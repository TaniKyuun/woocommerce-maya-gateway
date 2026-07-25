<?php

/**
 * Translates a verified webhook event into a WC order state change.
 *
 * @package RogueDex\MayaGateway\Webhook
 */

declare(strict_types=1);

namespace RogueDex\MayaGateway\Webhook;

use RogueDex\MayaGateway\Api\Endpoints\Payments;
use RogueDex\MayaGateway\Gateway\MayaGateway;
use RogueDex\MayaGateway\Util\IdempotencyKey;
use RogueDex\MayaGateway\Util\Logger;
use RogueDex\MayaGateway\Value\AuthorizationType;
use RogueDex\MayaGateway\Value\PaymentRecord;
use RogueDex\MayaGateway\Value\WebhookEvent;
use WC_Order;
use WP_Error;

/**
 * Verified-event → order-state machine.
 *
 * Called from {@see WebhookHandler::process()} once signature/timestamp/IP
 * checks have passed. Idempotent: the only state mutation for a paid order
 * is logging that we skipped it.
 *
 * Mappings (Phase 5):
 *
 *  - `PAYMENT_SUCCESS` on an immediate-capture order (auth type `none`):
 *    matching amount → `payment_complete($id)`; mismatch → log + order note
 *    and leave the order alone.
 *  - `PAYMENT_SUCCESS` on a *manual-capture* order: complete only when the
 *    payment's `capturedAmount` equals `amount` (full capture reached);
 *    otherwise add a partial-capture note and keep the order in
 *    `processing`. Each successive capture re-fires this branch until the
 *    last one promotes the order.
 *  - `AUTHORIZED` on a manual-capture order: add an "authorized, awaiting
 *    capture" note (no state change).
 *  - `PAYMENT_FAILED` / `PAYMENT_EXPIRED` / `PAYMENT_CANCELLED` /
 *    `CHECKOUT_FAILURE` / `CHECKOUT_DROPOUT` / `AUTH_FAILED` →
 *    `update_status('failed')`.
 */
class EventDispatcher
{
    /**
     * Currency-safe amount tolerance. Maya sends decimals (e.g. 199.5);
     * floating-point round-trips can leave a sub-cent difference. We accept
     * anything inside half a cent as "matching".
     */
    public const AMOUNT_TOLERANCE = 0.005;

    /**
     * Payments endpoint is optional: only the manual-capture branch needs it.
     * Immediate-capture orders (the most common case) never call it.
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ?Payments $payments = null,
    ) {}

    /**
     * @param array<string,mixed> $payload Verified webhook payload.
     *
     * @return array{action: string, order_id?: int, payment_id?: string, reference?: string, event?: string, expected?: float, received?: float}
     */
    public function dispatch(WebhookEvent $event, array $payload): array
    {
        $reference = $payload['requestReferenceNumber'] ?? '';
        $order     = self::find_order($reference);

        if (! $order instanceof WC_Order) {
            $this->logger->warning('EventDispatcher: order not found.', [
                'reference' => (string) $reference,
                'event'     => $event->value,
            ]);
            return [ 'action' => 'order_not_found', 'reference' => (string) $reference ];
        }

        // Monotonic order state: "paid is a floor." Once an order is paid we
        // never demote it, and we don't re-run payment_complete. This guard
        // runs BEFORE the terminal-failure branch so a late, replayed, or
        // out-of-order PAYMENT_FAILED / EXPIRED / CANCELLED cannot flip a
        // completed order back to `failed`. That is safe for Maya: a genuine
        // reversal of a settled payment arrives as a refund event on the
        // separate process_refund() path, never as a terminal-failure webhook
        // on the original checkout. It also makes concurrent/retried
        // PAYMENT_SUCCESS deliveries converge (payment_complete is idempotent
        // in WC once the order is paid).
        if ($order->is_paid()) {
            $this->logger->info('EventDispatcher: order already paid; skipping (paid is a floor).', [
                'order_id' => $order->get_id(),
                'event'    => $event->value,
            ]);
            return [ 'action' => 'already_paid', 'order_id' => (int) $order->get_id() ];
        }

        // Replay de-dup (defense-in-depth): if this exact event has already been
        // terminally processed for this order, don't re-run it. Non-terminal
        // transients are never recorded, so RetryQueue replays still proceed.
        $ledger_key = WebhookLedger::entry_key($event, $payload);
        if (WebhookLedger::has($order, $ledger_key)) {
            $this->logger->info('EventDispatcher: duplicate webhook; already terminally processed.', [
                'order_id' => $order->get_id(),
                'event'    => $event->value,
                'key'      => $ledger_key,
            ]);
            return [ 'action' => 'duplicate', 'order_id' => (int) $order->get_id(), 'event' => $event->value ];
        }

        if ($event->is_terminal_failure()) {
            return $this->mutate_terminal(
                $order,
                $event,
                $payload,
                $ledger_key,
                fn(WC_Order $locked_order): array => $this->mark_failed($locked_order, $event),
            );
        }

        if (WebhookEvent::PaymentSuccess === $event) {
            return $this->mutate_terminal(
                $order,
                $event,
                $payload,
                $ledger_key,
                function (WC_Order $locked_order) use ($payload): array {
                    $result = $this->prepare_payment_success($locked_order, $payload);
                    return in_array($result['action'], [ 'payment_complete', 'payment_complete_full_capture' ], true)
                        ? $this->finish_payment($locked_order, $result)
                        : $result;
                },
            );
        }

        if (
            WebhookEvent::Authorized === $event
            && AuthorizationType::from_setting($order->get_meta(MayaGateway::META_AUTHORIZATION_TYPE))->is_manual_capture()
        ) {
            return $this->note_authorized($order, $payload);
        }


        $this->logger->info('EventDispatcher: event ignored at this phase.', [
            'order_id' => $order->get_id(),
            'event'    => $event->value,
        ]);
        return [ 'action' => 'ignored', 'order_id' => (int) $order->get_id(), 'event' => $event->value ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param callable(WC_Order): array{action: string, order_id: int, payment_id?: string, event?: string} $mutate
     * @return array{action: string, order_id: int, payment_id?: string, event?: string}
     */
    private function mutate_terminal(WC_Order $order, WebhookEvent $event, array $payload, string $ledger_key, callable $mutate): array
    {
        if (! WebhookLedger::acquire_lock($order)) {
            return [ 'action' => 'mutation_in_progress', 'order_id' => (int) $order->get_id(), 'event' => $event->value ];
        }

        try {
            $locked_order = self::find_order($order->get_id());
            if (! $locked_order instanceof WC_Order) {
                return [ 'action' => 'mutation_in_progress', 'order_id' => (int) $order->get_id(), 'event' => $event->value ];
            }

            $duplicate = WebhookLedger::has($locked_order, $ledger_key);
            if ($locked_order->is_paid() || $duplicate) {
                return [
                    'action'   => $duplicate ? 'duplicate' : 'already_paid',
                    'order_id' => (int) $locked_order->get_id(),
                    'event'    => $event->value,
                ];
            }

            $result = $mutate($locked_order);
            if ('mutation_failed' === $result['action']) {
                return [ ...$result, 'event' => $event->value ];
            }

            WebhookLedger::record($locked_order, $event, $payload, $result['action']);
            if (in_array($result['action'], [ 'payment_complete', 'payment_complete_full_capture' ], true)) {
                do_action('wc_maya_payment_confirmed', (int) $locked_order->get_id(), $payload);
            }

            return $result;
        } finally {
            WebhookLedger::release_lock($order);
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{action: string, order_id: int, payment_id?: string}
     */
    private function prepare_payment_success(WC_Order $order, array $payload): array
    {
        $auth_type = AuthorizationType::from_setting($order->get_meta(MayaGateway::META_AUTHORIZATION_TYPE));
        return $auth_type->is_manual_capture()
            ? $this->complete_manual_capture($order, $payload)
            : $this->complete_payment($order, $payload);
    }

    /**
     * @param array{action: string, order_id: int, payment_id?: string} $result
     * @return array{action: string, order_id: int, payment_id?: string}
     */
    private function finish_payment(WC_Order $order, array $result): array
    {
        $payment_id = $result['payment_id'] ?? '';
        if (false === $order->payment_complete($payment_id)) {
            $this->logger->warning('EventDispatcher: payment_complete() failed.', [ 'order_id' => $order->get_id(), 'payment_id' => $payment_id ]);
            return [ 'action' => 'mutation_failed', 'order_id' => (int) $order->get_id(), 'payment_id' => $payment_id ];
        }

        $this->logger->info('EventDispatcher: payment_complete().', [ 'order_id' => $order->get_id(), 'payment_id' => $payment_id ]);
        return $result;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{action: string, order_id: int, payment_id?: string, expected?: float, received?: float}
     */
    private function complete_payment(WC_Order $order, array $payload): array
    {
        $expected = (float) $order->get_total();
        $received = (float) ($payload['amount'] ?? 0);
        $expected_currency = strtoupper((string) $order->get_currency());
        $received_currency = isset($payload['currency']) && is_string($payload['currency']) && '' !== $payload['currency']
            ? strtoupper($payload['currency'])
            : $expected_currency;

        if (abs($expected - $received) >= self::AMOUNT_TOLERANCE || $expected_currency !== $received_currency) {
            $this->logger->error('EventDispatcher: amount/currency mismatch — leaving order alone.', [
                'order_id' => $order->get_id(), 'expected' => $expected, 'received' => $received,
                'expected_currency' => $expected_currency, 'received_currency' => $received_currency,
            ]);
            $order->add_order_note(sprintf(
                __('Maya PAYMENT_SUCCESS webhook arrived with a mismatched amount/currency (expected %1$s %3$s, received %2$s %4$s). Order state left unchanged for manual review.', 'wc-maya-gateway'),
                $expected, $received, $expected_currency, $received_currency,
            ));
            return [
                'action' => 'amount_mismatch', 'order_id' => (int) $order->get_id(),
                'expected' => $expected, 'received' => $received,
                'expected_currency' => $expected_currency, 'received_currency' => $received_currency,
            ];
        }

        $payment_id = isset($payload['id']) && is_string($payload['id']) ? $payload['id'] : '';
        return [ 'action' => 'payment_complete', 'order_id' => (int) $order->get_id(), 'payment_id' => $payment_id ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{action: string, order_id: int, payment_id?: string, authorized?: float, captured?: float}
     */
    private function complete_manual_capture(WC_Order $order, array $payload): array
    {
        $payment_id = isset($payload['id']) && is_string($payload['id']) ? $payload['id'] : '';
        if (null === $this->payments) {
            $this->logger->error('EventDispatcher: manual-capture branch reached without a Payments endpoint.', [ 'order_id' => $order->get_id(), 'payment_id' => $payment_id ]);
            return [ 'action' => 'manual_capture_lookup_unavailable', 'order_id' => (int) $order->get_id(), 'payment_id' => $payment_id ];
        }

        $records = $this->payments->get_by_rrn(IdempotencyKey::for_order((int) $order->get_id()));
        if ($records instanceof WP_Error) {
            $this->logger->error('EventDispatcher: payment lookup failed during manual-capture check.', [ 'order_id' => $order->get_id(), 'payment_id' => $payment_id, 'code' => $records->get_error_code(), 'message' => $records->get_error_message() ]);
            return [ 'action' => 'manual_capture_lookup_failed', 'order_id' => (int) $order->get_id(), 'payment_id' => $payment_id ];
        }

        $authorization = self::find_authorization_record($records);
        if (! $authorization instanceof PaymentRecord) {
            $this->logger->warning('EventDispatcher: no AUTHORIZED record on a manual-capture order.', [ 'order_id' => $order->get_id(), 'payment_id' => $payment_id ]);
            return [ 'action' => 'manual_capture_no_authorization', 'order_id' => (int) $order->get_id(), 'payment_id' => $payment_id ];
        }

        $authorized = $authorization->amount->value;
        $captured = null !== $authorization->captured_amount ? $authorization->captured_amount->value : 0.0;
        if (abs($authorized - $captured) >= self::AMOUNT_TOLERANCE) {
            $order->add_order_note(sprintf(
                __('Maya partial capture confirmed: %1$s of %2$s captured. Order will complete when the remaining balance is captured.', 'wc-maya-gateway'),
                $captured, $authorized,
            ));
            return [ 'action' => 'partial_capture_note', 'order_id' => (int) $order->get_id(), 'payment_id' => $payment_id, 'authorized' => $authorized, 'captured' => $captured ];
        }

        return [ 'action' => 'payment_complete_full_capture', 'order_id' => (int) $order->get_id(), 'payment_id' => $payment_id, 'authorized' => $authorized, 'captured' => $captured ];
    }

    /**
     * @param list<PaymentRecord> $records
     */
    private static function find_authorization_record(array $records): ?PaymentRecord
    {
        foreach ($records as $record) {
            if ($record->is_authorization()) {
                return $record;
            }
        }
        return null;
    }

    /**
     * AUTHORIZED webhook on a manual-capture order: record the note so the
     * merchant sees the auth landed.
     *
     * @param array<string,mixed> $payload
     *
     * @return array{action: string, order_id: int, payment_id?: string}
     */
    private function note_authorized(WC_Order $order, array $payload): array
    {
        $payment_id = isset($payload['id']) && is_string($payload['id']) ? $payload['id'] : '';

        $order->add_order_note(
            __('Maya authorized the payment. Use the Capture panel on this order to capture funds.', 'wc-maya-gateway'),
        );

        $this->logger->info('EventDispatcher: authorized note added.', [
            'order_id'   => $order->get_id(),
            'payment_id' => $payment_id,
        ]);

        return [
            'action'     => 'authorized_note',
            'order_id'   => (int) $order->get_id(),
            'payment_id' => $payment_id,
        ];
    }

    /**
     * @return array{action: string, order_id: int, event: string}
     */
    private function mark_failed(WC_Order $order, WebhookEvent $event): array
    {
        $note = match ($event) {
            WebhookEvent::PaymentExpired   => __('Maya payment expired.', 'wc-maya-gateway'),
            WebhookEvent::PaymentCancelled => __('Maya payment cancelled.', 'wc-maya-gateway'),
            WebhookEvent::AuthFailed       => __('Maya authorization failed.', 'wc-maya-gateway'),
            default                        => __('Maya payment failed.', 'wc-maya-gateway'),
        };

        // All failure-family events map to WooCommerce `failed` — a retryable
        // status (unlike `cancelled`, which restores stock and blocks re-payment).
        // The customer can pay again from the order-pay page; genuine
        // abandonment is caught by PAYMENT_EXPIRED.
        if (false === $order->update_status('failed', $note)) {
            $this->logger->warning('EventDispatcher: update_status() failed.', [
                'order_id' => $order->get_id(),
                'event'    => $event->value,
            ]);
            return [
                'action'   => 'mutation_failed',
                'order_id' => (int) $order->get_id(),
                'event'    => $event->value,
            ];
        }

        $this->logger->info('EventDispatcher: order failed.', [
            'order_id' => $order->get_id(),
            'event'    => $event->value,
        ]);

        return [
            'action'   => 'failed',
            'order_id' => (int) $order->get_id(),
            'event'    => $event->value,
        ];
    }

    private static function find_order(mixed $reference): ?WC_Order
    {
        if (! function_exists('wc_get_order')) {
            return null;
        }

        $id = is_numeric($reference) ? (int) $reference : 0;
        if ($id <= 0) {
            return null;
        }

        $order = wc_get_order($id);
        return $order instanceof WC_Order ? $order : null;
    }
}
