<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\RestrictMediaFileAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Admin editing surface for the per-file access lists.
 *
 * Renders the "File access" meta box on the attachment edit screen, stores the
 * submitted lists, and answers the AJAX user search that feeds the user chips.
 *
 * @since   1.6.0
 * @version 1.6.0
 */
class AttachmentsAccessAdmin {

	/**
	 * The nonce action shared by the meta box, the save handler and the search.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'rmfa_file_access';

	/**
	 * The nonce field name used by the meta box form.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @var string
	 */
	public const NONCE_NAME = 'rmfa_file_access_nonce';

	/**
	 * Initialize the admin hooks.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @return void
	 */
	public function initialize(): void {
		add_action( 'add_meta_boxes_attachment', array( $this, 'register_meta_box' ) );
		// Attachments do not fire `save_post`: wp_insert_post() takes an
		// attachment branch that fires `edit_attachment` and returns before
		// `save_post`. Hook the attachment-specific action instead.
		add_action( 'edit_attachment', array( $this, 'save_meta_box' ) );
		add_action( 'wp_ajax_rmfa_search_users', array( $this, 'search_users' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the "File access" meta box on the attachment edit screen.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @return void
	 */
	public function register_meta_box(): void {
		if ( ! current_user_can( rmfa_manage_file_access_capability() ) ) {
			return;
		}

		add_meta_box(
			'rmfa_file_access',
			__( 'File access', 'restrict-media-file-access' ),
			array( $this, 'render_meta_box' ),
			'attachment',
			'side',
			'default'
		);
	}

	/**
	 * Render the "File access" meta box.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param \WP_Post $post The attachment being edited.
	 *
	 * @return void
	 */
	public function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		if ( ! rmfa_is_media_restricted( $post->ID ) ) {
			printf(
				'<p class="rmfa-access-notice">%s</p>',
				esc_html__( 'This file is not restricted yet, so these lists only apply once it is.', 'restrict-media-file-access' )
			);
		}

		$this->render_users_field( $post->ID );
		$this->render_roles_field( $post->ID );
		$this->render_deny_mode_field( $post->ID );
	}

	/**
	 * Store the submitted access lists of an attachment.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param int $post_id The attachment ID.
	 *
	 * @return void
	 */
	public function save_meta_box( int $post_id ): void {
		if ( false !== wp_is_post_autosave( $post_id ) || false !== wp_is_post_revision( $post_id ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ) : '';

		if ( false === wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( rmfa_manage_file_access_capability() ) ) {
			return;
		}

		$users = array_map( 'absint', $this->submitted_list( 'rmfa_access_users' ) );
		$roles = array_map( 'sanitize_key', $this->submitted_list( 'rmfa_access_roles' ) );
		$mode  = $this->submitted_list( 'rmfa_deny_mode' );
		$mode  = isset( $mode[0] ) ? sanitize_key( (string) $mode[0] ) : 'default';

		if ( 'default' === $mode || ! in_array( $mode, AttachmentsAccess::DENY_MODES, true ) ) {
			$mode = '';
		}

		AttachmentsAccess::set_access( $post_id, $users, $roles, $mode );

		( new AttachmentsAccess() )->flush_cache();
	}

	/**
	 * Answer the AJAX user search used by the user chips.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @return void
	 */
	public function search_users(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( rmfa_manage_file_access_capability() ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to search users.', 'restrict-media-file-access' ) ),
				403
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_ajax_referer() above.
		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';

		$users = get_users(
			array(
				'search' => '*' . $term . '*',
				'number' => 20,
				'fields' => array( 'ID', 'display_name', 'user_login' ),
			)
		);

		$results = array();

		foreach ( $users as $user ) {
			$results[] = array(
				'id'    => (int) $user->ID,
				'name'  => (string) $user->display_name,
				'login' => (string) $user->user_login,
			);
		}

		wp_send_json_success( $results );
	}

	/**
	 * Enqueue the meta box assets on the attachment edit screen only.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param string $hook_suffix The current admin page.
	 *
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		if ( ! current_user_can( rmfa_manage_file_access_capability() ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( null === $screen || 'attachment' !== $screen->post_type ) {
			return;
		}

		$script = restrict_media_file_access_get_asset_meta(
			RESTRICT_MEDIA_FILE_ACCESS_DIR_PATH . 'assets/admin/js/file-access.js',
			array( 'jquery', 'wp-util' )
		);

		if ( null !== $script ) {
			wp_enqueue_script(
				'rmfa-file-access',
				RESTRICT_MEDIA_FILE_ACCESS_DIR_URL . 'assets/admin/js/file-access.js',
				$script['dependencies'] ?? array(),
				$script['version'] ?? '1.0.0',
				true
			);

			wp_localize_script(
				'rmfa-file-access',
				'rmfaFileAccess',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
					'i18n'    => array(
						'noResults' => __( 'No users found.', 'restrict-media-file-access' ),
						'remove'    => __( 'Remove', 'restrict-media-file-access' ),
					),
				)
			);
		}

		$style = restrict_media_file_access_get_asset_meta( RESTRICT_MEDIA_FILE_ACCESS_DIR_PATH . 'assets/admin/css/file-access.css' );

		if ( null !== $style ) {
			wp_enqueue_style(
				'rmfa-file-access',
				RESTRICT_MEDIA_FILE_ACCESS_DIR_URL . 'assets/admin/css/file-access.css',
				$style['dependencies'] ?? array(),
				$style['version'] ?? '1.0.0'
			);
		}
	}

	/**
	 * Read one of the submitted list fields as a list of scalars.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param string $field The field name.
	 *
	 * @return array<string> The submitted values.
	 */
	private function submitted_list( string $field ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by save_meta_box() before this runs.
		if ( ! isset( $_POST[ $field ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by save_meta_box() before this runs.
		$values = (array) wp_unslash( $_POST[ $field ] );
		$list   = array();

		foreach ( $values as $value ) {
			if ( is_scalar( $value ) ) {
				$list[] = (string) $value;
			}
		}

		return $list;
	}

	/**
	 * Render the allowed-users field.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param int $post_id The attachment ID.
	 *
	 * @return void
	 */
	private function render_users_field( int $post_id ): void {
		echo '<p class="rmfa-access-label"><strong>' . esc_html__( 'Users with access', 'restrict-media-file-access' ) . '</strong></p>';
		echo '<div class="rmfa-access-users">';
		echo '<ul class="rmfa-access-user-chips">';

		foreach ( AttachmentsAccess::get_access_users( $post_id ) as $user_id ) {
			$user = get_userdata( $user_id );

			if ( false === $user ) {
				continue;
			}

			printf(
				'<li class="rmfa-access-user-chip" data-user-id="%1$s">' .
					'<input type="hidden" name="rmfa_access_users[]" value="%1$s" />' .
					'<span class="rmfa-access-user-name">%2$s</span>' .
					'<button type="button" class="rmfa-access-user-remove" aria-label="%3$s">&times;</button>' .
				'</li>',
				esc_attr( (string) $user_id ),
				esc_html( $user->display_name ),
				esc_attr(
					sprintf(
						/* translators: %s: user display name. */
						__( 'Remove %s', 'restrict-media-file-access' ),
						$user->display_name
					)
				)
			);
		}

		echo '</ul>';

		printf(
			'<input type="text" class="rmfa-access-user-search" autocomplete="off" placeholder="%1$s" aria-label="%2$s" aria-controls="rmfa-access-user-results" aria-expanded="false" />',
			esc_attr__( 'Search users…', 'restrict-media-file-access' ),
			esc_attr__( 'Search users', 'restrict-media-file-access' )
		);

		echo '<ul class="rmfa-access-user-results" id="rmfa-access-user-results" aria-live="polite"></ul>';
		echo '</div>';
	}

	/**
	 * Render the allowed-roles field.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param int $post_id The attachment ID.
	 *
	 * @return void
	 */
	private function render_roles_field( int $post_id ): void {
		$assigned = AttachmentsAccess::get_access_roles( $post_id );

		echo '<fieldset class="rmfa-access-roles">';
		echo '<legend><strong>' . esc_html__( 'Roles with access', 'restrict-media-file-access' ) . '</strong></legend>';

		foreach ( wp_roles()->get_names() as $slug => $name ) {
			printf(
				'<label class="rmfa-access-role"><input type="checkbox" name="rmfa_access_roles[]" value="%1$s" %2$s /> %3$s</label>',
				esc_attr( $slug ),
				checked( in_array( $slug, $assigned, true ), true, false ),
				esc_html( translate_user_role( $name ) )
			);
		}

		echo '</fieldset>';
	}

	/**
	 * Render the denied-response field.
	 *
	 * @since   1.6.0
	 * @version 1.6.0
	 *
	 * @param int $post_id The attachment ID.
	 *
	 * @return void
	 */
	private function render_deny_mode_field( int $post_id ): void {
		$current = (string) get_post_meta( $post_id, AttachmentsAccess::META_DENY_MODE, true );

		if ( ! in_array( $current, AttachmentsAccess::DENY_MODES, true ) ) {
			$current = 'default';
		}

		$choices = array(
			'default' => __( 'Site default', 'restrict-media-file-access' ),
			'pixel'   => __( '1×1 transparent image', 'restrict-media-file-access' ),
			'404'     => __( '404 Not Found', 'restrict-media-file-access' ),
			'403'     => __( '403 Forbidden', 'restrict-media-file-access' ),
		);

		echo '<p class="rmfa-access-label"><label for="rmfa_deny_mode"><strong>' . esc_html__( 'Response when denied', 'restrict-media-file-access' ) . '</strong></label></p>';
		echo '<select name="rmfa_deny_mode" id="rmfa_deny_mode">';

		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( $current, (string) $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}
}
