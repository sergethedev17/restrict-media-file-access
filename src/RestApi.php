<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\RestrictMediaFileAccess;

use WP_Error;
use WP_Post;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * REST API endpoints for restricting media files.
 *
 * @since   1.0.0
 * @version 1.6.0
 */
class RestApi {

	/**
	 * REST API namespace.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var string
	 */
	private const REST_NAMESPACE = 'restrict-media-file-access/v1';

	/**
	 * The denied responses accepted by the access endpoint.
	 *
	 * `default` clears the per-file mode, so the site default applies.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @var array<string>
	 */
	private const ACCESS_DENY_MODES = array( 'default', ...AttachmentsAccess::DENY_MODES );

	// region MAGIC METHODS

	/**
	 * Initialize the REST API endpoints.
	 *
	 * @since   1.0.0
	 * @version 1.6.0
	 *
	 * @return void
	 */
	public function initialize(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_attachment_query', array( $this, 'filter_attachment_query' ), 10, 1 );
		add_filter( 'rest_prepare_attachment', array( $this, 'filter_rest_prepare_attachment' ), 10, 3 );
	}

	/**
	 * Filter the attachment query.
	 *
	 * @since   1.0.0
	 * @version 1.6.0
	 *
	 * @param array<string, mixed> $args The query arguments.
	 *
	 * @return array<string, mixed> The query arguments.
	 */
	public function filter_attachment_query( array $args ): array {
		return apply_filters( 'rmfa_filter_attachment_query', $args );
	}

	/**
	 * Filter the REST API response for individual media.
	 *
	 * Only restricted files are gated, so public attachment records stay
	 * readable by anonymous consumers. Only reads are gated: a write that
	 * already passed the controller's own capability check must not be turned
	 * into a 403 by this filter. `HEAD` is a read too — WordPress routes it to
	 * the `GET` handler while the method on the request stays `HEAD`, so
	 * leaving it out would answer 200 and disclose the record.
	 *
	 * @since   1.0.0
	 * @version 1.6.0
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param WP_Post          $media    The media post object.
	 * @param WP_REST_Request  $request  The request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response The response object.
	 */
	public function filter_rest_prepare_attachment( WP_REST_Response $response, WP_Post $media, WP_REST_Request $request ) {
		if ( ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) ) {
			return $response;
		}

		$access = new AttachmentsAccess();

		if ( rmfa_is_media_restricted( $media->ID ) && ! $access->user_can_access( $media->ID ) ) {
			return new WP_REST_Response( array(), 403 );
		}

		return $response;
	}

	// endregion

	// region METHODS

	/**
	 * Register REST API routes.
	 *
	 * @since   1.0.0
	 * @version 1.6.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/media/(?P<file_id>\d+)/restrict',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'restrict_file' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'file_id'     => array(
						'required'          => true,
						'validate_callback' => array( $this, 'validate_file_id' ),
						'sanitize_callback' => 'absint',
					),
					'restrict'    => array(
						'required'          => false,
						'default'           => true,
						'validate_callback' => 'rest_is_boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'update_post' => array(
						'required'          => false,
						'default'           => true,
						'validate_callback' => 'rest_is_boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/media/(?P<file_id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_file_status' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'file_id' => array(
						'required'          => true,
						'validate_callback' => array( $this, 'validate_file_id' ),
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/media/(?P<file_id>\d+)/access',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'set_file_access' ),
				'permission_callback' => array( $this, 'check_manage_permissions' ),
				'args'                => array(
					'file_id'   => array(
						'required'          => true,
						'validate_callback' => array( $this, 'validate_file_id' ),
						'sanitize_callback' => 'absint',
					),
					'users'     => array(
						'required'          => true,
						'type'              => 'array',
						'items'             => array( 'type' => 'integer' ),
						'sanitize_callback' => array( $this, 'sanitize_user_ids' ),
					),
					'roles'     => array(
						'required'          => true,
						'type'              => 'array',
						'items'             => array( 'type' => 'string' ),
						'sanitize_callback' => array( $this, 'sanitize_role_slugs' ),
					),
					'deny_mode' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => self::ACCESS_DENY_MODES,
					),
				),
			)
		);
	}

	/**
	 * Restrict or unrestrict a file.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array{file_id: int, restrict?: bool, update_post?: bool}> $request
	 *
	 * @return WP_REST_Response|WP_Error The response object.
	 */
	public function restrict_file( WP_REST_Request $request ) {
		$file_id     = (int) $request->get_param( 'file_id' );
		$restrict    = (bool) $request->get_param( 'restrict' );
		$update_post = (bool) $request->get_param( 'update_post' );

		// Check if the file exists and is an attachment
		$attachment = get_post( $file_id );
		if ( null === $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'rmfa_invalid_file',
				__( 'The specified file does not exist or is not a valid attachment.', 'restrict-media-file-access' ),
				array( 'status' => 404 )
			);
		}

		// Check if the file has an attached file
		$attached_file = get_attached_file( $file_id );
		if ( false === $attached_file || ! file_exists( $attached_file ) ) {
			return new WP_Error(
				'rmfa_file_not_found',
				__( 'The attached file does not exist on the server.', 'restrict-media-file-access' ),
				array( 'status' => 404 )
			);
		}

		$is_disabled = apply_filters( 'rmfa_restricted_file_is_disabled', false, $attachment );

		if ( $is_disabled ) {
			return new WP_Error(
				'rmfa_file_restrictions_disabled',
				__( 'The file cannot be restricted because it has restrictions disabled.', 'restrict-media-file-access' ),
				array( 'status' => 403 )
			);
		}

		try {
			if ( $restrict ) {
				return $this->set_file_as_protected( $file_id, $update_post );
			}

			return $this->set_file_as_unprotected( $file_id, $update_post );
		} catch ( Exception $e ) {
			rmfa_log_error( 'Error restricting file ' . $file_id . ' REST API: ' . $e->getMessage() );
			return new WP_Error(
				'rmfa_restriction_error',
				__( 'An error occurred while processing the file restriction.', 'restrict-media-file-access' ),
				array(
					'status' => 500,
				)
			);
		}
	}

	/**
	 * Get the status of a file.
	 *
	 * @since   1.0.0
	 * @version 1.6.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array{file_id: int}> $request
	 *
	 * @return WP_REST_Response|WP_Error The response object.
	 */
	public function get_file_status( WP_REST_Request $request ) {
		$file_id = (int) $request->get_param( 'file_id' );

		// Check if the file exists and is an attachment
		$attachment = get_post( $file_id );
		if ( null === $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'rmfa_invalid_file',
				__( 'The specified file does not exist or is not a valid attachment.', 'restrict-media-file-access' ),
				array( 'status' => 404 )
			);
		}

		$access        = new AttachmentsAccess();
		$is_restricted = rmfa_is_media_restricted( $file_id );
		$file_url      = wp_get_attachment_url( $file_id );
		$file_path     = get_attached_file( $file_id );
		$file_exists   = false !== $file_path && file_exists( $file_path );
		$filename      = is_string( $file_path ) ? basename( $file_path ) : '';

		$data = array(
			'file_id'       => $file_id,
			'is_restricted' => $is_restricted,
			'url'           => $file_url,
			'exists'        => $file_exists,
			'filename'      => $filename,
			'mime_type'     => get_post_mime_type( $file_id ),
			'file_size'     => $file_exists ? filesize( $file_path ) : 0,
			'upload_date'   => get_the_date( 'c', $file_id ),
			'modified_date' => get_the_modified_date( 'c', $file_id ),
		);

		// Who may read the file is only disclosed to the users who may change it.
		if ( current_user_can( rmfa_manage_file_access_capability() ) ) {
			$data['access'] = array(
				'users'               => AttachmentsAccess::get_access_users( $file_id ),
				'roles'               => AttachmentsAccess::get_access_roles( $file_id ),
				'deny_mode'           => (string) get_post_meta( $file_id, AttachmentsAccess::META_DENY_MODE, true ),
				'deny_mode_effective' => $access->get_deny_mode( $file_id ),
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			200
		);
	}

	/**
	 * Save the access rules of a file.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array{file_id: int, users: array<int>, roles: array<string>, deny_mode: string}> $request
	 *
	 * @return WP_REST_Response|WP_Error The response object.
	 */
	public function set_file_access( WP_REST_Request $request ) {
		$file_id   = (int) $request->get_param( 'file_id' );
		$users     = $this->sanitize_user_ids( $request->get_param( 'users' ) );
		$roles     = $this->sanitize_role_slugs( $request->get_param( 'roles' ) );
		$deny_mode = (string) $request->get_param( 'deny_mode' );

		$attachment = get_post( $file_id );
		if ( null === $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'rmfa_invalid_file',
				__( 'The specified file does not exist or is not a valid attachment.', 'restrict-media-file-access' ),
				array( 'status' => 404 )
			);
		}

		foreach ( $users as $user_id ) {
			if ( false === get_userdata( $user_id ) ) {
				return new WP_Error(
					'rmfa_invalid_user',
					/* translators: %d: user ID. */
					sprintf( __( 'The user %d does not exist.', 'restrict-media-file-access' ), $user_id ),
					array( 'status' => 400 )
				);
			}
		}

		$valid_roles = wp_roles()->roles;

		foreach ( $roles as $role ) {
			if ( ! isset( $valid_roles[ $role ] ) ) {
				return new WP_Error(
					'rmfa_invalid_role',
					/* translators: %s: role slug. */
					sprintf( __( 'The role %s does not exist.', 'restrict-media-file-access' ), $role ),
					array( 'status' => 400 )
				);
			}
		}

		if ( ! in_array( $deny_mode, self::ACCESS_DENY_MODES, true ) ) {
			return new WP_Error(
				'rmfa_invalid_deny_mode',
				__( 'The denied response must be one of default, pixel, 404 or 403.', 'restrict-media-file-access' ),
				array( 'status' => 400 )
			);
		}

		$stored_mode = 'default' === $deny_mode ? '' : $deny_mode;

		AttachmentsAccess::set_access( $file_id, $users, $roles, $stored_mode );

		$access = new AttachmentsAccess();
		$access->flush_cache();

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'file_id'             => $file_id,
					'users'               => $users,
					'roles'               => $roles,
					'deny_mode'           => $stored_mode,
					'deny_mode_effective' => $access->get_deny_mode( $file_id ),
				),
			),
			200
		);
	}

	// endregion

	// region VALIDATION METHODS

	/**
	 * Check if the user has permission to access the REST API.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array{file_id: int}> $request
	 *
	 * @return bool True if the user has permission, false otherwise.
	 */
	public function check_permissions( WP_REST_Request $request ): bool {
		$file_id = (int) $request->get_param( 'file_id' );

		return current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $file_id );
	}

	/**
	 * Check if the user has permission to manage the access rules of a file.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @return bool True if the user has permission, false otherwise.
	 */
	public function check_manage_permissions(): bool {
		return current_user_can( rmfa_manage_file_access_capability() );
	}

	/**
	 * Validate file ID parameter.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param mixed $value The value to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public function validate_file_id( $value ): bool {
		return is_numeric( $value ) && $value > 0;
	}

	/**
	 * Sanitize a list of user IDs.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param mixed $value The value to sanitize.
	 *
	 * @return array<int> The sanitized user IDs.
	 */
	public function sanitize_user_ids( $value ): array {
		return array_map( 'absint', (array) $value );
	}

	/**
	 * Sanitize a list of role slugs.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param mixed $value The value to sanitize.
	 *
	 * @return array<string> The sanitized role slugs.
	 */
	public function sanitize_role_slugs( $value ): array {
		return array_map( 'sanitize_key', (array) $value );
	}

	/**
	 * Set the API restricted data.
	 *
	 * @param integer $file_id The file ID.
	 * @param boolean $restrict Whether the file is restricted.
	 * @param boolean $update_post Whether to update the post.
	 *
	 * @return void
	 */
	private function set_api_restricted_data( $file_id, $restrict, $update_post ): void {
		$api_restricted_data = array(
			'restrict'    => $restrict,
			'user_id'     => get_current_user_id(),
			'date'        => current_time( 'mysql' ),
			'update_post' => $update_post,
		);
		update_post_meta( $file_id, '_rmfa_api_restricted', $api_restricted_data );
	}

	/**
	 * Set the file as protected.
	 *
	 * @param integer $file_id The file ID.
	 * @param boolean $update_post Whether to update the post.
	 *
	 * @return WP_REST_Response The response object.
	 */
	private function set_file_as_protected( int $file_id, bool $update_post ): WP_REST_Response {
		$result = rmfa_set_file_as_protected( $file_id, array( 'update_post' => $update_post ) );

		if ( false === $result ) {
			$message = __( 'File is already restricted.', 'restrict-media-file-access' );

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => $message,
					'status'  => 'no_change',
					'data'    => array(
						'file_id'  => $file_id,
						'restrict' => true,
					),
				),
				200
			);
		}

		$message = __( 'File has been successfully restricted.', 'restrict-media-file-access' );

		do_action( 'rmfa_attachment_restrictions_updated_on_save', $result, $file_id );

		$this->set_api_restricted_data( $file_id, true, $update_post );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => $message,
				'status'  => $result,
				'data'    => array(
					'file_id'  => $file_id,
					'restrict' => true,
					'url'      => wp_get_attachment_url( $file_id ),
				),
			),
			200
		);
	}

	/**
	 * Set the file as unrestricted.
	 *
	 * @param integer $file_id The file ID.
	 * @param boolean $update_post Whether to update the post.
	 *
	 * @return WP_REST_Response The response object.
	 */
	private function set_file_as_unprotected( int $file_id, bool $update_post ): WP_REST_Response {
		$result = rmfa_set_file_as_unprotected( $file_id, array( 'update_post' => $update_post ) );

		if ( false === $result ) {
			$message = __( 'File is already unrestricted.', 'restrict-media-file-access' );

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => $message,
					'status'  => 'no_change',
					'data'    => array(
						'file_id'  => $file_id,
						'restrict' => false,
					),
				),
				200
			);
		}

		$message = __( 'File has been successfully unrestricted.', 'restrict-media-file-access' );

		do_action( 'rmfa_attachment_restrictions_updated_on_save', $result, $file_id );

		$this->set_api_restricted_data( $file_id, false, $update_post );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => $message,
				'status'  => $result,
				'data'    => array(
					'file_id'  => $file_id,
					'restrict' => false,
					'url'      => wp_get_attachment_url( $file_id ),
				),
			),
			200
		);
	}

	// endregion
}
