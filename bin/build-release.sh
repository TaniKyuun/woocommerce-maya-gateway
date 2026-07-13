#!/usr/bin/env bash
#
# Build a production-installable zip of the plugin.
#
# Produces `dist/wc-maya-gateway-<version>.zip` containing only the runtime
# files: src/, vendor/ (composer install --no-dev), assets/, templates/,
# languages/, the main plugin file, README, LICENSE, CHANGELOG.
#
# What counts as a dev file is defined once, by the `export-ignore` rules in
# .gitattributes — the same rules that decide what a Composer install gets.
#
# Usage:
#
#     bin/build-release.sh            # build HEAD
#     bin/build-release.sh v1.1.0     # build a tag (what you want when releasing)
#
# Run it by hand when cutting a release, and attach dist/*.zip to the GitHub
# Release. This zip is only for manual installs on sites that do not use
# Composer — a Composer install resolves the plugin straight from the git tag.

set -euo pipefail

PLUGIN_SLUG="wc-maya-gateway"
PLUGIN_DIR_NAME="woocommerce-maya-gateway"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
STAGING_DIR="${DIST_DIR}/${PLUGIN_DIR_NAME}"
REF="${1:-HEAD}"

if ! git -C "${ROOT_DIR}" rev-parse --verify --quiet "${REF}^{commit}" > /dev/null; then
    echo "Not a commit: ${REF}" >&2
    exit 1
fi

# Read the Version: header from the ref being built, not the working tree — the
# zip must describe the code it actually contains.
VERSION="$(git -C "${ROOT_DIR}" show "${REF}:wc-maya-payment-gateway.php" \
    | grep -E '^[ ]*\*[ ]*Version:' | head -n 1 | awk -F: '{ gsub(/^[ ]+|[ ]+$/, "", $2); print $2 }')"
if [ -z "${VERSION}" ]; then
    echo "Could not read the plugin version from ${REF}:wc-maya-payment-gateway.php." >&2
    exit 1
fi

# Uncommitted work is not in `git archive` output, so say so rather than let
# someone ship a zip that quietly omits the change they just made.
git -C "${ROOT_DIR}" update-index -q --refresh || true
if ! git -C "${ROOT_DIR}" diff-index --quiet HEAD --; then
    echo "Warning: working tree has uncommitted changes; they are NOT in this build." >&2
fi

echo "Building ${PLUGIN_SLUG} v${VERSION} from ${REF}…"

rm -rf "${DIST_DIR}"
mkdir -p "${STAGING_DIR}"

# Stage from `git archive`, not from the working tree.
#
# This ships tracked files only, and honours the `export-ignore` rules in
# .gitattributes — the same rules GitHub applies when Composer downloads a
# tagged zipball. So the zip and the Composer install contain the same files by
# construction, instead of via a hand-maintained exclude list that has to be
# kept in step with .gitattributes and that silently shipped whatever untracked
# junk (editor dirs, tool caches, .env files) happened to be lying around.
git -C "${ROOT_DIR}" archive --format=tar "${REF}" | tar -x -C "${STAGING_DIR}"

# git archive reads export-ignore from the tree it is archiving, so a ref that
# predates those rules (an older release line, a hotfix branch cut before them)
# stages the whole repo and would ship tests/, docs/ and bin/ to merchants.
# Fail loudly rather than quietly building a bad zip.
for unwanted in tests docs bin phpcs.xml phpunit.xml .php-cs-fixer.php; do
    if [ -e "${STAGING_DIR}/${unwanted}" ]; then
        echo "Refusing to build: '${unwanted}' was staged from ${REF}." >&2
        echo "That ref has no export-ignore rules for it — cherry-pick them before releasing from it." >&2
        exit 1
    fi
done

# Build vendor/ without dev deps. Install from the lock so a given ref always
# produces the same bytes rather than re-resolving against the network.
# --no-interaction turns a blocked-plugin prompt into an honest failure instead
# of a build that appears to hang.
git -C "${ROOT_DIR}" show "${REF}:composer.lock" > "${STAGING_DIR}/composer.lock"
(
    cd "${STAGING_DIR}"
    # A lock that has drifted from composer.json means the "same ref, same
    # bytes" guarantee above is a fiction. composer install only warns.
    composer validate --check-lock --no-check-all --no-check-publish --quiet
    composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --quiet
)
rm -f "${STAGING_DIR}/composer.lock"

# Strip ./vendor caches that composer leaves but the plugin doesn't need.
# `set -e` aborts on any failure here. The hygiene strip below is best-effort
# (a stray locked file shouldn't fail the build), so we capture the exit code
# and warn loudly instead of silencing with `|| true` — silent suppression
# masked the case where a permission error left dev files in the release zip.
find "${STAGING_DIR}/vendor" -type d \( -name 'tests' -o -name 'test' -o -name 'docs' -o -name 'doc' \) -prune -exec rm -rf {} +

set +e
find "${STAGING_DIR}/vendor" -type f \( -iname 'CHANGELOG*' -o -iname '*.md' -o -name 'phpunit.xml*' -o -name 'phpstan.neon*' -o -name '.php-cs-fixer*' \) -delete
FIND_EXIT=$?
set -e
if [ "${FIND_EXIT}" -ne 0 ]; then
    echo "Warning: vendor file-strip exited ${FIND_EXIT}; release zip may include dev files." >&2
fi

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
( cd "${DIST_DIR}" && zip -qr "${ZIP_NAME}" "${PLUGIN_DIR_NAME}" )

echo "Built ${DIST_DIR}/${ZIP_NAME}"

du -h "${DIST_DIR}/${ZIP_NAME}" | awk '{ print "Size:  " $1 }'
