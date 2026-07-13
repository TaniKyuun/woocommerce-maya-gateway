#!/usr/bin/env bash
#
# Build a production-installable zip of the plugin.
#
# Produces `dist/wc-maya-gateway-<version>.zip` containing only the runtime
# files: src/, assets/, templates/, languages/, the main plugin file, README,
# LICENSE, CHANGELOG.
#
# There is no vendor/ in the zip and no Composer step in this build. The plugin
# has no runtime dependencies, so the only thing a vendor/ would have carried is
# Composer's own class-loader — ~50KB of machinery to map one PSR-4 prefix onto
# src/. The main plugin file does that itself in ten lines when nothing else
# already has. (Under Composer, Bedrock's project-root autoloader does it.)
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

# The zip ships no vendor/, because the plugin has no runtime dependencies:
# every entry in composer.json's `require` other than `php` would be a package
# the main file's built-in PSR-4 autoloader cannot load. Adding one silently
# turns the zip into a plugin that fatals on a missing class, so stop here
# instead — whoever added it needs to decide how it gets shipped.
REQUIRES="$(git -C "${ROOT_DIR}" show "${REF}:composer.json" \
    | php -r 'echo implode(" ", array_diff(array_keys(json_decode(file_get_contents("php://stdin"), true)["require"] ?? []), ["php"]));' 2>/dev/null || true)"
if [ -n "${REQUIRES}" ]; then
    echo "Refusing to build: composer.json has runtime dependencies (${REQUIRES})." >&2
    echo "This zip ships no vendor/ — the plugin autoloads only its own src/." >&2
    echo "Bundle a vendor/ here, or drop the dependency." >&2
    exit 1
fi

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
( cd "${DIST_DIR}" && zip -qr "${ZIP_NAME}" "${PLUGIN_DIR_NAME}" )

echo "Built ${DIST_DIR}/${ZIP_NAME}"

du -h "${DIST_DIR}/${ZIP_NAME}" | awk '{ print "Size:  " $1 }'
