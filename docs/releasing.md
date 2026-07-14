# Releasing

## The short version

**A release is a git tag. That's it.**

```bash
git tag -a v1.2.0 -m "v1.2.0"
git push origin v1.2.0
```

There is no artifact to upload, no CI to wait on, and no release asset to fetch. Composer
resolves the tag straight from GitHub. Everything below is detail.

---

## How the plugin reaches a site

There are two install paths, and they work differently. Know which one you're dealing with.

### Path 1 — Composer (Bedrock, and how the production site runs)

The site's root `composer.json` names this repo and a version constraint:

```jsonc
"repositories": [
  { "name": "wp-packages", "type": "composer", "url": "https://repo.wp-packages.org" },
  { "type": "vcs", "url": "https://github.com/roguedex-labs/woocommerce-maya-gateway" }
],
"require": {
  "roguedex-labs/woocommerce-maya-gateway": "^1.1"
}
```

Composer reads the tags off GitHub, picks the newest one matching `^1.1`, downloads that tag's
zipball, and — because the package declares `"type": "wordpress-plugin"` and Bedrock has
`composer/installers` with `extra.installer-paths` — drops it into
`web/app/plugins/woocommerce-maya-gateway/`.

Upgrading is:

```bash
ddev composer update roguedex-labs/woocommerce-maya-gateway
```

**No `composer install` ever runs inside the plugin directory.** The plugin has no runtime
dependencies, so there is nothing to install; Composer merges the plugin's PSR-4 map into the
*project-root* autoloader, which Bedrock's `web/wp-config.php` loads before WordPress boots.
The classes are simply already there.

This is exactly how WooCommerce and every other plugin on the site arrives.

### Path 2 — the zip (only for sites that don't use Composer)

For a plain WordPress install where someone uploads through **Plugins → Add New → Upload**:

```bash
bin/build-release.sh v1.2.0     # -> dist/wc-maya-gateway-1.2.0.zip
```

Attach it to the GitHub Release by hand. **Nothing does this automatically** — there is no CI in
this repo, deliberately: Composer never looks at a Release asset, so automating it would serve no
one. If you don't need a zip, don't build one. The tag alone is a complete release.

The zip contains no `vendor/` either. See [Why there is no vendor/](#why-there-is-no-vendor).

---

## Cutting a release, step by step

### 1. Bump the version — all four places

The version is repeated in four files and they must agree. `bin/build-release.sh` reads the plugin
header and names the zip after it; a mismatch ships a zip that lies about its contents.

| File | What to change |
|---|---|
| `wc-maya-payment-gateway.php` | the `* Version:` header **and** `define('WC_MAYA_VERSION', …)` |
| `readme.txt` | `Stable tag:` and a new `= 1.2.0 =` changelog block |
| `CHANGELOG.md` | a new `## [1.2.0] — YYYY-MM-DD` section |

`bin/make-pot.php` reads the version out of the plugin header, so it needs no edit — but it must be
re-run (next step).

### 2. Regenerate translations

```bash
php bin/make-pot.php
```

Do this after the version bump — the POT carries the version in its header. Re-run it whenever a
translatable string changes, too.

### 3. Verify

```bash
composer test          # 244 tests
composer lint          # phpcs
composer format:check  # php-cs-fixer
```

### 4. Merge to `main`, then tag

Tag `main` **after** the PR merges, not the feature branch. Composer resolves tags regardless of
which branch they sit on, so tagging a branch that never merges "works" — and leaves `main`
permanently behind what's released. Don't.

```bash
git checkout main && git pull
git tag -a v1.2.0 -m "v1.2.0"
git push origin v1.2.0
```

`release/1.x` is the long-lived release line. Patches for the 1.x series cherry-pick onto it and
tag from there.

### 5. Roll it out to the site

**The plugin release and the site deploy are two separate events.** Tagging changes nothing on the
storefront until Bedrock's lockfile moves:

```bash
# in the Bedrock repo
ddev composer update roguedex-labs/woocommerce-maya-gateway
git commit -m "chore(deps): update maya gateway to 1.2.0" composer.json composer.lock
```

Deploy *that* commit. Production runs `composer install` from the committed `composer.lock`, which
pins the exact commit hash — so every environment gets byte-identical code.

Keep it as its own commit. It's the rollback lever (see below).

---

## Rollback

**Recovery is roll-forward.** Fix the bug, tag `v1.2.1`, `composer update`.

Do **not** move or delete a published tag. Composer caches a package's dist by commit reference, so
re-pointing a tag serves old bytes to some consumers and new bytes to others for the *same* version
number — the classic poisoned-release scenario. A deleted tag doesn't help either: already-locked
consumers keep working from cache, fresh ones break.

The one real safety net is Bedrock's `composer.lock`, which pins the exact commit. Reverting the
"chore(deps): update…" commit and running `composer install` puts the site back on the previous
code. That's why it stays a standalone commit.

> **Note for 1.1.0 specifically:** there is no rollback target. The repo had no tags before
> `v1.1.0` — 1.0.0 was never published — and 1.0.0 wouldn't be usable anyway, since it contains the
> fatal that 1.1.0 exists to fix (see below). Roll-forward is the only option.

---

## Why there is no `vendor/`

`composer.json`'s `require` is `php` and nothing else — every real package is `require-dev`. So
`composer install --no-dev` installs **zero packages**, and a bundled `vendor/` would contain
nothing but Composer's own class-loader (~50 KB) doing one job: mapping `RogueDex\MayaGateway\*`
onto `src/`.

`wc-maya-payment-gateway.php` does that itself, in ten lines, and only when nothing else already
has:

- **Under Composer** the check short-circuits — the project-root autoloader already provided the
  classes.
- **From the zip / a plain checkout** it registers its own PSR-4 autoloader.
- **Neither** — a graceful admin notice, not a fatal.

This is also why the build refuses to run if `require` ever grows beyond `php`:

```
Refusing to build: composer.json has runtime dependencies (guzzlehttp/guzzle).
This zip ships no vendor/ — the plugin autoloads only its own src/.
Bundle a vendor/ here, or drop the dependency.
```

Adding a runtime dependency is a real decision, not a casual one. Beyond breaking the zip, a
bundled `vendor/` inside a WordPress plugin is a known conflict hazard: two plugins shipping
different versions of the same library collide on the class name, and whichever loads first wins.
If you genuinely need one, you must decide how it ships — and probably scope/prefix it.

### The bug this replaced

Before 1.1.0 the main file did:

```php
require_once __DIR__ . '/vendor/autoload.php';   // unconditional
```

Under a Composer install there is no `vendor/` in the plugin directory, so this **fatal-errored the
entire site** — a white screen, not a broken checkout. It's why the plugin could not be installed
the way every other plugin on the site is installed, and why 1.1.0 is more than a rename.

---

## What ships, and what doesn't

Defined in **one** place: the `export-ignore` rules in `.gitattributes`.

GitHub honours those rules when generating the tag zipball Composer downloads, and
`bin/build-release.sh` stages the zip with `git archive`, which honours them too. So the Composer
install and the standalone zip contain the same files **by construction** — there is no second
exclude list to keep in sync.

Excluded: `tests/`, `docs/`, `bin/`, `.github/`, `.claude/`, `phpcs.xml`, `phpunit.xml`,
`.php-cs-fixer.php`, `composer.lock`.

> **Gotcha:** `git archive` reads `export-ignore` from *the tree being archived*. Building a ref
> older than those rules stages the whole repo. `bin/build-release.sh` asserts the staged tree is
> clean and hard-fails rather than quietly shipping a zip full of tests — but if you're cutting a
> hotfix from an old release line, cherry-pick the `.gitattributes` rules first.

---

## Version constraints, for reference

| Constraint in Bedrock | Picks up |
|---|---|
| `^1.1` | any `1.x` ≥ 1.1.0 — new features and fixes, no breaking changes |
| `~1.1.0` | `1.1.x` patches only |
| `1.1.0` | that exact tag, forever |

`^1.1` is what we use. Composer strips the leading `v`, so the tag `v1.1.0` satisfies it.
