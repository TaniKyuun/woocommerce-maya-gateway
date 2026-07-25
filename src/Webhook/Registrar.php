<?php

/**
 * Reconciles Maya's registered webhooks with the set this plugin manages.
 *
 * @package RogueDex\MayaGateway\Webhook
 */

declare(strict_types=1);

namespace RogueDex\MayaGateway\Webhook;

use RogueDex\MayaGateway\Api\Endpoints\Webhooks;
use RogueDex\MayaGateway\Util\Logger;
use RogueDex\MayaGateway\Value\WebhookEvent;
use RogueDex\MayaGateway\Value\WebhookRecord;
use WP_Error;

/**
 * Idempotent webhook reconciler.
 *
 * On every call, walks Maya's registered webhooks and brings the merchant's
 * account into a known-good state:
 *
 *  1. List everything Maya has for this account.
 *  2. Delete every webhook *whose name is in our managed set*. Webhooks the
 *     merchant registered for other purposes are left alone.
 *  3. Create a fresh entry for each name in {@see MANAGED_EVENTS} pointing
 *     at the supplied callback URL.
 *
 * The "delete-then-create" pattern is deliberate: it's easier to reason
 * about than diff-and-update, and Maya doesn't expose a PUT that lets us
 * change a callback URL in place. Failure of any individual step is captured
 * in the returned summary so the caller can surface partial-success cleanly.
 */
class Registrar
{
    /**
     * Events this plugin owns. Anything outside this set is left untouched
     * during reconciliation so the merchant's other integrations survive.
     *
     * @var list<WebhookEvent>
     */
    public const MANAGED_EVENTS = [
        WebhookEvent::PaymentSuccess,
        WebhookEvent::PaymentFailed,
        WebhookEvent::PaymentExpired,
        WebhookEvent::PaymentCancelled,
    ];

    /**
     * Deprecated Maya event names this plugin used to register. Reconcile
     * DELETES any of these it finds on the merchant's account (but never
     * re-creates them), so migrating off the deprecated CHECKOUT_* family
     * actually cleans up existing subscriptions instead of orphaning them.
     *
     * @var list<string>
     */
    public const DEPRECATED_EVENT_NAMES = [
        'CHECKOUT_SUCCESS',
        'CHECKOUT_FAILURE',
        'CHECKOUT_DROPOUT',
        'CHECKOUT_CANCELLED',
    ];

    public function __construct(
        private readonly Webhooks $endpoint,
        private readonly Logger $logger,
    ) {}

    /**
     * Bring the merchant's Maya account in line with our managed set.
     *
     * @return array{
     *     deleted:  list<string>,
     *     created:  list<WebhookRecord>,
     *     skipped:  list<string>,
     *     errors:   list<string>,
     * }|WP_Error
     */
    public function reconcile(string $callback_url): array|WP_Error
    {
        if ('' === trim($callback_url)) {
            return new WP_Error(
                'wc_maya_registrar_empty_url',
                __('Cannot register webhooks against an empty callback URL.', 'wc-maya-gateway'),
            );
        }

        $existing = $this->endpoint->all();
        if ($existing instanceof WP_Error) {
            $this->logger->error('Webhook reconcile: list failed.', [
                'code'    => $existing->get_error_code(),
                'message' => $existing->get_error_message(),
            ]);
            return $existing;
        }

        $managed_names = self::managed_names();
        $deleted = [];
        $skipped = [];
        $errors  = [];

        foreach ($existing as $record) {
            $is_managed = in_array($record->name, $managed_names, true);
            $is_owned_deprecated = in_array($record->name, self::DEPRECATED_EVENT_NAMES, true)
                && $record->callback_url === $callback_url;

            if (! $is_managed && ! $is_owned_deprecated) {
                $skipped[] = $record->name;
                continue;
            }

            $result = $this->endpoint->delete($record->id);
            if ($result instanceof WP_Error) {
                $errors[] = sprintf('Delete %s (%s): %s', $record->name, $record->id, $result->get_error_message());
                $this->logger->warning('Webhook reconcile: delete failed.', [
                    'id'      => $record->id,
                    'name'    => $record->name,
                    'message' => $result->get_error_message(),
                ]);
                continue;
            }
            $deleted[] = $record->name;
        }

        $created = [];
        foreach ($managed_names as $event_name) {
            $result = $this->endpoint->create($event_name, $callback_url);
            if ($result instanceof WP_Error) {
                $errors[] = sprintf('Create %s: %s', $event_name, $result->get_error_message());
                $this->logger->warning('Webhook reconcile: create failed.', [
                    'name'    => $event_name,
                    'message' => $result->get_error_message(),
                ]);
                continue;
            }
            $created[] = $result;
        }

        $this->logger->info('Webhook reconcile done.', [
            'callback_url' => $callback_url,
            'deleted'      => $deleted,
            'created'      => array_map(static fn(WebhookRecord $r): string => $r->name, $created),
            'skipped'      => $skipped,
            'errors'       => $errors,
        ]);

        return [
            'deleted' => $deleted,
            'created' => $created,
            'skipped' => $skipped,
            'errors'  => $errors,
        ];
    }

    /**
     * @return list<string>
     */
    public static function managed_names(): array
    {
        return array_map(static fn(WebhookEvent $event): string => $event->value, self::MANAGED_EVENTS);
    }

    public static function is_managed(string $event_name): bool
    {
        return in_array($event_name, self::managed_names(), true);
    }
}
