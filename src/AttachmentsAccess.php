<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\RestrictMediaFileAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Per-file access rules.
 *
 * Answers whether the current user may read a restricted file, resolves the
 * response to send when access is denied, and stores the per-file allow-lists.
 *
 * @since   1.6.0
 * @version 1.6.0
 */
class AttachmentsAccess {

	/**
	 * Post meta key holding the IDs of the users allowed to read the file.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @var string
	 */
	public const META_USERS = '_rmfa_access_users';

	/**
	 * Post meta key holding the roles allowed to read the file.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @var string
	 */
	public const META_ROLES = '_rmfa_access_roles';

	/**
	 * Post meta key holding the per-file denied response.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @var string
	 */
	public const META_DENY_MODE = '_rmfa_deny_mode';

	/**
	 * The supported denied responses.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @var array<string>
	 */
	public const DENY_MODES = array( 'pixel', '404', '403' );

	/**
	 * Initialize the service.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @return void
	 */
	public function initialize(): void {
		add_filter( 'ajax_query_attachments_args', array( $this, 'filter_query_args' ), 10, 1 );
		add_filter( 'rest_attachment_query', array( $this, 'filter_query_args' ), 10, 1 );
		add_action( 'pre_get_posts', array( $this, 'filter_media_library_query' ), 10, 1 );

		add_action( 'added_post_meta', array( $this, 'maybe_flush_cache' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'maybe_flush_cache' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'maybe_flush_cache' ), 10, 3 );

		// The denied list depends on the current user's roles, so a role change
		// must invalidate it too.
		add_action( 'set_user_role', array( $this, 'flush_cache' ) );
		add_action( 'add_user_role', array( $this, 'flush_cache' ) );
		add_action( 'remove_user_role', array( $this, 'flush_cache' ) );
	}

	/**
	 * Check whether the current user may read the file.
	 *
	 * Rule order: unrestricted files keep the logged-in gate, then the admin
	 * bypass, the uploader bypass, the user allow-list and the role allow-list.
	 * An empty allow-list denies everyone but administrators and the uploader.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param int $attachment_id The attachment ID.
	 *
	 * @return bool True if the current user may read the file, false otherwise.
	 */
	public function user_can_access( int $attachment_id ): bool {
		$allowed = $this->is_allowed_by_rules( $attachment_id );

		/**
		 * Filter whether the current user may read the file.
		 *
		 * @since   1.6.0
		 * @version 1.6.0
		 *
		 * @param bool $allowed       Whether the current user may read the file.
		 * @param int  $attachment_id The attachment ID.
		 *
		 * @return bool Whether the current user may read the file.
		 */
		return (bool) apply_filters( 'rmfa_file_access_user_can_access', $allowed, $attachment_id );
	}

	/**
	 * Apply the access rules to the current user.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param int $attachment_id The attachment ID.
	 *
	 * @return bool True if a rule allows the current user, false otherwise.
	 */
	private function is_allowed_by_rules( int $attachment_id ): bool {
		if ( ! rmfa_is_media_restricted( $attachment_id ) ) {
			return is_user_logged_in();
		}

		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( (bool) apply_filters( 'rmfa_file_access_admin_bypass', current_user_can( 'manage_options' ), $attachment_id ) ) {
			return true;
		}

		if ( get_current_user_id() === (int) get_post_field( 'post_author', $attachment_id ) ) {
			return true;
		}

		if ( in_array( get_current_user_id(), self::get_access_users( $attachment_id ), true ) ) {
			return true;
		}

		return array() !== array_intersect( (array) wp_get_current_user()->roles, self::get_access_roles( $attachment_id ) );
	}

	/**
	 * Check whether the current user may see the file in a media listing.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param int $attachment_id The attachment ID.
	 *
	 * @return bool True if the current user may see the file in a listing, false otherwise.
	 */
	public function user_can_see_in_library( int $attachment_id ): bool {
		$allowed = $this->user_can_access( $attachment_id );

		/**
		 * Filter whether the current user may see the file in a media listing.
		 *
		 * @since   1.6.0
		 * @version 1.6.0
		 *
		 * @param bool $allowed       Whether the current user may see the file in a listing.
		 * @param int  $attachment_id The attachment ID.
		 *
		 * @return bool Whether the current user may see the file in a listing.
		 */
		return (bool) apply_filters( 'rmfa_file_access_user_can_see_in_library', $allowed, $attachment_id );
	}

	/**
	 * Get the response to send when access to the file is denied.
	 *
	 * The per-file mode wins, then the site default, then `404`.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param int $attachment_id The attachment ID.
	 *
	 * @return string One of the DENY_MODES values.
	 */
	public function get_deny_mode( int $attachment_id ): string {
		$mode = (string) get_post_meta( $attachment_id, self::META_DENY_MODE, true );

		if ( ! in_array( $mode, self::DENY_MODES, true ) ) {
			$mode = (string) get_option( 'rmfa_default_deny_mode', '404' );
		}

		if ( ! in_array( $mode, self::DENY_MODES, true ) ) {
			$mode = '404';
		}

		/**
		 * Filter the response to send when access to the file is denied.
		 *
		 * @since   1.6.0
		 * @version 1.6.0
		 *
		 * @param string $mode          One of the DENY_MODES values.
		 * @param int    $attachment_id The attachment ID.
		 *
		 * @return string One of the DENY_MODES values.
		 */
		return (string) apply_filters( 'rmfa_file_deny_mode', $mode, $attachment_id );
	}

	/**
	 * Get the restricted attachments the current user may not see.
	 *
	 * The list is cached per user and per cache version, because it depends on
	 * the current user's roles and on the per-file allow-lists.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @return array<int> The denied attachment IDs.
	 */
	public function denied_attachment_ids(): array {
		$version   = (int) get_option( 'rmfa_access_cache_version', 1 );
		$cache_key = 'rmfa_denied_ids_' . get_current_user_id() . '_' . $version;
		$cached    = rmfa_get_cache( $cache_key );

		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}

		$restricted_ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_key'       => '_restricted_file',
				'meta_value'     => '1',
			)
		);

		$denied = array();

		foreach ( $restricted_ids as $attachment_id ) {
			if ( ! $this->user_can_see_in_library( (int) $attachment_id ) ) {
				$denied[] = (int) $attachment_id;
			}
		}

		rmfa_set_cache( $cache_key, $denied, '', HOUR_IN_SECONDS );

		return $denied;
	}

	/**
	 * Flush the cached access decisions.
	 *
	 * Bumping the version makes every per-user key stale at once.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		update_option( 'rmfa_access_cache_version', (int) get_option( 'rmfa_access_cache_version', 1 ) + 1 );
	}

	/**
	 * Flush the cached access decisions when an access rule changes.
	 *
	 * `deleted_post_meta` passes the deleted meta IDs as an array, while
	 * `added_post_meta` and `updated_post_meta` pass a single ID.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param array<int>|int $meta_ids  The meta ID, or the deleted meta IDs.
	 * @param int            $object_id The attachment (post) ID.
	 * @param string         $meta_key  The meta key.
	 *
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
	 */
	public function maybe_flush_cache(
		array|int $meta_ids,
		int $object_id,
		string $meta_key
	): void {
		$access_keys = array(
			self::META_USERS,
			self::META_ROLES,
			self::META_DENY_MODE,
			'_restricted_file',
		);

		if ( ! in_array( $meta_key, $access_keys, true ) ) {
			return;
		}

		$this->flush_cache();
	}

	/**
	 * Add the denied attachments to a media-listing query.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param array<string, mixed> $args The query arguments.
	 *
	 * @return array<string, mixed> The query arguments.
	 */
	public function filter_query_args( array $args ): array {
		$denied = $this->denied_attachment_ids();

		if ( array() === $denied ) {
			return $args;
		}

		$existing             = (array) ( $args['post__not_in'] ?? array() );
		$args['post__not_in'] = $this->merge_denied_ids( $existing, $denied );

		return $args;
	}

	/**
	 * Exclude the denied attachments from the classic Media Library grid.
	 *
	 * Only the admin main attachment query is touched; every other query,
	 * including the ones the plugin runs itself, is left alone.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param \WP_Query $query The query.
	 *
	 * @return void
	 */
	public function filter_media_library_query( \WP_Query $query ): void {
		$is_attachment_query = 'attachment' === $query->get( 'post_type' );

		if ( ! is_admin() || ! $query->is_main_query() || ! $is_attachment_query ) {
			return;
		}

		$denied = $this->denied_attachment_ids();

		if ( array() === $denied ) {
			return;
		}

		$existing = (array) $query->get( 'post__not_in' );

		$query->set( 'post__not_in', $this->merge_denied_ids( $existing, $denied ) );
	}

	/**
	 * Merge the denied IDs into an existing exclusion list.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param array<int> $existing The IDs already excluded.
	 * @param array<int> $denied   The IDs to exclude.
	 *
	 * @return array<int> The merged exclusion list.
	 */
	private function merge_denied_ids( array $existing, array $denied ): array {
		$merged = array_merge( array_map( 'intval', $existing ), $denied );

		return array_values( array_unique( $merged ) );
	}

	/**
	 * Get the IDs of the users allowed to read the file.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param int $attachment_id The attachment ID.
	 *
	 * @return array<int> The allowed user IDs.
	 */
	public static function get_access_users( int $attachment_id ): array {
		$user_ids = array_map( 'absint', (array) get_post_meta( $attachment_id, self::META_USERS, true ) );

		return array_values(
			array_filter(
				$user_ids,
				static function ( int $user_id ): bool {
					return 0 !== $user_id;
				}
			)
		);
	}

	/**
	 * Get the roles allowed to read the file.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param int $attachment_id The attachment ID.
	 *
	 * @return array<string> The allowed role slugs.
	 */
	public static function get_access_roles( int $attachment_id ): array {
		$role_slugs = array_map( 'sanitize_key', (array) get_post_meta( $attachment_id, self::META_ROLES, true ) );

		return array_values(
			array_filter(
				$role_slugs,
				static function ( string $role ): bool {
					return '' !== $role;
				}
			)
		);
	}

	/**
	 * Store the access rules of the file.
	 *
	 * An empty deny mode removes the per-file mode, so the site default applies.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param int           $attachment_id The attachment ID.
	 * @param array<int>    $users         The IDs of the users allowed to read the file.
	 * @param array<string> $roles         The roles allowed to read the file.
	 * @param string        $deny_mode     The denied response, or an empty string for the site default.
	 *
	 * @return bool True always.
	 */
	public static function set_access( int $attachment_id, array $users, array $roles, string $deny_mode ): bool {
		$valid_roles = wp_roles()->roles;
		$user_ids    = array_values(
			array_filter(
				array_map( 'absint', $users ),
				static function ( int $user_id ): bool {
					return 0 !== $user_id;
				}
			)
		);
		$role_slugs  = array_values(
			array_filter(
				array_map( 'sanitize_key', $roles ),
				static function ( string $role ) use ( $valid_roles ): bool {
					return isset( $valid_roles[ $role ] );
				}
			)
		);
		$mode        = sanitize_key( $deny_mode );

		update_post_meta( $attachment_id, self::META_USERS, $user_ids );
		update_post_meta( $attachment_id, self::META_ROLES, $role_slugs );

		if ( in_array( $mode, self::DENY_MODES, true ) ) {
			update_post_meta( $attachment_id, self::META_DENY_MODE, $mode );

			return true;
		}

		delete_post_meta( $attachment_id, self::META_DENY_MODE );

		return true;
	}
}
