---
name: rmfa-architecture
description: Guides work on Restrict Media File Access WordPress plugin PHP architecture including services, REST API, cron, settings, filesystem helper, and bulk operations. Use when adding or modifying services, REST endpoints, admin UI, settings, cron jobs, or understanding the plugin bootstrap and service pattern.
---

# Skill: RMFA Plugin Architecture

## When to Use

Use this skill when:
- Adding or modifying services, REST endpoints, or settings in the RMFA plugin.
- Understanding the plugin bootstrap and service registration pattern.
- Working with the filesystem helper, bulk operations, or cron jobs.
- Adding new features that need to integrate with existing services.

## Bootstrap Flow

1. `restrict-media-file-access.php` defines constants (`RESTRICT_MEDIA_FILE_ACCESS_BASENAME`, `_DIR_PATH`, `_DIR_URL`).
2. Requires `functions-bootstrap.php` (plugin metadata, version checks, error display).
3. Loads translations via `init` hook.
4. Declares WooCommerce compatibility (HPOS).
5. Loads Composer autoloader from `vendor/autoload.php`.
6. Validates requirements (PHP 8.3+, WP 6.4+) via `restrict_media_file_access_validate_requirements()`.
7. If requirements pass: requires `functions.php` (plugin instance getter, includes loader, logger) and hooks `plugins_loaded` → `Plugin::get_instance()->maybe_initialize()`. `ApplicationPasswords` is initialized here, at load time, because the current user is resolved before `plugins_loaded`.
8. Activation hook: creates `.protected` directory and its `.htaccess` guard, schedules initial setup cron, sets transient to flush rewrite rules.

## Service Pattern

Every feature is a "service" — a class with an `initialize()` method that hooks into WordPress. `Plugin::initialize()` instantiates all services and calls `initialize()` on each.

```php
// Adding a new service:
// 1. Create the class in src/
class MyFeature {
    public function initialize(): void {
        add_action( 'init', array( $this, 'do_something' ) );
    }

    public function do_something(): void {
        // Feature logic
    }
}

// 2. Register it in Plugin::initialize()
$this->services = array(
    // ... existing services ...
    'my-feature' => new MyFeature(),
);
```

### Registered Services (in order)

| Key | Class | Responsibility |
|-----|-------|----------------|
| `self-update` | `SelfUpdate` | GitHub-hosted auto-updates via `update_plugins_github.com` filter. |
| `cron` | `Cron` | Initial setup cron job (`rmfa_run_initial_setup`). |
| `settings` | `Settings` | Admin settings on Media Settings page, asset enqueuing. |
| `attachments-protector` | `AttachmentsProtector` | Handles `template_redirect` to serve/block protected files. |
| `attachments-access` | `AttachmentsAccess` | Per-file allow-lists, access rule order, deny-mode resolution, media-listing filters, denied-ID cache. |
| `attachments-access-admin` | `AttachmentsAccessAdmin` | "File access" meta box, its save handler and the AJAX user search. |
| `jetpack-compatibility` | `JetpackCompatibility` | Skips Jetpack Photon for restricted images. |
| `attachments` | `Attachments` | Modifies attachment URLs, metadata, srcset, image attributes. |
| `attachments-admin` | `AttachmentsAdmin` | Admin UI: restriction checkbox, media column, meta box. |
| `attachments-upload` | `AttachmentsUpload` | Unique filename check against protected directory. |
| `attachments-removal` | `AttachmentsRemoval` | Cleanup on attachment deletion. |
| `rewrite-rules` | `RewriteRules` | WordPress rewrite rules for `protected-files/{hash}` URLs. |
| `cache-cleanup` | `CacheCleanup` | Cache invalidation on attachment/post deletion. |
| `attachments-tracking` | `AttachmentsTracking` | Bidirectional tracking: which posts use which attachments. |
| `rest-api` | `RestApi` | REST API endpoints for restrict/unrestrict/status/access. |
| `auto-restrict` | `AttachmentsAutoRestrict` | Optionally restricts new uploads (`rmfa_auto_restrict_on_upload`) and repairs protected metadata. |

## REST API

All routes registered under `restrict-media-file-access/v1`:

| Method | Endpoint | Handler | Description |
|--------|----------|---------|-------------|
| POST/PUT/PATCH | `/media/{file_id}/restrict` | `RestApi::restrict_file()` | Restrict or unrestrict a file. |
| GET | `/media/{file_id}/status` | `RestApi::get_file_status()` | Get restriction status, URL, metadata. The `access` key is only added for users with `rmfa_manage_file_access_capability()`. |
| POST/PUT/PATCH | `/media/{file_id}/access` | `RestApi::set_file_access()` | Replace the `users`, `roles` and `deny_mode` of a file. |

### Permission Callbacks

- `check_permissions()` — `upload_files` AND `edit_post` for the specific file ID (`restrict`, `status`).
- `check_manage_permissions()` — `rmfa_manage_file_access_capability()` (default `manage_options`) on `access`.

### Request Parameters (restrict endpoint)

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `file_id` | int | required | Attachment post ID. |
| `restrict` | bool | `true` | Whether to restrict or unrestrict. |
| `update_post` | bool | `true` | Whether to update all posts containing the file. |

### Request Parameters (access endpoint)

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `file_id` | int | required | Attachment post ID. |
| `users` | int[] | required (may be `[]`) | User IDs allowed to read the file. |
| `roles` | string[] | required (may be `[]`) | Role slugs allowed to read the file. |
| `deny_mode` | string | required | `404`, `403`, `pixel`, or `default` for the site-wide setting. |

The endpoint is a full replacement, so all three rule parameters are required: an incomplete POST is a `400` and leaves the stored rule untouched.

### REST Filters

- `rmfa_filter_attachment_query` — Modifies the attachment query args after RMFA filtering.
- `rmfa_file_access_user_can_see_in_library` — Runs inside `AttachmentsAccess::denied_attachment_ids()`, which is what hides restricted files from `rest_attachment_query` and `ajax_query_attachments_args`.
- `rmfa_rest_api_restricted_files_access` was removed in 1.6.0.

## Settings

Settings live on the WordPress **Media Settings** page (`/wp-admin/options-media.php`) in a custom section:

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `rmfa_show_border_on_restricted_files` | bool | `true` | Show visual border on restricted files in admin. |
| `rmfa_should_old_restricted_file_urls_work_if_file_set_as_public` | bool | `true` | Allow old protected URLs to resolve after unrestricting. |
| `rmfa_default_deny_mode` | string | `404` | Site-wide denied response (`404`, `403`, `pixel`). Sanitized by `Settings::sanitize_default_deny_mode()`. |

## Filesystem Helper

`Filesystem` is a static utility wrapping `WP_Filesystem_Base`:

| Method | Description |
|--------|-------------|
| `Filesystem::exists( $path )` | Check if file/dir exists. |
| `Filesystem::move( $src, $dst )` | Move a file. |
| `Filesystem::delete( $path )` | Delete a file. |
| `Filesystem::delete_recursive( $path )` | Delete a directory recursively. |
| `Filesystem::read( $path )` | Read file contents. |
| `Filesystem::put_contents( $path, $contents )` | Write file contents. |
| `Filesystem::dirlist( $path, $options )` | List directory contents. |

## Bulk Operations

`BulkOperations::update_all_attachments()` processes all public posts in batches to build the attachment tracking index. Called by `Cron::run_initial_setup()` on first activation.

## Constants

| Constant | Value | Description |
|----------|-------|-------------|
| `RESTRICT_MEDIA_FILE_ACCESS_PROTECTED_DIR` | `.protected` | Directory name inside `wp-content/uploads/`. |
| `RESTRICT_MEDIA_FILE_ACCESS_PROTECTED_PATH` | `protected-files` | URL path segment for rewrite rules. |
| `RESTRICT_MEDIA_FILE_ACCESS_BASENAME` | `restrict-media-file-access/restrict-media-file-access.php` | Plugin basename. |
| `RESTRICT_MEDIA_FILE_ACCESS_DIR_PATH` | (dynamic) | Plugin directory path. |
| `RESTRICT_MEDIA_FILE_ACCESS_DIR_URL` | (dynamic) | Plugin directory URL. |

## Global Helper Functions

| Function | Description |
|----------|-------------|
| `rmfa_set_file_as_protected( $id, $options )` | Protect a file (delegates to `AttachmentsFileManager`). |
| `rmfa_set_file_as_unprotected( $id, $options )` | Unprotect a file. |
| `rmfa_is_media_restricted( $id )` | Check if an attachment is restricted. |
| `rmfa_find_attachment_id_by_hash( $hash )` | Lookup attachment ID from file hash. |
| `rmfa_get_media_protected_file_hash( $id )` | Get the hash for a protected file. |
| `rmfa_get_posts_with_media_url( $id )` | Get post IDs that contain a given attachment. |
| `rmfa_set_cache()` / `rmfa_get_cache()` / `rmfa_delete_cache()` | Dual-layer cache (WP Object Cache + transients). |
| `rmfa_log_error( $message )` | Log with `RMFA:` prefix. |
| `rmfa_manage_file_access_capability()` | Capability required to read/change access lists (filterable, default `manage_options`). |
| `rmfa_get_file_access_users( $id )` / `rmfa_get_file_access_roles( $id )` | Stored allow-lists for a file. |
| `rmfa_get_file_deny_mode( $id )` | Effective denied response, via `AttachmentsAccess::get_deny_mode()`. |
| `rmfa_user_can_access_file( $id )` / `rmfa_user_can_see_file_in_library( $id )` | Access and listing checks for the current user. |
| `rmfa_ensure_protected_dir_htaccess()` | Writes the `.protected/.htaccess` guard; returns `false` if it cannot. |
| `rmfa_should_old_restricted_file_urls_work_if_file_set_as_public()` | Reads the matching option. |

## Verification

- `composer lint:php:phpcs` reports no new findings. One warning is pre-existing: the unused `$requested_url` parameter in `AttachmentsProtector.php`. The full `composer lint:php` does not pass (pre-existing PHPMD violations and a PHPStan config failure), so judge a change by PHPCS plus the smoke suite.
- Smoke suite ends `N checks, 0 failures`.
- New services are registered in `Plugin::initialize()`.
- New REST routes have proper `permission_callback`.
- Version stamps (`Version:`, README stable tag, smoke assertion, `package.json`) say `1.6.0`.
