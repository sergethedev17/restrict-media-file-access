---
name: rmfa-file-protection
description: Guides work on the Restrict Media File Access file protection pipeline including protecting/unprotecting files, hash-based URL rewriting, file serving with range request support, access control, attachment tracking, and URL replacement in post content. Use when debugging access issues, modifying the protection flow, working with URL rewriting, or changing how files are served.
---

# Skill: RMFA File Protection

## When to Use

Use this skill when:
- Debugging or modifying file protection/unprotection flows.
- Working with hash-based URL rewriting or the rewrite rules.
- Modifying how protected files are served (headers, range requests, access checks).
- Changing access control logic (who can view protected files).
- Working with attachment tracking (which posts use which attachments).
- Debugging URL replacement in post content after protect/unprotect.
- Troubleshooting "file not found" or access-denied issues.

## File Protection Architecture

### Core Concept

When a file is "protected", it is:
1. Physically moved from `wp-content/uploads/YYYY/MM/` to `wp-content/uploads/.protected/YYYY/MM/`.
2. Assigned a SHA-256 hash stored in post meta `_protected_file_hash`.
3. Served via a WordPress rewrite rule at `protected-files/{hash}` instead of a direct file URL.
4. Access-controlled by `AttachmentsAccess::user_can_access()`: unrestricted files keep the logged-in gate; restricted files allow the admin bypass, the uploader, the listed users and the listed roles, and deny everyone else — including when both allow-lists are empty. The `restrict_media_file_access_protect_file` filter can override the final decision.
5. Guarded on disk by `.protected/.htaccess` (`Require all denied`), written before a file is moved into the directory.

### Protection Flow (Restrict)

```
User clicks "Is restricted file" checkbox
  → AttachmentsAdmin::save_attachment_field()
  → AttachmentsFileManager::set_file_as_protected( $attachment_id )
    1. ensure_file_hash()           — Generate SHA-256 hash if not exists
    2. store_original_paths()       — Save original file + sizes paths to meta
    3. move_files_to_protected_directory() — Move main file + all sizes + scaled originals
    4. update_metadata_for_protected_file() — Update _wp_attachment_metadata
    5. replace_file_urls()           — Update all posts containing this file
  → update_post_meta( '_restricted_file', '1' )
```

### Unprotection Flow (Unrestrict)

```
User unchecks "Is restricted file" checkbox
  → AttachmentsAdmin::save_attachment_field()
  → AttachmentsFileManager::set_file_as_unprotected( $attachment_id )
    1. move_files_back_to_original_location() — Restore from .protected/ to original paths
    2. update_metadata_for_unprotected_file() — Restore _wp_attachment_metadata
    3. cleanup_original_paths()               — Remove _original_file_path, _original_sizes_paths meta
    4. replace_file_urls()                    — Update all posts back to normal URLs
  → delete_post_meta( '_restricted_file' )
```

## Post Meta Keys

| Meta Key | Stored On | Description |
|----------|-----------|-------------|
| `_restricted_file` | attachment | `'1'` if file is restricted. |
| `_protected_file_hash` | attachment | SHA-256 hash used in protected URLs. |
| `_original_file_path` | attachment | Original absolute path before protection. |
| `_original_sizes_paths` | attachment | Array of original size variation paths. |
| `_restricted_file_urls_map` | attachment | Map of old URL → new URL for content replacement. |
| `_rmfa_used_in_posts` | attachment | Array of post IDs that contain this attachment. |
| `_rmfa_attachments` | post | Array of attachment IDs used in this post. |
| `_rmfa_api_restricted` | attachment | Latest REST API restriction activity log. |

## Hash-Based URL System

### URL Format

Protected files are served at:
```
https://example.com/protected-files/{hash}
https://example.com/protected-files/{hash}-{width}x{height}
```

### Hash Generation

```php
$salt      = AUTH_SALT (or fallback to rmfa_hash_salt option)
$file_hash = hash( 'sha256', $salt . basename( $file_path ) . wp_generate_password( 8, false ) )
```

Stored in `_protected_file_hash` post meta. The hash is generated once when a file is first protected and persists across protect/unprotect cycles.

### URL Resolution

1. WordPress rewrite rule: `^protected-files/([^/]+)` → `index.php?protected_file=$matches[1]`.
2. `AttachmentsProtector::handle_protected_file_early()` reads the `protected_file` query var on `parse_request` and serves the request before the main query or template stack run. `restrict_media_file_access_serve_on_parse_request` (default `true`) can disable this and fall back to `handle_protected_file()` on `template_redirect` priority 5.
3. A restricted attachment page is served through the same pipeline on `template_redirect` priority 0, before `redirect_canonical()` can redirect it to the hash URL.
4. Extracts hash and optional size suffix from the URL.
5. Looks up the attachment ID via `resolve_attachment_id()`, which splits off the
   `-{width}x{height}` suffix and then calls `rmfa_find_attachment_id_by_hash()`.
6. Resolves the physical file path including size variations.

### Attachment URL Modification

`Attachments::modify_attachment_url()` intercepts `wp_get_attachment_url` and returns hash-based URLs for protected files. Similarly modifies:
- `wp_get_attachment_metadata` — Hash-based file paths in metadata.
- `wp_calculate_image_srcset` — Hash-based srcset URLs.
- `attachment_url_to_postid` — Reverse lookup from protected URLs to post IDs.
- `wp_get_attachment_image_attributes` — Adds `rmfa-protected="true"` attribute.

## File Serving Pipeline

### Access Check

```php
// AttachmentsProtector::is_file_protected()
// resolve_attachment_id() strips the -{width}x{height} suffix first, so a sized
// segment resolves to the same attachment as the full-size one.
$attachment_id = $this->resolve_attachment_id( $protected_file );

$default = null === $attachment_id
    ? true // unknown hash: 404 for everyone
    : ! ( new AttachmentsAccess() )->user_can_access( $attachment_id );

$is_protected = apply_filters( 'restrict_media_file_access_protect_file', $default, $protected_file );
```

**Default behavior:** an unrestricted file still requires a logged-in user. A restricted file is readable by administrators (`manage_options`, filterable via `rmfa_file_access_admin_bypass`), its uploader, the listed users and the listed roles; with empty lists only the first two get in. The filter receives the resolved decision and the `{hash}` segment, and can override it in either direction.

### Denied Response (Access Denied)

The response follows the deny-mode resolution: the per-file `_rmfa_deny_mode`, then the `rmfa_default_deny_mode` option, then `404`. `pixel` degrades to `404` for non-images unless a placeholder filter is registered. Every denied response is uncacheable: `Cache-Control: private, no-store, max-age=0`, `Pragma: no-cache`, `Vary: Authorization`.
- `404` — plain-text `Not found`
- `403` — plain-text `Forbidden`
- `pixel` — a 1x1 transparent GIF: `Content-Type: image/gif` plus the no-store headers (overridable via `restrict_media_file_access_protected_headers` and `restrict_media_file_access_protected_image`)

An unknown hash answers exactly the same `404` (status, body and headers) as a denied file's `404`, so the URL cannot be used as an existence oracle.

### Unprotected Response (Access Granted)

1. Refuses to serve a file that does not resolve inside the uploads directory (`realpath()` on both sides); controlled by `restrict_media_file_access_require_uploads_dir` (default `true`) and answered like a missing file.
2. Determines MIME type via `mime_content_type()`.
3. Sends `Cache-Control: private, no-cache`, `Vary: Authorization` and the `A8C-Edge-Cache` header (default `no-cache`, filterable). The browser may store the bytes but revalidates before reuse. Disables page caching (`DONOTCACHEPAGE`, `batcache_cancel()`).
4. Sends `ETag` / `Last-Modified` and answers `If-None-Match` / `If-Modified-Since` with `304 Not Modified`. The access check has already run, so a revoked user is denied rather than revalidated.
5. Answers `HEAD` with headers only.
6. Supports HTTP Range requests: `Accept-Ranges: bytes`, and `206 Partial Content` with `Content-Range` for a range request (an unsatisfiable range is `416`).
7. Streams the file in 8KB chunks. `restrict_media_file_access_serve_contents` may substitute the bytes (range serving is then disabled).
8. Fires `restrict_media_file_access_before_serve` before output.

### Entry Points

For integrations that resolve the `protected-files/{segment}` URL themselves:

* `serve_request( string $segment )` — the full pipeline: access check, denied response, file response. Exits for a non-empty segment.
* `serve_placeholder( string $segment )` — sends the configured denied response without re-running the access check. Exits.

## Attachment Tracking

`AttachmentsTracking` maintains a bidirectional index between posts and attachments:

### On Post Save (`wp_insert_post`)

1. Extracts all media URLs from post content (href, src, poster, srcset).
2. Resolves each URL to an attachment ID.
3. Computes diff vs previously tracked attachments.
4. Updates `_rmfa_used_in_posts` on each attachment.
5. Updates `_rmfa_attachments` on the post.

### On Post Delete (`before_delete_post`)

Removes the post from all tracked attachments.

### URL Fixing (`wp_insert_post_data`)

Before saving, checks if post content contains wrong URLs (e.g., public URL for a restricted file or protected URL for an unrestricted file) and auto-corrects them using the `_restricted_file_urls_map`.

## Key Filters and Actions

### Filters

| Filter | Description |
|--------|-------------|
| `restrict_media_file_access_protect_file` | Controls access to protected files. Receives `(bool $protect, string $segment)` where `$segment` is the hash with an optional `-{width}x{height}` suffix. |
| `restrict_media_file_access_protected_headers` | Modify headers sent for access-denied responses. |
| `restrict_media_file_access_protected_image` | Modify the base64 image data sent for access-denied responses. |
| `restrict_media_file_access_serve_contents` | Substitute the bytes served for a file. Receives `( $contents, $attachment_id, $file_path, $mime_type )`; a string disables range serving. |
| `restrict_media_file_access_serve_cache_control` | The `Cache-Control` header for an allowed response (default `private, no-cache`). Receives `( $cache_control, $attachment_id, $file_path )`. |
| `restrict_media_file_access_serve_on_parse_request` | Serve on `parse_request` (default `true`); `false` falls back to `template_redirect`. |
| `restrict_media_file_access_a8c_edge_cache` | The `A8C-Edge-Cache` header (default `no-cache`); an empty string suppresses it. |
| `restrict_media_file_access_require_uploads_dir` | Require the served file to resolve inside uploads (default `true`). Receives `( $require, $file_path )`. |
| `restrict_media_file_access_etag` | The `ETag` used for conditional requests. Receives `( $etag, $attachment_id, $file_path )`; an empty string disables both validators. |
| `rmfa_filter_attachment_query` | Attachment query args after listing filtering. Receives `( $args )`. |
| `rmfa_auto_restrict_on_upload` | Whether a new upload is restricted automatically (default `false`). Receives `( $should_restrict, $attachment_id, $attachment )`. |
| `rmfa_restricted_file_help_text` | Change the help text on the restriction checkbox. |
| `rmfa_restricted_file_helps_text` | Change the description text below the checkbox. |
| `rmfa_restricted_file_is_disabled` | Disable the restriction toggle for specific attachments. |
| `rmfa_file_access_admin_bypass` | Whether the current user bypasses the allow-lists as an administrator. Receives `(bool $bypass, int $attachment_id)`. |
| `rmfa_file_access_user_can_access` | Final say on whether the current user may read the file. Receives `(bool $allowed, int $attachment_id)`. |
| `rmfa_file_access_user_can_see_in_library` | Whether the file appears in the current user's media listings. Receives `(bool $allowed, int $attachment_id)`. |
| `rmfa_file_deny_mode` | The denied response (`404`, `403`, `pixel`). Receives `(string $mode, int $attachment_id)`. |
| `rmfa_manage_file_access_capability` | Capability required to read and change access lists (default `manage_options`). Receives `(string $capability)`. |
| `rmfa_api_restricted_meta_box_html` | Customize the restriction activity meta box HTML. |

`rmfa_rest_api_restricted_files_access` was removed in 1.6.0 — restricted files are hidden from listings by `AttachmentsAccess::user_can_see_in_library()`.

### Actions

| Action | Description |
|--------|-------------|
| `restrict_media_file_access_before_serve` | Fires before serving a protected file. Receives `(int $attachment_id, string $file_path)`. |
| `rmfa_attachment_restrictions_updated_on_save` | Fires after restriction status changes. Receives `(bool $result, int $file_id)`. |

## Jetpack Compatibility

`JetpackCompatibility` skips Jetpack Photon processing for any URL containing `.protected/` or `protected-files/` paths, preventing Photon from attempting to serve protected images.

## Procedure: Debugging File Access Issues

1. Check that rewrite rules are flushed — visit Settings → Permalinks or check `_rmfa_flush_rewrite_rules` transient.
2. Verify the `protected_file` query var is registered — `RewriteRules::add_query_vars()`.
3. Check `_protected_file_hash` exists on the attachment — `rmfa_get_media_protected_file_hash()`.
4. Verify the physical file exists at the expected `.protected/` path — `get_attached_file()`.
5. Check the `restrict_media_file_access_protect_file` filter return value.
6. For 404s on protected URLs: ensure pretty permalinks are enabled.

## Procedure: Adding a New Access Control Rule

1. Prefer `rmfa_file_access_user_can_access` when the rule is about a specific attachment — it receives the resolved decision and the attachment ID.
2. Fall back to `restrict_media_file_access_protect_file` for a request-level override; it receives `(bool $protect, string $protected_file)` where the segment is the `{hash}` (with an optional `-{width}x{height}` suffix), not the attachment ID.
3. Return `true` to block access, `false` to allow.
4. Example: allow access based on user role, membership, or token.

```php
add_filter( 'restrict_media_file_access_protect_file', function ( $protect, $protected_file ) {
    if ( current_user_can( 'subscriber' ) ) {
        return false; // Allow subscribers
    }
    return $protect;
}, 10, 2 );
```

## Verification

- Smoke suite ends `N checks, 0 failures`: `wp eval-file wp-content/plugins/restrict-media-file-access/tests/smoke/file-rbac.php`.
- Version stamps say `1.6.0`.
- `composer lint:php:phpcs` reports exactly one pre-existing warning — the unused
  `$requested_url` parameter in `AttachmentsProtector.php` — and no new ones.
  The full `composer lint:php` does **not** pass, and did not before this work:
  `phpmd` reports pre-existing violations in untouched code
  (`AttachmentsProtector` is a long class, `AttachmentsAutoRestrict` has unused
  parameters and an else clause), and `phpstan` cannot run under the repo
  config — `WPCompat`'s `SinceVersionRule` aborts with `No plugin or theme file
  found while analysing file models/index.php` because `WPCompat.pluginFile` is
  not set, so it reports nothing useful either way. Judge a change by "no new
  PHPCS findings" plus the smoke suite below, not by a green `lint:php`.
- Protected files are physically in `wp-content/uploads/.protected/`.
- Protected URLs resolve correctly at `protected-files/{hash}`.
- A restricted file is readable by its admin, uploader, listed users and listed roles; an empty allow-list denies everyone else.
- A denied request gets the configured mode: `404` by default, or `403` / the 1x1 GIF when set per file or site-wide.
- An allowed response revalidates (`304`) and is never served to a revoked user.
- URL replacement works correctly in post content after protect/unprotect.
- Attachment tracking meta is accurate.
