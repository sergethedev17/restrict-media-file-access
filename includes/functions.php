<?php declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use A8C\SpecialProjects\RestrictMediaFileAccess\AttachmentsAccess;
use A8C\SpecialProjects\RestrictMediaFileAccess\AttachmentsTracking;
use A8C\SpecialProjects\RestrictMediaFileAccess\AttachmentsFileManager;
use A8C\SpecialProjects\RestrictMediaFileAccess\Filesystem;

/**
 * Find the attachment ID by hash.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param string $hash The hash to search for.
 *
 * @return int|null The attachment ID if found, false otherwise.
 */
function rmfa_find_attachment_id_by_hash( string $hash ): int|null {

	$cached_attachment_id = rmfa_get_cache( 'rmfa_attachment_id_' . $hash );

	if ( ! empty( $cached_attachment_id ) ) {
		return (int) $cached_attachment_id;
	}

	global $wpdb;
	$attachment_id = $wpdb->get_var(
		$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_protected_file_hash' AND meta_value = %s LIMIT 1", $hash )
	);

	if ( null === $attachment_id ) {
		return null;
	}

	rmfa_set_cache( 'rmfa_attachment_id_' . $hash, $attachment_id, '', 60 * 60 * 24 * 7 );

	return (int) $attachment_id;
}

/**
 * Get the protected file hash for a media item.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param int $media_id The ID of the media.
 *
 * @return string|null The protected file hash if found, null otherwise.
 */
function rmfa_get_media_protected_file_hash( int $media_id ): ?string {
	$hash = get_post_meta( $media_id, '_protected_file_hash', true );
	if ( empty( $hash ) ) {
		return null;
	}

	return $hash;
}

/**
 * Check if old restricted file URLs should work if file is set as public.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return bool True if old restricted file URLs should work if file is set as public, false otherwise.
 */
function rmfa_should_old_restricted_file_urls_work_if_file_set_as_public(): bool {
	return (bool) get_option( 'rmfa_should_old_restricted_file_urls_work_if_file_set_as_public', true );
}

/**
 * Set file as protected.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param int                $file_id The file ID to set as protected.
 * @param array<string,bool> $options The options: update_post (bool).
 *
 * @return bool True if the file was set as protected, false otherwise.
 */
function rmfa_set_file_as_protected( int $file_id, array $options = array() ): bool {
	$file_manager = new AttachmentsFileManager();

	return $file_manager->set_file_as_protected( $file_id, $options );
}

/**
 * Set file as unprotected.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param int                $file_id The file ID to set as unprotected.
 * @param array<string,bool> $options The options: update_post (bool).
 *
 * @return bool True if the file was set as unprotected, false otherwise.
 */
function rmfa_set_file_as_unprotected( int $file_id, array $options = array() ): bool {
	$file_manager = new AttachmentsFileManager();

	return $file_manager->set_file_as_unprotected( $file_id, $options );
}

/**
 * Get posts with media URL.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param int        $media_id The ID of the media.
 * @param array<int> $exclude_ids The IDs to exclude from the results.
 *
 * @return array<int> The post IDs with the media URL.
 */
function rmfa_get_posts_with_media_url( int $media_id, array $exclude_ids = array() ): array {
	$post_ids = get_post_meta( $media_id, '_rmfa_used_in_posts', true );

	if ( empty( $post_ids ) ) {
		return array();
	}

	$post_ids = array_diff( $post_ids, $exclude_ids );

	return $post_ids;
}

/**
 * Check if the media is restricted.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param int $media_id The ID of the media.
 *
 * @return bool True if the media is restricted, false otherwise.
 */
function rmfa_is_media_restricted( int $media_id ): bool {
	return boolval( get_post_meta( $media_id, '_restricted_file', true ) );
}

/**
 * Set value in both WP Cache and transient.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param string $key        Cache key.
 * @param mixed  $value      Value to store.
 * @param string $cache_group WP Cache group (optional).
 * @param int    $expiration Expiration time in seconds for transient.
 * @return void
 */
function rmfa_set_cache( string $key, $value, string $cache_group = '', int $expiration = HOUR_IN_SECONDS ): void {
	// Set in WP Cache (memory, current request only)
	wp_cache_set( $key, $value, $cache_group, $expiration );

	// Set in transient (persistent between requests)
	set_transient( $key, $value, $expiration );
}

/**
 * Get value from cache with fallback strategy.
 * First tries WP Cache, then transient if not found.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param string $key        Cache key.
 * @param string $cache_group WP Cache group (optional).
 * @return mixed|false       Cached value or false if not found.
 */
function rmfa_get_cache( string $key, string $cache_group = '' ) {
	// Try WP Cache first (fastest)
	$value = wp_cache_get( $key, $cache_group );

	// If not in WP Cache, try transient
	if ( false === $value ) {
		$value = get_transient( $key );

		// If found in transient, also store in WP Cache for this request
		if ( false !== $value ) {
			wp_cache_set( $key, $value, $cache_group );
		}
	}

	return $value;
}

/**
 * Delete value from both WP Cache and transient.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param string $key        Cache key.
 * @param string $cache_group WP Cache group (optional).
 * @return void
 */
function rmfa_delete_cache( string $key, string $cache_group = '' ): void {
	// Delete from WP Cache
	wp_cache_delete( $key, $cache_group );

	// Delete from transient
	delete_transient( $key );
}

/**
 * Get media IDs with URLs from post content.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param string $post_content The post content.
 *
 * @return array<int, mixed> The media IDs with URLs.
 */
function rmfa_get_media_ids_with_urls_from_post_content( string $post_content ): array {
	$attachments_tracking = new AttachmentsTracking();
	$media_ids_with_urls  = $attachments_tracking->get_media_ids_with_urls_from_post_content( $post_content );

	return $media_ids_with_urls;
}

/**
 * Get the capability required to manage per-file access lists.
 *
 * @since   1.6.0
 * @version 1.6.0
 *
 * @return string The capability name.
 */
function rmfa_manage_file_access_capability(): string {
	/**
	 * Filter the capability required to manage per-file access lists.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param string $capability The capability name. Default `manage_options`.
	 *
	 * @return string The capability name.
	 */
	return (string) apply_filters( 'rmfa_manage_file_access_capability', 'manage_options' );
}

/**
 * Get the IDs of the users allowed to read a file.
 *
 * @since   1.6.0
 * @version 1.6.0
 *
 * @param int $file_id The file ID.
 *
 * @return array<int> The allowed user IDs.
 */
function rmfa_get_file_access_users( int $file_id ): array {
	return AttachmentsAccess::get_access_users( $file_id );
}

/**
 * Get the roles allowed to read a file.
 *
 * @since   1.6.0
 * @version 1.6.0
 *
 * @param int $file_id The file ID.
 *
 * @return array<string> The allowed role slugs.
 */
function rmfa_get_file_access_roles( int $file_id ): array {
	return AttachmentsAccess::get_access_roles( $file_id );
}

/**
 * Get the response to send when access to a file is denied.
 *
 * @since   1.6.0
 * @version 1.6.0
 *
 * @param int $file_id The file ID.
 *
 * @return string One of the AttachmentsAccess::DENY_MODES values.
 */
function rmfa_get_file_deny_mode( int $file_id ): string {
	return ( new AttachmentsAccess() )->get_deny_mode( $file_id );
}

/**
 * Check whether the current user may read the file through the protected-file URL.
 *
 * Unrestricted files are not public here: they still require a logged-in user,
 * exactly like the protected-file handler.
 *
 * @since   1.6.0
 * @version 1.6.0
 *
 * @param int $file_id The file ID.
 *
 * @return bool True if the current user may read the file, false otherwise.
 */
function rmfa_user_can_access_file( int $file_id ): bool {
	return ( new AttachmentsAccess() )->user_can_access( $file_id );
}

/**
 * Check whether the current user may see a file in a media listing.
 *
 * @since   1.6.0
 * @version 1.6.0
 *
 * @param int $file_id The file ID.
 *
 * @return bool True if the current user may see the file in a listing, false otherwise.
 */
function rmfa_user_can_see_file_in_library( int $file_id ): bool {
	return ( new AttachmentsAccess() )->user_can_see_in_library( $file_id );
}

/**
 * Ensure the protected directory denies direct web access.
 *
 * Returns false when the guard cannot be created, so callers that move files
 * into the directory can refuse to leave them web-reachable.
 *
 * @since   1.6.0
 * @version 1.6.0
 *
 * @return bool True if the guard is in place, false otherwise.
 */
function rmfa_ensure_protected_dir_htaccess(): bool {
	$protected_dir = wp_upload_dir()['basedir'] . '/' . RESTRICT_MEDIA_FILE_ACCESS_PROTECTED_DIR;
	$htaccess_path = $protected_dir . '/.htaccess';

	// Native stat on purpose: this runs on every request, and WP_Filesystem
	// writes a temp probe file when FS_METHOD is unset.
	if ( file_exists( $htaccess_path ) ) {
		return true;
	}

	if ( ! wp_mkdir_p( $protected_dir ) ) {
		rmfa_log_error( 'rmfa_ensure_protected_dir_htaccess_mkdir_failed:' . $protected_dir );
		return false;
	}

	$contents = '<IfModule mod_authz_core.c>' . "\n"
		. 'Require all denied' . "\n"
		. '</IfModule>' . "\n"
		. '<IfModule !mod_authz_core.c>' . "\n"
		. 'Order allow,deny' . "\n"
		. 'Deny from all' . "\n"
		. '</IfModule>' . "\n";

	if ( false === Filesystem::put_contents( $htaccess_path, $contents ) ) {
		rmfa_log_error( 'rmfa_ensure_protected_dir_htaccess_write_failed:' . $htaccess_path );
		return false;
	}

	return true;
}
