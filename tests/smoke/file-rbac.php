<?php

$GLOBALS['rmfa_failures'] = 0;
$GLOBALS['rmfa_checks']   = 0;

function rmfa_check( string $label, bool $condition ): void {
	$GLOBALS['rmfa_checks']++;
	if ( $condition ) {
		echo "PASS {$label}\n";
		return;
	}
	$GLOBALS['rmfa_failures']++;
	echo "FAIL {$label}\n";
}

function rmfa_make_user( string $login, string $role ): int {
	$user_id = username_exists( $login );
	if ( false === $user_id ) {
		$user_id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => 'password',
				'user_email' => $login . '@example.com',
				'role'       => $role,
			)
		);
	}
	return (int) $user_id;
}

function rmfa_make_attachment( string $filename, string $mime, string $bytes, int $author ): int {
	$upload = wp_upload_dir();
	$path   = $upload['path'] . '/' . $filename;
	file_put_contents( $path, $bytes );
	$id = wp_insert_attachment(
		array(
			'post_mime_type' => $mime,
			'post_title'     => $filename,
			'post_status'    => 'inherit',
			'post_author'    => $author,
		),
		$path
	);
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
	return (int) $id;
}

$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );

add_role( 'rmfa_role', 'RMFA Role', array( 'read' => true ) );

$admin_id    = (int) get_user_by( 'login', 'admin' )->ID;
$owner_id    = rmfa_make_user( 'rmfa_owner', 'author' );
$allowed_id  = rmfa_make_user( 'rmfa_allowed', 'subscriber' );
$unlisted_id = rmfa_make_user( 'rmfa_unlisted', 'subscriber' );
$role_user   = rmfa_make_user( 'rmfa_roleuser', 'rmfa_role' );

$image_id = rmfa_make_attachment( 'rmfa-image.png', 'image/png', $png, $owner_id );
rmfa_set_file_as_protected( $image_id );

$access = new \A8C\SpecialProjects\RestrictMediaFileAccess\AttachmentsAccess();

// Empty lists: admin and owner only.
wp_set_current_user( $admin_id );
rmfa_check( 'empty list allows admin', true === $access->user_can_access( $image_id ) );
wp_set_current_user( $owner_id );
rmfa_check( 'empty list allows owner', true === $access->user_can_access( $image_id ) );
wp_set_current_user( $unlisted_id );
rmfa_check( 'empty list denies unlisted', false === $access->user_can_access( $image_id ) );
wp_set_current_user( 0 );
rmfa_check( 'empty list denies anonymous', false === $access->user_can_access( $image_id ) );

// Lists grant access.
$access::set_access( $image_id, array( $allowed_id ), array( 'rmfa_role' ), '' );
wp_set_current_user( $allowed_id );
rmfa_check( 'listed user allowed', true === $access->user_can_access( $image_id ) );
wp_set_current_user( $role_user );
rmfa_check( 'listed role allowed', true === $access->user_can_access( $image_id ) );
wp_set_current_user( $unlisted_id );
rmfa_check( 'unlisted user denied', false === $access->user_can_access( $image_id ) );
rmfa_check( 'unlisted user cannot see in library', false === $access->user_can_see_in_library( $image_id ) );

// Owner sees the listing and reads the bytes.
wp_set_current_user( $owner_id );
rmfa_check( 'owner can see in library', true === $access->user_can_see_in_library( $image_id ) );

// Unrestricted files keep the login gate.
$public_id = rmfa_make_attachment( 'rmfa-public.png', 'image/png', $png, $owner_id );
wp_set_current_user( $unlisted_id );
rmfa_check( 'public file allows logged in', true === $access->user_can_access( $public_id ) );
wp_set_current_user( 0 );
rmfa_check( 'public file denies anonymous', false === $access->user_can_access( $public_id ) );

// Deny mode resolution.
rmfa_check( 'default deny mode is 404', '404' === $access->get_deny_mode( $public_id ) );
update_post_meta( $image_id, '_rmfa_deny_mode', 'pixel' );
rmfa_check( 'per-file deny mode wins', 'pixel' === $access->get_deny_mode( $image_id ) );
update_post_meta( $image_id, '_rmfa_deny_mode', 'bogus' );
rmfa_check( 'invalid deny mode falls back', '404' === $access->get_deny_mode( $image_id ) );

function rmfa_http( string $path, array $cookies = array() ): array {
	$response = wp_remote_get(
		'http://wordpress' . $path,
		array(
			'cookies'     => $cookies,
			'timeout'     => 20,
			'redirection' => 0,
		)
	);

	// Every header except Date, so two responses can be compared for equality.
	$headers = array();
	$raw     = wp_remote_retrieve_headers( $response );
	if ( is_iterable( $raw ) ) {
		foreach ( $raw as $name => $value ) {
			$name = strtolower( trim( (string) $name ) );
			if ( 'date' === $name ) {
				continue;
			}
			$headers[ $name ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
	}
	ksort( $headers );

	return array(
		'status'  => (int) wp_remote_retrieve_response_code( $response ),
		'type'    => (string) wp_remote_retrieve_header( $response, 'content-type' ),
		'body'    => (string) wp_remote_retrieve_body( $response ),
		'headers' => $headers,
	);
}

function rmfa_login( string $login ): array {
	$jar      = wp_remote_retrieve_cookies(
		wp_remote_get( 'http://wordpress/wp-login.php', array( 'cookies' => array() ) )
	);
	$response = wp_remote_post(
		'http://wordpress/wp-login.php',
		array(
			'cookies'     => $jar,
			'redirection' => 0,
			'body'        => array(
				'log'        => $login,
				'pwd'        => 'password',
				'wp-submit'  => 'Log In',
				'testcookie' => '1',
			),
		)
	);
	return wp_remote_retrieve_cookies( $response );
}

$hash   = rmfa_get_media_protected_file_hash( $image_id );
$path   = '/protected-files/' . $hash;
$pdf_id = rmfa_make_attachment( 'rmfa-doc.pdf', 'application/pdf', "%PDF-1.4\n%%EOF\n", $owner_id );
rmfa_set_file_as_protected( $pdf_id );
$pdf_path = '/protected-files/' . rmfa_get_media_protected_file_hash( $pdf_id );

// Default mode is 404.
$anon = rmfa_http( $path );
rmfa_check( 'anonymous denied with 404 by default', 404 === $anon['status'] );
rmfa_check( '404 body is not the gif', 'image/gif' !== $anon['type'] );
rmfa_check( '404 is not cacheable', false !== stripos( (string) wp_remote_retrieve_header( wp_remote_get( 'http://wordpress' . $path ), 'cache-control' ), 'no-store' ) );

// 403 mode.
update_post_meta( $image_id, '_rmfa_deny_mode', '403' );
rmfa_check( 'anonymous denied with 403 when configured', 403 === rmfa_http( $path )['status'] );

// Pixel mode.
update_post_meta( $image_id, '_rmfa_deny_mode', 'pixel' );
$pixel = rmfa_http( $path );
rmfa_check( 'pixel mode returns gif', 200 === $pixel['status'] && false !== strpos( $pixel['type'], 'image/gif' ) );

// Pixel mode on a non-image falls back to 404...
update_post_meta( $pdf_id, '_rmfa_deny_mode', 'pixel' );
rmfa_check( 'pixel mode on a pdf returns 404', 404 === rmfa_http( $pdf_path )['status'] );

// ...unless an integration supplies its own placeholder. Filters are
// per-request state, so a filter registered here is invisible to the web
// request that serves the file — assert the protector's own check instead.
$rmfa_custom_headers = static function ( array $headers ): array {
	$headers['Content-Type'] = 'application/pdf';
	return $headers;
};

$rmfa_protector                 = new \A8C\SpecialProjects\RestrictMediaFileAccess\AttachmentsProtector();
$rmfa_placeholder_filters_check = method_exists( $rmfa_protector, 'has_placeholder_filters' )
	? new ReflectionMethod( $rmfa_protector, 'has_placeholder_filters' )
	: null;
rmfa_check( 'no placeholder filter by default', null !== $rmfa_placeholder_filters_check && false === $rmfa_placeholder_filters_check->invoke( $rmfa_protector ) );
add_filter( 'restrict_media_file_access_protected_headers', $rmfa_custom_headers );
rmfa_check( 'pixel mode honours a placeholder filter', null !== $rmfa_placeholder_filters_check && true === $rmfa_placeholder_filters_check->invoke( $rmfa_protector ) );
remove_filter( 'restrict_media_file_access_protected_headers', $rmfa_custom_headers );

// Unknown hash is 404 for everyone.
rmfa_check( 'unknown hash 404 anonymous', 404 === rmfa_http( '/protected-files/deadbeef' )['status'] );
$unlisted_cookies = rmfa_login( 'rmfa_unlisted' );
rmfa_check( 'unknown hash 404 logged in', 404 === rmfa_http( '/protected-files/deadbeef', $unlisted_cookies )['status'] );

// Listed user and owner get bytes; unlisted and anonymous do not.
update_post_meta( $image_id, '_rmfa_deny_mode', '404' );
$allowed_cookies = rmfa_login( 'rmfa_allowed' );
$owner_cookies   = rmfa_login( 'rmfa_owner' );
rmfa_check( 'listed user gets bytes', 200 === rmfa_http( $path, $allowed_cookies )['status'] );
rmfa_check( 'owner gets bytes', 200 === rmfa_http( $path, $owner_cookies )['status'] );
rmfa_check( 'unlisted user denied', 200 !== rmfa_http( $path, $unlisted_cookies )['status'] );

// An integration can override the access decision for a single file. Filters
// registered here live in this process only, so the override has to reach the
// request that serves the file: drop a temporary mu-plugin and remove it again.
$mu_plugin = WPMU_PLUGIN_DIR . '/rmfa-protect-file-override.php';
$mu_source = "<?php\n"
	. "add_filter( 'restrict_media_file_access_protect_file', static function ( bool \$protect, string \$file ): bool {\n"
	. "\treturn str_starts_with( \$file, '" . $hash . "' ) ? false : \$protect;\n"
	. "}, 10, 2 );\n";

if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
	wp_mkdir_p( WPMU_PLUGIN_DIR );
}

if ( file_exists( $mu_plugin ) ) {
	unlink( $mu_plugin );
}

file_put_contents( $mu_plugin, $mu_source );
rmfa_check( 'protect_file override serves bytes to an unlisted user', 200 === rmfa_http( $path, $unlisted_cookies )['status'] );
unlink( $mu_plugin );
rmfa_check( 'protect_file override removed', 200 !== rmfa_http( $path, $unlisted_cookies )['status'] );

// The site-wide setting drives files with no per-file mode.
delete_post_meta( $pdf_id, '_rmfa_deny_mode' );
update_option( 'rmfa_default_deny_mode', '403' );
rmfa_check( 'site default drives the denied response', 403 === rmfa_http( $pdf_path )['status'] );
delete_option( 'rmfa_default_deny_mode' );

// Setting is registered with the 404 default.
rmfa_check( 'deny-mode option registered', '404' === get_option( 'rmfa_default_deny_mode', '404' ) );

$rmfa_settings = new \A8C\SpecialProjects\RestrictMediaFileAccess\Settings();
rmfa_check( 'invalid deny mode sanitizes to 404', '404' === $rmfa_settings->sanitize_default_deny_mode( 'nope' ) );
rmfa_check( 'valid deny mode sanitizes through', '403' === $rmfa_settings->sanitize_default_deny_mode( '403' ) );

// Media listings hide what the current user may not see.
function rmfa_rest_media_ids( int $user_id ): array {
	wp_set_current_user( $user_id );
	$request = new WP_REST_Request( 'GET', '/wp/v2/media' );
	$request->set_query_params( array( 'per_page' => 100 ) );
	$data = rest_do_request( $request )->get_data();
	return is_array( $data ) ? array_map( 'intval', wp_list_pluck( $data, 'id' ) ) : array();
}

// The denied list is cached per user, so drop any list cached by a previous run.
$access->flush_cache();

$admin_ids    = rmfa_rest_media_ids( $admin_id );
$unlisted_ids = rmfa_rest_media_ids( $unlisted_id );
$owner_ids    = rmfa_rest_media_ids( $owner_id );

rmfa_check( 'admin sees restricted media', in_array( $image_id, $admin_ids, true ) );
rmfa_check( 'unlisted user does not see restricted media', ! in_array( $image_id, $unlisted_ids, true ) );
rmfa_check( 'owner sees own restricted media', in_array( $image_id, $owner_ids, true ) );
rmfa_check( 'unlisted user sees public media', in_array( $public_id, $unlisted_ids, true ) );

// The modal and classic-library queries run through the same rule.
wp_set_current_user( $unlisted_id );
$modal_args = apply_filters( 'ajax_query_attachments_args', array() );
rmfa_check( 'modal query excludes restricted media', in_array( $image_id, (array) ( $modal_args['post__not_in'] ?? array() ), true ) );
$rest_args = apply_filters( 'rest_attachment_query', array() );
rmfa_check( 'rest query args exclude restricted media', in_array( $image_id, (array) ( $rest_args['post__not_in'] ?? array() ), true ) );

// The cache version is a counter, not a timestamp: two flushes inside the same
// second must still invalidate the cached list.
$version_before = (int) get_option( 'rmfa_access_cache_version', 1 );
$access->flush_cache();
$version_first = (int) get_option( 'rmfa_access_cache_version', 1 );
$access->flush_cache();
$version_second = (int) get_option( 'rmfa_access_cache_version', 1 );
$versions_rise  = $version_first > $version_before && $version_second > $version_first;
rmfa_check( 'each flush bumps the cache version', $versions_rise );
rmfa_check( 'flushes produce different versions', $version_first !== $version_second );

// The attachment edit screen saves the access lists.
wp_set_current_user( $admin_id );
$_POST['rmfa_file_access_nonce'] = wp_create_nonce( 'rmfa_file_access' );
$_POST['rmfa_access_users']      = array( (string) $unlisted_id );
$_POST['rmfa_access_roles']      = array( 'rmfa_role' );
$_POST['rmfa_deny_mode']         = '403';
$access_admin                    = new \A8C\SpecialProjects\RestrictMediaFileAccess\AttachmentsAccessAdmin();
// Fire the hook WordPress actually fires for attachment updates. The meta box
// is wired to `edit_attachment` because `wp_insert_post()` returns before
// `save_post` for attachments, so `save_post_attachment` never fires.
do_action( 'edit_attachment', $image_id );
rmfa_check( 'meta box save stores users', array( $unlisted_id ) === $access::get_access_users( $image_id ) );
rmfa_check( 'meta box save stores roles', array( 'rmfa_role' ) === $access::get_access_roles( $image_id ) );
rmfa_check( 'meta box save stores deny mode', '403' === $access->get_deny_mode( $image_id ) );

$_POST['rmfa_access_users'] = array();
$_POST['rmfa_access_roles'] = array();
$_POST['rmfa_deny_mode']    = 'default';
do_action( 'edit_attachment', $image_id );
rmfa_check( 'meta box clears lists', array() === $access::get_access_users( $image_id ) );
rmfa_check( 'meta box default clears per-file mode', '' === get_post_meta( $image_id, '_rmfa_deny_mode', true ) );

// A non-manager cannot save, even with a nonce minted for them.
wp_set_current_user( $unlisted_id );
$_POST['rmfa_file_access_nonce'] = wp_create_nonce( 'rmfa_file_access' );
$_POST['rmfa_access_users']      = array( (string) $unlisted_id );
do_action( 'edit_attachment', $image_id );
rmfa_check( 'non-manager cannot save access', array() === $access::get_access_users( $image_id ) );

// The meta box renders the fields.
wp_set_current_user( $admin_id );
ob_start();
$access_admin->render_meta_box( get_post( $image_id ) );
$html = (string) ob_get_clean();
rmfa_check( 'meta box renders user search', false !== strpos( $html, 'rmfa-access-user-search' ) );
rmfa_check( 'meta box renders role checkboxes', false !== strpos( $html, 'rmfa_access_roles' ) );
rmfa_check( 'meta box renders deny modes', false !== strpos( $html, 'rmfa_deny_mode' ) );

// The user search reads the posted term, not the query string.
function rmfa_ajax_search( int $user_id, string $term ): array {
	$expires = time() + HOUR_IN_SECONDS;
	$token   = WP_Session_Tokens::get_instance( $user_id )->create( $expires );
	$cookie  = new WP_Http_Cookie(
		array(
			'name'   => LOGGED_IN_COOKIE,
			'value'  => wp_generate_auth_cookie( $user_id, $expires, 'logged_in', $token ),
			'domain' => 'wordpress',
			'path'   => '/',
		)
	);

	// The nonce is bound to the user and to the session token in the cookie.
	$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie->value;
	wp_set_current_user( $user_id );
	$nonce = wp_create_nonce( 'rmfa_file_access' );
	unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

	$response = wp_remote_post(
		'http://wordpress/wp-admin/admin-ajax.php',
		array(
			'cookies' => array( $cookie ),
			'timeout' => 20,
			'body'    => array(
				'action' => 'rmfa_search_users',
				'nonce'  => $nonce,
				'term'   => $term,
			),
		)
	);

	WP_Session_Tokens::get_instance( $user_id )->destroy( $token );

	return (array) json_decode( (string) wp_remote_retrieve_body( $response ), true );
}

$rmfa_search = rmfa_ajax_search( $admin_id, 'rmfa_allowed' );
$rmfa_logins = wp_list_pluck( (array) ( $rmfa_search['data'] ?? array() ), 'login' );
rmfa_check( 'search uses the posted term', array( 'rmfa_allowed' ) === $rmfa_logins );

// The REST API saves the access lists for a manager only.
function rmfa_rest_call( int $user_id, string $method, string $path, array $params = array() ): WP_REST_Response {
	wp_set_current_user( $user_id );
	$request = new WP_REST_Request( $method, $path );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
}

$route = '/restrict-media-file-access/v1/media/' . $image_id . '/access';

$denied = rmfa_rest_call(
	$unlisted_id,
	'POST',
	$route,
	array(
		'users'     => array( $unlisted_id ),
		'roles'     => array(),
		'deny_mode' => 'default',
	)
);
rmfa_check( 'non-manager cannot set access over REST', 401 === $denied->get_status() || 403 === $denied->get_status() );

$saved = rmfa_rest_call(
	$admin_id,
	'POST',
	$route,
	array(
		'users'     => array( $allowed_id ),
		'roles'     => array( 'rmfa_role' ),
		'deny_mode' => '403',
	)
);
rmfa_check( 'manager can set access over REST', 200 === $saved->get_status() );
rmfa_check( 'REST saved users', array( $allowed_id ) === $access::get_access_users( $image_id ) );
rmfa_check( 'REST saved roles', array( 'rmfa_role' ) === $access::get_access_roles( $image_id ) );

// The route is a full replacement: a partial POST must be a 400, never a
// silent wipe of the lists it did not mention.
$partial = rmfa_rest_call( $admin_id, 'POST', $route, array( 'deny_mode' => 'pixel' ) );
rmfa_check( 'partial access POST rejected', 400 === $partial->get_status() );
rmfa_check( 'partial access POST keeps the saved users', array( $allowed_id ) === $access::get_access_users( $image_id ) );
rmfa_check( 'partial access POST keeps the saved roles', array( 'rmfa_role' ) === $access::get_access_roles( $image_id ) );
rmfa_check( 'partial access POST keeps the saved deny mode', '403' === $access->get_deny_mode( $image_id ) );

$bad_role = rmfa_rest_call(
	$admin_id,
	'POST',
	$route,
	array(
		'users'     => array(),
		'roles'     => array( 'nope' ),
		'deny_mode' => 'default',
	)
);
rmfa_check( 'unknown role rejected', 400 === $bad_role->get_status() );

$bad_user = rmfa_rest_call(
	$admin_id,
	'POST',
	$route,
	array(
		'users'     => array( 999999 ),
		'roles'     => array(),
		'deny_mode' => 'default',
	)
);
rmfa_check( 'unknown user rejected', 400 === $bad_user->get_status() );

$bad_mode = rmfa_rest_call(
	$admin_id,
	'POST',
	$route,
	array(
		'users'     => array(),
		'roles'     => array(),
		'deny_mode' => 'nope',
	)
);
rmfa_check( 'unknown deny mode rejected', 400 === $bad_mode->get_status() );

// Managing the access lists is its own capability: an author keeps
// upload_files and edit_post on the attachment but must not pass this gate.
$owner_denied = rmfa_rest_call(
	$owner_id,
	'POST',
	$route,
	array(
		'users'     => array(),
		'roles'     => array(),
		'deny_mode' => 'default',
	)
);
rmfa_check( 'file owner cannot set access over REST', 403 === $owner_denied->get_status() );
rmfa_check( 'owner POST left the access lists alone', array( $allowed_id ) === $access::get_access_users( $image_id ) );

$status      = rmfa_rest_call( $admin_id, 'GET', '/restrict-media-file-access/v1/media/' . $image_id . '/status' );
$status_data = $status->get_data();
rmfa_check( 'status exposes access object', isset( $status_data['data']['access']['users'] ) );
rmfa_check( 'status exposes effective mode', '403' === $status_data['data']['access']['deny_mode_effective'] );

// The allow-list is only disclosed to the users who may change it. The owner
// passes the route's own permission check, so this covers the disclosure fix.
$owner_status      = rmfa_rest_call( $owner_id, 'GET', '/restrict-media-file-access/v1/media/' . $image_id . '/status' );
$owner_status_data = $owner_status->get_data();
rmfa_check( 'status readable by a non-manager', 200 === $owner_status->get_status() );
rmfa_check( 'status hides the access lists from a non-manager', ! isset( $owner_status_data['data']['access'] ) );

// Record gate: unlisted user gets 403 for the attachment record.
$record = rmfa_rest_call( $unlisted_id, 'GET', '/wp/v2/media/' . $image_id );
rmfa_check( 'unlisted user gets 403 for record', 403 === $record->get_status() );

// The record gate only applies to restricted files: public records stay readable.
$anon_public = rmfa_rest_call( 0, 'GET', '/wp/v2/media/' . $public_id );
rmfa_check( 'anonymous reads a public record', 200 === $anon_public->get_status() );

$anon_restricted = rmfa_rest_call( 0, 'GET', '/wp/v2/media/' . $image_id );
rmfa_check( 'anonymous gets 403 for a restricted record', 403 === $anon_restricted->get_status() );

// HEAD is a read as well: WordPress serves it from the GET handler while the
// request method stays HEAD, so an ungated HEAD would answer 200 and leak the
// record's existence — the oracle the 403 is there to remove.
$head_restricted = rmfa_rest_call( $unlisted_id, 'HEAD', '/wp/v2/media/' . $image_id );
rmfa_check( 'HEAD on a restricted record is gated like GET', 403 === $head_restricted->get_status() );
$head_public = rmfa_rest_call( 0, 'HEAD', '/wp/v2/media/' . $public_id );
rmfa_check( 'HEAD on a public record stays readable', 200 === $head_public->get_status() );

// The gate is a read gate. An editor may edit the attachment without being on
// its access list, so a successful PATCH must not be turned into a 403.
$editor_id = rmfa_make_user( 'rmfa_editor', 'editor' );
$patched   = rmfa_rest_call( $editor_id, 'PATCH', '/wp/v2/media/' . $image_id, array( 'title' => 'rmfa-patched' ) );
rmfa_check( 'PATCH by a non-listed editor is not gated', 200 === $patched->get_status() );

// The site default is stored as an empty per-file mode.
$cleared = rmfa_rest_call(
	$admin_id,
	'POST',
	$route,
	array(
		'users'     => array( $allowed_id ),
		'roles'     => array( 'rmfa_role' ),
		'deny_mode' => 'default',
	)
);
rmfa_check( 'REST default clears per-file mode', 200 === $cleared->get_status() && '' === get_post_meta( $image_id, '_rmfa_deny_mode', true ) );

// Direct access to the physical file is blocked by the .protected/.htaccess guard.
$protected_dir     = wp_upload_dir()['basedir'] . '/.protected';
$htaccess_expected = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
rmfa_ensure_protected_dir_htaccess();
rmfa_check( 'protected dir has htaccess', file_exists( $protected_dir . '/.htaccess' ) );
rmfa_check( 'htaccess is the exact deny block', $htaccess_expected === file_get_contents( $protected_dir . '/.htaccess' ) );

// The guard fails closed: when the protected directory cannot be created, the
// file is never moved and the attachment is not marked restricted.
$rmfa_poison_upload_dir = static function ( array $dirs ): array {
	$dirs['basedir'] = '/proc/rmfa-no-such-place';
	return $dirs;
};
add_filter( 'upload_dir', $rmfa_poison_upload_dir );
$rmfa_guard_blocked = rmfa_set_file_as_protected( $public_id );
remove_filter( 'upload_dir', $rmfa_poison_upload_dir );
rmfa_check( 'guard failure leaves the file unrestricted', false === $rmfa_guard_blocked && '1' !== get_post_meta( $public_id, '_restricted_file', true ) );

$attached = (string) get_attached_file( $image_id );
rmfa_check( 'protected file lives in .protected', false !== strpos( $attached, '/.protected/' ) );
$real_url = str_replace( ABSPATH, '/', $attached );
rmfa_check( 'direct protected path is forbidden', 403 === rmfa_http( $real_url )['status'] );

// The attachment page returns the same result as the protected file URL: no
// 301 to the hash URL, the same denied response, and the file when allowed.
update_post_meta( $image_id, '_rmfa_deny_mode', '404' );
$page_path = (string) wp_parse_url( get_permalink( $image_id ), PHP_URL_PATH );
$page_anon = rmfa_http( $page_path );
rmfa_check( 'attachment page is not redirected', 301 !== $page_anon['status'] );
rmfa_check( 'attachment page returns the denied response', 404 === $page_anon['status'] );
$page_allowed = rmfa_http( $page_path, $allowed_cookies );
rmfa_check( 'attachment page serves the file to an allowed user', 200 === $page_allowed['status'] );

// A denied request must not reveal that the hash exists: in 404 mode the
// response for a real protected file and for a hash that never existed have to
// be identical down to the headers, or a prober can tell the two apart.
$rmfa_denied_404  = rmfa_http( $path );
$rmfa_unknown_404 = rmfa_http( '/protected-files/' . str_repeat( 'deadbeef', 8 ) );
rmfa_check( 'denied 404 and unknown hash agree on status', $rmfa_denied_404['status'] === $rmfa_unknown_404['status'] );
rmfa_check( 'denied 404 and unknown hash agree on body', $rmfa_denied_404['body'] === $rmfa_unknown_404['body'] );
rmfa_check( 'denied 404 and unknown hash agree on every header', $rmfa_denied_404['headers'] === $rmfa_unknown_404['headers'] );

// An allowed response is stored by the browser but revalidated on every use:
// the bytes are skipped when the file is unchanged, while the access check
// still runs. A denial stays uncacheable, so a placeholder is never pinned.
$rmfa_allowed_404 = rmfa_http( $path, $allowed_cookies );
rmfa_check( 'allowed response is cacheable but must revalidate', 'private, no-cache' === ( $rmfa_allowed_404['headers']['cache-control'] ?? '' ) );
rmfa_check( 'denied response stays uncacheable', false !== stripos( $rmfa_denied_404['headers']['cache-control'] ?? '', 'no-store' ) );
rmfa_check( 'denied response forbids shared caches', false !== stripos( $rmfa_denied_404['headers']['cache-control'] ?? '', 'private' ) );

$rmfa_etag = (string) ( $rmfa_allowed_404['headers']['etag'] ?? '' );
rmfa_check( 'allowed response carries an ETag', '' !== $rmfa_etag );

$rmfa_revalidated = wp_remote_get(
	'http://wordpress' . $path,
	array(
		'cookies'     => $allowed_cookies,
		'headers'     => array( 'If-None-Match' => $rmfa_etag ),
		'timeout'     => 20,
		'redirection' => 0,
	)
);
$rmfa_revalidated_status = (int) wp_remote_retrieve_response_code( $rmfa_revalidated );
rmfa_check( 'revalidation answers 304', 304 === $rmfa_revalidated_status );
rmfa_check( 'revalidation sends no body', '' === (string) wp_remote_retrieve_body( $rmfa_revalidated ) );
rmfa_check( '304 keeps the same Cache-Control', 'private, no-cache' === (string) wp_remote_retrieve_header( $rmfa_revalidated, 'cache-control' ) );

// The stored copy must not outlive the permission that produced it: the access
// check runs before the conditional branch, so a valid ETag from a request
// without access is denied rather than revalidated.
$rmfa_anon_conditional = wp_remote_get(
	'http://wordpress' . $path,
	array(
		'headers'     => array( 'If-None-Match' => $rmfa_etag ),
		'timeout'     => 20,
		'redirection' => 0,
	)
);
rmfa_check( 'a stored copy does not outlive access', 304 !== (int) wp_remote_retrieve_response_code( $rmfa_anon_conditional ) );

update_post_meta( $image_id, '_rmfa_deny_mode', 'pixel' );
$rmfa_pixel_denial = rmfa_http( $path );
rmfa_check(
	'pixel denial agrees on Cache-Control with a 404 denial',
	( $rmfa_pixel_denial['headers']['cache-control'] ?? '' ) === ( $rmfa_denied_404['headers']['cache-control'] ?? '' )
);
update_post_meta( $image_id, '_rmfa_deny_mode', '404' );

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/restrict-media-file-access/restrict-media-file-access.php' );
rmfa_check( 'plugin version is 1.6.0', '1.6.0' === $plugin_data['Version'] );

// A plugin update does not fire the activation hook, so the guard is restored
// on every request through Plugin::maybe_initialize().
$guard_removed = unlink( $protected_dir . '/.htaccess' );
\A8C\SpecialProjects\RestrictMediaFileAccess\Plugin::get_instance()->maybe_initialize();
rmfa_check( 'upgrade path restores the guard', $guard_removed && file_exists( $protected_dir . '/.htaccess' ) && $htaccess_expected === file_get_contents( $protected_dir . '/.htaccess' ) );

echo "\n{$GLOBALS['rmfa_checks']} checks, {$GLOBALS['rmfa_failures']} failures\n";
exit( 0 === $GLOBALS['rmfa_failures'] ? 0 : 1 );
