# Restrict Media File Access

**Contributors:** wpcomspecialprojects  
**Tags:** media, files, access control, security, attachments, protection  
**Requires at least:** 6.4  
**Tested up to:** 6.8  
**Requires PHP:** 8.3  
**Stable tag:** 1.6.0  
**License:** GPLv3 or later  
**License URI:** <http://www.gnu.org/licenses/gpl-3.0.html>  

Secure your WordPress media files with per-file access control. Protect images, documents, and other uploads, then decide file by file which users and roles may read them.

**[Download the latest release](https://github.com/a8cteam51/restrict-media-file-access/releases/latest/download/restrict-media-file-access.zip)**

## Description

Restrict Media File Access is a powerful WordPress plugin that helps you protect your media files from unauthorized access. Each protected file is served only to administrators, its uploader, and the users and roles you list on it; with no lists filled in, only administrators and the uploader can read it.

### Key Features

* **Selective File Protection**: Choose which media files to protect on a per-file basis
* **Per-File Access Lists**: Grant read access to specific users or roles, with owner and administrator bypass
* **Secure File Storage**: Protected files are moved to the hidden `uploads/.protected/` folder, which is denied direct web access by its own `.htaccess` guard
* **Automatic Image Handling**: Supports all image sizes and variations
* **Performance-Optimized**: Built with performance in mind, using WordPress filesystem abstraction
* **Developer Friendly**: Includes filters and actions for customization
* **REST API**: Programmatic access to manage file restrictions

### Requirements

* Pretty permalinks must be used
* The server needs to support dot folders

### Technical Features

* Uses WordPress Filesystem API for secure file operations
* Supports all media file types
* Handles image sizes and thumbnails
* Maintains original file paths for easy restoration
* Implements proper cache control headers
* Answers conditional requests (`ETag` / `Last-Modified`) with `304 Not Modified`, and supports `HEAD` and HTTP `Range` requests
* Secure hash-based file access
* REST API endpoints for programmatic control

### Use Cases

* Membership sites
* Client portals
* Private galleries
* Protected documents
* Premium content
* Educational materials

## Installation

### Manual Installation

1. Download the plugin ZIP file
2. Log in to your WordPress dashboard
3. Navigate to Plugins → Add New → Upload Plugin
4. Upload the ZIP file and click "Install Now"
5. Activate the plugin

## Usage

### Protecting Files

1. Go to Media Library
2. Click on a file to edit
3. Check the "Is restricted file" checkbox
4. Save changes
5. In the "File access" box, list the users and roles that may read the file, and pick the response for everyone else

The file will be moved to a protected location and served only to administrators (`manage_options`), its uploader, and the users and roles in its access lists. When both lists are empty, only administrators and the uploader can read it.

### Managing Protected Files

* Protected files are marked with a lock icon in the Media Library
* Original file paths are preserved in metadata for easy restoration
* All image sizes are automatically protected
* Files can be unprotected by unchecking the restriction

### Settings

Settings → Media → **Restrict Media File Access Settings**:

| Setting | Default | Effect |
|---|---|---|
| Show border on restricted files | On | Draws the admin border on restricted tiles. |
| Should old restricted file URLs work if file set as public? | On | Keeps a file's old `protected-files/{hash}` URL working after the file is unrestricted. |
| Response when access is denied | `404` | Site-wide denied response (`404`, `403` or the 1×1 image) for files without their own. |

A per-file denied response set in the "File access" box overrides the site-wide choice.

### Developer Features

#### Filters

* `restrict_media_file_access_protect_file`: Control whether a file should be protected
* `restrict_media_file_access_serve_contents`: Substitute the bytes served for a protected file on a per-request basis (e.g. watermarking). Return a string to serve it verbatim (range serving is disabled), or `null` to serve the file unchanged. Receives `( $contents, $attachment_id, $file_path, $mime_type )`.
* `restrict_media_file_access_serve_cache_control`: Adjust the `Cache-Control` header sent with successfully served files (default `private, no-store, max-age=0`). Only relax it when every credential is part of the URL itself and the served bytes are not personalized per request. Receives `( $cache_control, $attachment_id, $file_path )`.
* `restrict_media_file_access_serve_on_parse_request`: Whether to serve protected files on `parse_request` (default `true`). Return `false` to fall back to serving on `template_redirect`, e.g. when an access integration registers its `restrict_media_file_access_protect_file` filter later than `init`.
* `restrict_media_file_access_a8c_edge_cache`: Value of the `A8C-Edge-Cache` header sent with protected-file responses (default `no-cache`, which keeps these per-user responses out of the WordPress.com/WP Cloud edge cache so conditional requests reach the plugin). Return an empty string to suppress the header. Ignored by other hosts.
* `restrict_media_file_access_require_uploads_dir`: Whether a served file must resolve (via `realpath()`, so a symlinked uploads directory is fine) to a path inside the uploads directory (default `true`). Files that fail the check get the same 404 as a missing file. Return `false` only when protected files are deliberately kept elsewhere, e.g. a `.protected` directory symlinked outside uploads. Receives `( $require_uploads_dir, $file_path )`.
* `rmfa_file_access_admin_bypass`: Whether the current user bypasses the per-file allow-lists as an administrator (default `current_user_can( 'manage_options' )`). Receives `( $bypass, $attachment_id )`.
* `rmfa_file_access_user_can_access`: The final word on whether the current user may read the file, after the rule order (admin bypass, uploader, user list, role list) has been applied. Receives `( $allowed, $attachment_id )`.
* `rmfa_file_access_user_can_see_in_library`: Whether a file appears in the current user's media listings (REST media, the media modal, the classic library). Receives `( $allowed, $attachment_id )`.
* `rmfa_file_deny_mode`: The response sent when access is denied — `404`, `403` or `pixel`. Receives `( $mode, $attachment_id )`.
* `rmfa_manage_file_access_capability`: The capability required to read and change a file's access lists (default `manage_options`). Receives `( $capability )`.
* `restrict_media_file_access_protected_headers`: Headers sent with the 1×1 placeholder, as `key => value` pairs. Receives `( $headers, $protected_file )`.
* `restrict_media_file_access_protected_image`: The base64-encoded image sent for the `pixel` denied response. Receives `( $image_data, $protected_file )`.
* `restrict_media_file_access_etag`: The `ETag` used for conditional requests (default: attachment ID, file path and modification time). Return an empty string to disable `ETag` and `Last-Modified` handling altogether. Receives `( $etag, $attachment_id, $file_path )`.
* `rmfa_filter_attachment_query`: Attachment query arguments after the listing filter has hidden unreadable files. Receives `( $args )`.
* `rmfa_auto_restrict_on_upload`: Whether a newly uploaded attachment is restricted automatically (default `false`). Receives `( $should_restrict, $attachment_id, $attachment )`.
* `rmfa_restricted_file_is_disabled`: Disables the restriction toggle for a specific attachment. Receives `( $is_disabled, $post )`.

#### Actions

* `restrict_media_file_access_before_serve`: Fires before a protected file's bytes are streamed. Receives `( $attachment_id, $file_path )`.
* `rmfa_attachment_restrictions_updated_on_save`: Fires after the restriction checkbox is saved. Receives `( $restrictions_set, $attachment_id )`.

#### Integration entry points

`AttachmentsProtector` exposes two public methods for integrations that resolve the `protected-files/{segment}` URL themselves (for example a lightweight handler that runs before the rewrite rules and main query). Both send the response and exit, exactly like the built-in `parse_request` / `template_redirect` handlers:

* `serve_request( string $segment )`: runs the full pipeline — the `restrict_media_file_access_protect_file` access check, the placeholder for denied requests, and the file response (conditional requests, `HEAD`, `Range`, `restrict_media_file_access_serve_contents`) otherwise. Any filter the integration relies on must already be registered. Does nothing for an empty segment.
* `serve_placeholder( string $segment )`: sends the file's configured denied-access response — the `404`, the `403`, or the 1×1 GIF (`restrict_media_file_access_protected_headers` / `restrict_media_file_access_protected_image`) — without re-running the access check, for callers that have already denied the request.

```php
$protector = new \A8C\SpecialProjects\RestrictMediaFileAccess\AttachmentsProtector();
$protector->serve_request( $segment ); // never returns for a non-empty $segment
```

### Security Features

* Files are stored in a hidden folder
* Uses WordPress Filesystem API
* Implements proper cache control
* Secure hash-based file access
* No direct file access: a `.protected/.htaccess` guard denies the physical path

## REST API

This plugin provides REST API endpoints for managing file restrictions programmatically. All endpoints require authentication and appropriate capabilities.

### Base URL

All endpoints are prefixed with `/wp-json/restrict-media-file-access/v1/`

### Authentication

All endpoints require WordPress authentication. You can authenticate using:

* WordPress REST API authentication
* Application passwords
* Nonces for logged-in users

### Permissions

| Endpoint | Capability |
|---|---|
| `POST /media/{file_id}/restrict` | `upload_files` and `edit_post` for the file |
| `GET /media/{file_id}/status` | `upload_files` and `edit_post` for the file |
| `POST /media/{file_id}/access` | `rmfa_manage_file_access_capability()` (default `manage_options`) |

The `access` block of the status response — who may read the file and how a denied request is answered — is returned only to users who pass `rmfa_manage_file_access_capability()`.

### Endpoints

#### 1. Restrict/Unrestrict a File

**Endpoint:** `POST /wp-json/restrict-media-file-access/v1/media/{file_id}/restrict`

**Parameters:**

* `file_id` (required): The ID of the attachment to restrict/unrestrict
* `restrict` (optional): Boolean to restrict (true) or unrestrict (false). Default: true
* `update_post` (optional): Boolean update all posts where the file is located. Default: true

**Permissions:** Requires `upload_files` capability and `edit_post` capability for the file ID

#### 2. Get File Status

**Endpoint:** `GET /wp-json/restrict-media-file-access/v1/media/{file_id}/status`

**Parameters:**

* `file_id` (required): The ID of the attachment

**Permissions:** Requires `upload_files` capability and `edit_post` capability for the file ID

#### 3. Replace the Access Rules of a File

**Endpoint:** `POST /wp-json/restrict-media-file-access/v1/media/{file_id}/access`

**Parameters:**

* `file_id` (required): The ID of the attachment
* `users` (required): Array of user IDs allowed to read the file. Send `[]` to clear the list.
* `roles` (required): Array of role slugs allowed to read the file. Send `[]` to clear the list.
* `deny_mode` (required): `404`, `403`, `pixel`, or `default` to fall back to the site-wide setting

The request replaces the whole rule, so all three parameters are required: an incomplete request is answered with a `400` and changes nothing.

**Permissions:** Requires the `rmfa_manage_file_access_capability()` capability (default `manage_options`)

**Response:** `users`, `roles`, the stored `deny_mode` (an empty string means "site default") and `deny_mode_effective`.

### Security Notes

1. Every endpoint has its own permission callback: `upload_files` + `edit_post` for the file on `/restrict` and `/status`, the `rmfa_manage_file_access_capability()` capability on `/access`
2. File IDs are validated to ensure they are valid attachments
3. File existence is verified before processing
4. All input is sanitized and validated
5. Error messages do not expose sensitive information

## Frequently Asked Questions

### What happens to protected files when the plugin is deactivated?

Protected files remain in the secure directory but become inaccessible. Upon reactivation, all protected files become accessible again.

### Can I protect files selectively?

Yes, you can choose which files to protect on a per-file basis in the Media Library.

### Does it work with all file types?

Yes, the plugin works with all file types that WordPress allows uploading.

### How does it handle image sizes?

All image sizes (thumbnails, medium, large, etc.) are automatically protected when you protect the original image.

### Can I use the REST API to manage file restrictions?

Yes, the plugin provides REST API endpoints for programmatically managing file restrictions. See the REST API section above for details.

## Contributing

We welcome contributions! Please feel free to submit a Pull Request.

Run the smoke suite inside a WordPress install with the plugin active:

```bash
wp eval-file wp-content/plugins/restrict-media-file-access/tests/smoke/file-rbac.php
```

It creates its own fixtures and must end with `N checks, 0 failures`. Lint PHP with
`composer lint:php:phpcs`.

## Changelog

### 1.6.0

* Per-file access lists: each restricted file can name the users and roles allowed to read it. Administrators (`manage_options`) and the file's author always keep access; when both lists are empty, only those two bypasses can read the file.
* Configurable denied responses: `404` (default), `403`, or a 1×1 GIF placeholder, set per file and site-wide. The placeholder falls back to `404` for non-images unless an integration registers a placeholder filter.
* Media listings (REST, the media modal, the classic library) hide restricted files the current user may not read.
* New `POST /media/{file_id}/access` REST endpoint to replace a file's access lists and denied response; `GET /media/{file_id}/status` now reports the effective settings to the users allowed to manage them.
* Breaking change: the `rmfa_rest_api_restricted_files_access` filter is gone. Restricted files are hidden from media listings by `AttachmentsAccess::user_can_see_in_library()`; use `rmfa_file_access_user_can_see_in_library` instead.
* Deny direct requests to the physical file: the protected directory gets an `.htaccess` guard (`Require all denied`, with an `Order allow,deny` fallback for older Apache) created on activation and whenever a file is protected.
* Fix the File access meta box never saving. WordPress does not fire `save_post` for attachments — `wp_insert_post()` fires `edit_attachment` and returns before `save_post` — so the denied response and access lists were never stored from the attachment edit screen. The handler now hooks `edit_attachment`.
* Serve a restricted attachment's page through the same pipeline as its protected file URL. With attachment pages disabled, `redirect_canonical()` 301s the page to `wp_get_attachment_url()`, which for a restricted file is the protected-files URL — exposing the hash and adding a hop. The page now returns the same denied response (or the file) as the file URL, with no redirect.
* Let the browser keep allowed responses and revalidate them: served files now send `Cache-Control: private, no-cache` instead of `private, no-store, max-age=0`, so an unchanged file answers `304 Not Modified` and only a changed one is streamed again. The access check runs before the conditional branch, so a revoked user is denied rather than revalidated. Still filterable through `restrict_media_file_access_serve_cache_control`.
* Denied responses stay uncacheable (`private, no-store, max-age=0`, `Pragma: no-cache`), and the legacy `Cache-Control: post-check=0, pre-check=0` line is gone, so a placeholder or `404` is never pinned in a cache.
* Denied `404` responses and never-resolved hashes are now covered by a smoke check asserting they match on status, body and every header, so a future change cannot reintroduce an oracle.

### 1.5.1

* Stop the Media Library grid from overwriting the open Edit Media modal. The attachment render override reaches `.attachment-details` with a document-wide selector, so while the grid was still loading pages, every tile rendered behind the modal replaced the preview image with its own file and toggled the restricted-file styling on and off. Both writes are now skipped unless the attachment being rendered is the one the modal is showing.

### 1.5.0

* Add `AttachmentsProtector::serve_request()` and `AttachmentsProtector::serve_placeholder()` as public entry points so integrations can serve a protected-file request (or the denied placeholder) with the built-in behaviour.
* Refuse to serve a file that does not resolve inside the uploads directory (both sides compared via `realpath()`; responds with the file-not-found 404). Controlled by the new `restrict_media_file_access_require_uploads_dir` filter (default `true`).
* Check file existence on the serve path with native `is_file()` / `is_readable()` instead of `WP_Filesystem`, so serving a file no longer triggers the `WP_Filesystem` initialization (and, when `FS_METHOD` is unset, its temporary-file write probe) on every request. `WP_Filesystem` is still used for moving and deleting files.
