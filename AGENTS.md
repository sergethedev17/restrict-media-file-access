# Restrict Media File Access — Agent Instructions

This file provides AI coding assistants with the context they need to work effectively on the **Restrict Media File Access** WordPress plugin.

## Plugin Overview

**Restrict Media File Access** (`restrict-media-file-access`) is a WordPress plugin that protects media files from unauthorized access. It provides:

- Per-file protection via a checkbox in the Media Library.
- Secure file storage by moving protected files to a hidden `.protected` directory.
- Hash-based URL rewriting so protected file paths are never exposed.
- Per-file access lists (users and roles) plus a choice of denied response (`404`, `403`, `pixel`), with the `restrict_media_file_access_protect_file` filter as the final override. See Access Control.
- Media listings (REST, the media modal, the classic library) hide restricted files the current user may not read.
- Conditional requests: allowed files revalidate with `ETag` / `Last-Modified` and answer `304 Not Modified`; denied responses are uncacheable.
- Direct access to the protected directory is denied by its own `.htaccess` guard.
- HTTP Range request support for media streaming.
- Automatic URL replacement in post content when files are protected/unprotected.
- Bidirectional attachment tracking (which posts use which attachments).
- A REST API under the `restrict-media-file-access/v1` namespace.
- Jetpack Photon compatibility.
- GitHub-hosted self-update mechanism.
- Admin UI: restriction checkbox on attachments, "File access" meta box (users, roles, denied response), "Restricted" column in Media Library, activity meta box.

**Text domain:** `restrict-media-file-access`
**Namespace:** `A8C\SpecialProjects\RestrictMediaFileAccess\`
**Requires:** WordPress 6.4+, PHP 8.3+
**Version:** 1.6.0

---

## Directory Structure

```
restrict-media-file-access/
├── restrict-media-file-access.php  ← Bootstrap (constants, autoloader, activation hooks)
├── functions-bootstrap.php         ← Plugin metadata, version checks, error display
├── functions.php                   ← Plugin instance getter, includes loader, logger
├── AGENTS.md                       ← You are here
├── CLAUDE.md                       ← Points to this file
├── .agents/                        ← Project-specific agent skills
│   └── skills/
│       ├── rmfa-architecture/SKILL.md
│       ├── rmfa-file-protection/SKILL.md
│       └── rmfa-testing/SKILL.md
│
├── src/                            ← PSR-4 autoloaded PHP (A8C\SpecialProjects\RestrictMediaFileAccess\)
│   ├── Plugin.php                  ← Singleton main class; registers all services
│   ├── ApplicationPasswords.php    ← Lets application passwords authenticate protected file requests
│   ├── Attachments.php             ← URL modification, metadata, srcset, image attributes
│   ├── AttachmentsAccess.php       ← Per-file access rules: user/role allow-lists, deny mode, listing filter
│   ├── AttachmentsAccessAdmin.php  ← "File access" meta box, its save handler and the AJAX user search
│   ├── AttachmentsAdmin.php        ← Admin UI: restriction checkbox, media column, meta box
│   ├── AttachmentsAutoRestrict.php ← Optional auto-restriction of new uploads
│   ├── AttachmentsFileManager.php  ← Core: protect/unprotect files, move, URL replacement
│   ├── AttachmentsProtector.php    ← File serving: access check, headers, range requests
│   ├── AttachmentsRemoval.php      ← Cleanup on attachment deletion
│   ├── AttachmentsTracking.php     ← Bidirectional post↔attachment tracking
│   ├── AttachmentsUpload.php       ← Unique filename check against protected directory
│   ├── BulkOperations.php          ← Initial setup: batch index all posts
│   ├── CacheCleanup.php            ← Cache invalidation on attachment/post deletion
│   ├── Cron.php                    ← Initial setup cron job
│   ├── Filesystem.php              ← Static WP_Filesystem wrapper
│   ├── JetpackCompatibility.php    ← Skip Photon for protected images
│   ├── RestApi.php                 ← REST API endpoints (restrict, status, access)
│   ├── RewriteRules.php            ← WordPress rewrite rules for protected-files/{hash}
│   ├── SelfUpdate.php              ← GitHub-hosted auto-updates
│   └── Settings.php                ← Media Settings page section
│
├── includes/                       ← Procedural PHP files loaded by functions.php
│   ├── constants.php               ← RESTRICT_MEDIA_FILE_ACCESS_PROTECTED_DIR, _PROTECTED_PATH
│   ├── functions.php               ← Global helper functions (rmfa_*)
│   ├── assets.php                  ← Asset meta helper
│   └── settings.php                ← (empty placeholder)
│
├── assets/                         ← Admin CSS and JS
│   └── admin/
│       ├── css/
│       │   ├── file-access.css             ← "File access" meta box: chips and search
│       │   ├── media.css                   ← General admin media styles
│       │   └── media-restricted-files.css  ← Restricted file border styles
│       └── js/
│           ├── file-access.js              ← "File access" meta box: user chips, AJAX search
│           └── media.js                    ← Media library JS enhancements
│
├── models/                         ← Model classes (classmap autoloaded, currently empty)
├── languages/                      ← i18n files
│
├── tests/
│   ├── Integration/                ← Codeception integration tests
│   ├── EndToEnd/                   ← Codeception E2E tests
│   ├── Support/                    ← Test support classes and data
│   ├── smoke/                      ← WP-CLI smoke suite (`wp eval-file tests/smoke/file-rbac.php`)
│   ├── Integration.suite.yml       ← Integration suite config
│   ├── EndToEnd.suite.yml          ← E2E suite config
│   └── README.md                   ← Local test setup instructions
│
├── composer.json                   ← PHP deps + scripts (lint, test, i18n)
├── package.json                    ← JS deps + scripts (wp-env, tests)
├── codeception.dist.yml            ← Codeception configuration
├── .phpcs.xml                      ← PHPCS configuration
├── .phpmd.xml                      ← PHPMD configuration
├── .phpstan.neon                   ← PHPStan configuration
└── .wp-env.json                    ← wp-env configuration
```

---

## Architecture

### Bootstrap

`restrict-media-file-access.php` defines constants, loads `functions-bootstrap.php`, loads translations, declares WC compatibility, validates requirements, and hooks `plugins_loaded` → `Plugin::get_instance()->maybe_initialize()`. `ApplicationPasswords` is initialized at load time (not through the service registry) because the current user is resolved before `plugins_loaded` and the filter must already be registered. Activation creates the `.protected` directory and its `.htaccess` guard, schedules initial tracking setup, and sets a transient to flush rewrite rules. See `.agents/skills/rmfa-architecture/SKILL.md` for the full bootstrap sequence.

### Service Pattern

`Plugin` is a singleton. `Plugin::initialize()` instantiates all services and calls `initialize()` on each. `ApplicationPasswords` is the exception: it is initialized directly from the bootstrap file. See `.agents/skills/rmfa-architecture/SKILL.md` for the service pattern, code examples, and how to add new services.

Registered services (in order):

| Key | Class |
|-----|-------|
| `self-update` | `SelfUpdate` |
| `cron` | `Cron` |
| `settings` | `Settings` |
| `attachments-protector` | `AttachmentsProtector` |
| `attachments-access` | `AttachmentsAccess` |
| `attachments-access-admin` | `AttachmentsAccessAdmin` |
| `jetpack-compatibility` | `JetpackCompatibility` |
| `attachments` | `Attachments` |
| `attachments-admin` | `AttachmentsAdmin` |
| `attachments-upload` | `AttachmentsUpload` |
| `attachments-removal` | `AttachmentsRemoval` |
| `rewrite-rules` | `RewriteRules` |
| `cache-cleanup` | `CacheCleanup` |
| `attachments-tracking` | `AttachmentsTracking` |
| `rest-api` | `RestApi` |
| `auto-restrict` | `AttachmentsAutoRestrict` |

### REST API Routes

All routes registered under `restrict-media-file-access/v1`:

- `POST /media/{file_id}/restrict` — Restrict or unrestrict a file (`upload_files` + `edit_post`). Registered with `WP_REST_Server::EDITABLE`, so `PUT` and `PATCH` work too.
- `GET /media/{file_id}/status` — Get file restriction status and metadata (`upload_files` + `edit_post`). The `access` key (allow-lists and deny modes) is included only for users with `rmfa_manage_file_access_capability()`.
- `POST /media/{file_id}/access` — Replace a file's `users`, `roles` and `deny_mode` (`rmfa_manage_file_access_capability()`). All three parameters are required; a partial request is a `400` and changes nothing. Also accepts `PUT`/`PATCH`.

Individual attachment records (`rest_prepare_attachment`) answer `403` for a restricted file the reader may not access; the listing is filtered separately by `AttachmentsAccess`.

### File Protection

Files are physically moved to `.protected/` and served via hash-based rewrite rules. `AttachmentsProtector` serves the request on `parse_request` (skipping the main query and template stack), falls back to `template_redirect` priority 5, and serves a restricted attachment's page on `template_redirect` priority 0 so `redirect_canonical()` cannot expose the hash. `AttachmentsFileManager` handles the protect/unprotect lifecycle. See `.agents/skills/rmfa-file-protection/SKILL.md` for the full pipeline.

### Access Control

Per-file allow-lists of user IDs (`_rmfa_access_users`) and role slugs
(`_rmfa_access_roles`) are evaluated by `AttachmentsAccess::user_can_access()`
(rules live in the private `is_allowed_by_rules()`):
unrestricted files keep the logged-in gate, then admin (`manage_options`), owner
(`post_author`), listed user, listed role — otherwise denied, **including when
both lists are empty**. Denied requests answer with the per-file
`_rmfa_deny_mode` (`pixel`, `404`, `403`), falling back to the
`rmfa_default_deny_mode` option (default `404`); `pixel` degrades to `404` for
non-images unless a placeholder filter is registered. Unknown hashes always
answer 404. The `restrict_media_file_access_protect_file` filter still overrides
the default. See `.agents/skills/rmfa-file-protection/SKILL.md` for details.

| Meta / option | On | Meaning |
|---|---|---|
| `_rmfa_access_users` | attachment | Allowed user IDs. |
| `_rmfa_access_roles` | attachment | Allowed role slugs. |
| `_rmfa_deny_mode` | attachment | `pixel`, `404`, `403`, or empty for the site default. |
| `rmfa_default_deny_mode` | option | Site-wide denied response (default `404`). |
| `rmfa_access_cache_version` | option | Bumped on every rule change to invalidate the denied-ID cache. |

Denied responses are uncacheable (`private, no-store, max-age=0`,
`Pragma: no-cache`, `Vary: Authorization`). Allowed responses are
`private, no-cache` and carry `ETag` / `Last-Modified`, so an unchanged file
answers `304 Not Modified`; the access check runs before the conditional branch.

### Attachment Tracking

`AttachmentsTracking` maintains a bidirectional index of which posts contain which attachments, using `_rmfa_used_in_posts` (on attachments) and `_rmfa_attachments` (on posts). `BulkOperations` builds this index on first activation. See `.agents/skills/rmfa-file-protection/SKILL.md` for details.

---

## Coding Standards

### PHP

- **WordPress PHP Coding Standards** — enforced via PHPCS (`.phpcs.xml`) extending `a8cteam51/team51-configs`.
- MUST indent with **tabs**.
- MUST use `array()` long syntax (not `[]`).
- MUST use Yoda conditions: `if ( 'value' === $var )`.
- MUST use strict comparisons (`===`, `!==`).
- MUST declare visibility (`public`, `protected`, `private`) on all class members.
- MUST use full `<?php` tags; no closing `?>` in pure PHP files.
- Uses `declare(strict_types=1)` in most source files (follows existing convention).
- CRITICAL: `$wpdb->prepare()` for ALL database queries — no exceptions.
- CRITICAL: Escape all output — `esc_html()`, `esc_attr()`, `esc_url()`.
- CRITICAL: Sanitize all input — `sanitize_text_field()`, `sanitize_email()`.
- Global function prefixes: `rmfa_` or `restrict_media_file_access_`.
- PHPCS prefixes: `a8csp_`, `rmfa_`, `restrict_media_file_access_`, `A8C\SpecialProjects\RestrictMediaFileAccess`.

### JavaScript

- **WordPress JavaScript Coding Standards**.
- MUST indent with **tabs**.
- MUST use single quotes for strings.
- MUST use strict equality (`===`).

### Accessibility

- WCAG 2.2 Level AA minimum.
- Semantic HTML, ARIA labels, keyboard navigation, 4.5:1 contrast ratio.

---

## Git Conventions

- Branch naming: `feature/<description>`, `fix/<description>`, `chore/<description>`.
- Commit messages: imperative mood ("Add feature" not "Added feature"), reference issues where applicable.
- PRs: include description of changes, testing steps, and screenshots for UI changes.
- All PRs MUST pass `composer lint:php:phpcs` before merge. The full `composer lint:php` does not pass: PHPMD and PHPStan have pre-existing violations/failures in untouched code.

---

## Versioning

The current plugin version is **1.6.0**. A version bump touches:

1. `Version:` in the `restrict-media-file-access.php` header (and its `@version`).
2. `**Stable tag:**` and a new `README.md` changelog entry.
3. `package.json` / `package-lock.json` root version.
4. The `plugin version is …` assertion in `tests/smoke/file-rbac.php`.

---

## Development Workflows

```bash
# Install dependencies
composer run-script packages-install
npm install

# Lint
composer lint:php          # PHPCS + PHPMD + PHPStan
composer lint:php:phpcs    # PHPCS only
composer lint:php:phpmd    # PHPMD only
composer lint:php:phpstan  # PHPStan only
composer format:php        # PHPCS auto-fix

# Tests (requires wp-env running)
npm run wp-env:start
npm run tests:run                     # Integration + E2E
npm run tests:run:integration         # Integration only
npm run tests:run:end-to-end          # E2E only

# i18n
composer internationalize             # Make POT, update PO, make MO, make PHP
```

---

## Testing

### Smoke Suite (primary)

- **Location:** `tests/smoke/file-rbac.php`
- **Run:** `wp eval-file wp-content/plugins/restrict-media-file-access/tests/smoke/file-rbac.php` inside a WordPress install with the plugin active.
- Every run creates its own users, role and attachments, so re-running is safe and leaves the fixtures behind.
- Use `rmfa_check()`; append new checks before the summary lines. The run must end with `N checks, 0 failures`.
- Do not declare `strict_types` in this file: `wp eval-file` prepends `?>` and the declaration becomes a fatal error.
- The fixtures (`$admin_id`, `$owner_id`, `$allowed_id`, `$unlisted_id`, `$role_user`, `$image_id`, `$public_id`, `$access`) already exist — reuse them instead of redeclaring.
- Use `rest_do_request()` for REST checks. A filter added through the CLI is invisible to the web container, so test hook-dependent behaviour in-process.
- The suite asserts the plugin version. Bump that assertion with every release (currently `1.6.0`).

### Integration Tests (Codeception + wp-browser)

The Codeception suites are scaffolded — `tests/Integration.suite.yml`, `tests/EndToEnd.suite.yml`, the two `_bootstrap.php` files and the support classes — but contain no test classes yet. The smoke suite is what covers behaviour today.

- **Location:** `tests/Integration/`
- **Framework:** Codeception with `lucatume/wp-browser` ^4.
- **Namespace:** `Tests\Integration\*`
- Run: `composer tests:run:integration` (requires wp-env)

### Codeception E2E

- **Location:** `tests/EndToEnd/`
- **Requires:** Selenium with Chromium.
- Run: `composer tests:run:end-to-end` (requires wp-env)

See `.agents/skills/rmfa-testing/SKILL.md` for full details on writing tests, test patterns, naming conventions, and local setup.

---

## Skills

This plugin uses a combination of [WordPress/agent-skills](https://github.com/WordPress/agent-skills) and plugin-specific skills.

### Plugin-Specific Skills (in `.agents/skills/`)

| Skill | When to Use |
|-------|-------------|
| `rmfa-architecture` | Working on plugin PHP: services, REST API, cron, settings. |
| `rmfa-file-protection` | File protection pipeline, URL rewriting, access control, file serving. |
| `rmfa-testing` | Writing or running tests (smoke suite, Codeception). |

### Recommended: WordPress Agent-Skills

The following skills from [WordPress/agent-skills](https://github.com/WordPress/agent-skills) are recommended but **not bundled** — install them globally to keep them up-to-date. See `.agents/README.md` for installation instructions.

| Skill | When to Use |
|-------|-------------|
| `wordpress-router` | Classifying the repo and routing to the correct workflow. |
| `wp-project-triage` | Inspecting project type, tooling, and versions. |
| `wp-plugin-development` | General WordPress plugin architecture and hooks. |
| `wp-rest-api` | REST API routes, schema, auth, permission callbacks. |
| `wp-performance` | Profiling, caching, database optimization. |
| `wp-wpcli-and-ops` | WP-CLI operations and automation. |
| `wp-playground` | Local development with wp-env. |
| `wp-phpstan` | PHPStan static analysis setup. |

---

## Important Notes & Common Pitfalls

### Important Notes

- **Pretty permalinks required:** The rewrite rules for `protected-files/{hash}` require pretty permalinks to be enabled.
- **Dot folder support:** The server must support folders starting with `.` (e.g., `.protected`).
- **Singleton pattern:** `Plugin` is a singleton. In tests, reset via reflection.
- **WooCommerce compatibility:** The plugin declares HPOS compatibility but does not depend on WooCommerce.
- **Protected directory:** Created at `wp-content/uploads/.protected/` on activation. Mirrors the year/month subdirectory structure of the regular uploads directory.
- **File hashes persist:** The `_protected_file_hash` post meta persists even after unprotecting, so re-protecting reuses the same hash.
- **Deny by default:** an unknown hash answers `404` for everyone, and an empty allow-list denies everyone but administrators and the uploader. A denied `404` and an unknown hash must match on status, body and headers.
- **Allow-list cache:** the denied-ID list is cached per user and per `rmfa_access_cache_version`; every meta write and role change bumps it. Do not read it without that version.
- **Cache headers:** allowed responses are `private, no-cache` and revalidate (`304`); denied responses are `private, no-store, max-age=0` with `Pragma: no-cache` and `Vary: Authorization`.
- **Self-update:** The plugin auto-updates from GitHub releases via the `update_plugins_github.com` filter.
- **Dual-layer caching:** `rmfa_set_cache()`/`rmfa_get_cache()` use both WP Object Cache and transients for performance.

### Common Pitfalls

- **DO NOT** use short array syntax (`[]`) — this project uses `array()` long syntax per WordPress standards.
- **DO NOT** edit files in `vendor/` — these are Composer-managed dependencies.
- **DO NOT** forget `$wpdb->prepare()` for ALL database queries — no exceptions.
- **DO NOT** create REST routes without a `permission_callback`.
- **DO NOT** bypass the `Filesystem` helper for file operations — use it instead of raw PHP file functions.
- **DO NOT** forget to flush rewrite rules after changing the rewrite rule pattern.
- **DO NOT** assume direct file access is possible for protected files — they are served through WordPress.
- **DO NOT** modify `_protected_file_hash` directly — use `AttachmentsFileManager` methods.
- Singletons (`Plugin`) **MUST** be reset via reflection in tests.
- Global state (`$_GET`, `$_SERVER`) **MUST** be saved in `setUp()` and restored in `tearDown()` in tests.

### WordPress Traps

| Trap | What happens |
|---|---|
| `save_post` is not fired for attachments | `wp_insert_post()` takes the attachment branch, fires `edit_attachment` + `attachment_updated`, then returns. Hook `edit_attachment`. |
| `wp_insert_post_data` is not fired for attachments | It is `wp_insert_attachment_data`. |
| `redirect_canonical()` 301s attachment pages | It runs on `template_redirect` priority 10 and redirects to `wp_get_attachment_url()`, which this plugin rewrites to the hash URL. Serve restricted attachment pages at priority 0. |
| `wp eval-file` prepends `?>` | `declare( strict_types=1 )` on line 2 is a fatal. The smoke file must not declare it. |
| Filters are per-process | A filter added in the CLI is invisible to the web container. Test such behaviour in-process. |
| `wp_remote_*` with `redirection => 0` | Returns the 3xx itself — the way to assert "no redirect". |
