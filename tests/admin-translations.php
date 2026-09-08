<?php
/** Run with: studio wp eval-file tests/admin-translations.php */

if ( ! class_exists( 'Sneerly_Coherent_Random_Post' ) ) {
	throw new RuntimeException( 'Activate Sneerly Coherent Random Post before running this check.' );
}

foreach ( array( 'wp-admin/includes/plugin.php', 'wp-admin/includes/template.php', 'wp-admin/includes/user.php' ) as $include ) {
	require_once ABSPATH . $include;
}

$original_limit = get_option( 'sneerly_coherent_history_limit', null );
$original_types = get_option( 'sneerly_coherent_post_types', null );
$original_user  = get_current_user_id();
$original_get   = $_GET;
$original_ip    = $_SERVER['REMOTE_ADDR'] ?? null;
$original_uri   = $_SERVER['REQUEST_URI'] ?? null;
$original_title = $GLOBALS['title'] ?? null;
$original_menu  = $GLOBALS['submenu']['options-general.php'] ?? null;
$user_id        = 0;
$post_id        = 0;
$fixture_ip     = 'suite-admin-' . wp_generate_uuid4();
$history_key    = '';
$translate      = static function ( $translation, $text, $domain ) {
	if ( 'sneerly-coherent-random' !== $domain ) {
		return $translation;
	}

	$markers = array(
		'Sneerly Coherent Random Post Settings' => 'MARKER <strong>Settings</strong>',
		'Sneerly Coherent Random'               => 'MARKER <strong>Menu</strong>',
		'History Size'                           => 'MARKER <strong>History Size</strong>',
		'Recent-history limit: %d. Posts in recent history are excluded while another eligible post is available.' => 'MARKER <strong>Limit %d</strong>',
		'Recently shown posts:'                  => 'MARKER <strong>Recent posts</strong>',
	);

	return $markers[ $text ] ?? $translation;
};

try {
	$user_id = wp_insert_user( array(
		'user_login' => 'suite-admin-' . wp_generate_uuid4(),
		'user_pass'  => wp_generate_password(),
		'role'       => 'administrator',
	) );
	if ( is_wp_error( $user_id ) ) {
		throw new RuntimeException( $user_id->get_error_message() );
	}
	$post_id = wp_insert_post( array(
		'post_status' => 'publish',
		'post_title'  => 'Suite admin history fixture',
	), true );
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( $post_id->get_error_message() );
	}

	wp_set_current_user( $user_id );
	$_SERVER['REMOTE_ADDR'] = $fixture_ip;
	$history_key = 'sneerly_coherent_random_history_' . $user_id;
	$_SERVER['REQUEST_URI'] = '/wp-admin/options-general.php?page=sneerly-coherent-random';
	$_GET = array();
	$GLOBALS['title'] = 'MARKER <em>Admin title</em>';
	update_option( 'sneerly_coherent_history_limit', 1 );
	update_option( 'sneerly_coherent_post_types', array( 'post' ) );

	add_filter( 'gettext', $translate, 10, 3 );
	try {
		$plugin = new Sneerly_Coherent_Random_Post();
		$plugin->add_admin_menu();
		$menu_item = end( $GLOBALS['submenu']['options-general.php'] );
		if ( 'MARKER <strong>Menu</strong>' !== $menu_item[0] || 'MARKER <strong>Settings</strong>' !== $menu_item[3] ) {
			throw new RuntimeException( 'Settings menu string was not translated.' );
		}
		ob_start();
		$plugin->render_settings_page();
		$empty_output = ob_get_clean();

		if ( false === strpos( $empty_output, 'MARKER &lt;strong&gt;History Size&lt;/strong&gt;' ) || false !== strpos( $empty_output, 'MARKER <strong>History Size</strong>' ) ) {
			throw new RuntimeException( 'History Size translation was not escaped.' );
		}
		if ( false === strpos( $empty_output, 'MARKER &lt;strong&gt;Limit 1&lt;/strong&gt;' ) || false !== strpos( $empty_output, 'MARKER <strong>Limit 1</strong>' ) ) {
			throw new RuntimeException( 'History-limit translation was not escaped.' );
		}
		if ( false !== strpos( $empty_output, 'MARKER <em>Admin title</em>' ) || false === strpos( $empty_output, 'MARKER &lt;em&gt;Admin title&lt;/em&gt;' ) ) {
			throw new RuntimeException( 'Admin page title was not escaped.' );
		}
		if ( false === strpos( $empty_output, '<code>' . esc_url( site_url( '/?random' ) ) . '</code>' ) ) {
			throw new RuntimeException( 'Random URL code example was not preserved.' );
		}

		set_transient( $history_key, array( $post_id ), HOUR_IN_SECONDS );
		ob_start();
		$plugin->render_settings_page();
		$history_output = ob_get_clean();
		if ( false === strpos( $history_output, 'MARKER &lt;strong&gt;Recent posts&lt;/strong&gt;' ) || false !== strpos( $history_output, 'MARKER <strong>Recent posts</strong>' ) ) {
			throw new RuntimeException( 'Recent history translation was not escaped.' );
		}
		if ( false === strpos( html_entity_decode( $history_output, ENT_QUOTES, 'UTF-8' ), 'clear_history=1&_wpnonce=' ) ) {
			throw new RuntimeException( 'Clear History nonce link was not preserved.' );
		}
	} finally {
		remove_filter( 'gettext', $translate, 10 );
	}
} finally {
	if ( '' !== $history_key ) {
		delete_transient( $history_key );
	}
	if ( is_int( $post_id ) && $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
	if ( is_int( $user_id ) && $user_id > 0 ) {
		wp_delete_user( $user_id );
	}
	if ( null === $original_limit ) {
		delete_option( 'sneerly_coherent_history_limit' );
	} else {
		update_option( 'sneerly_coherent_history_limit', $original_limit );
	}
	if ( null === $original_types ) {
		delete_option( 'sneerly_coherent_post_types' );
	} else {
		update_option( 'sneerly_coherent_post_types', $original_types );
	}
	wp_set_current_user( $original_user );
	$_GET = $original_get;
	if ( null === $original_uri ) {
		unset( $_SERVER['REQUEST_URI'] );
	} else {
		$_SERVER['REQUEST_URI'] = $original_uri;
	}
	if ( null === $original_ip ) {
		unset( $_SERVER['REMOTE_ADDR'] );
	} else {
		$_SERVER['REMOTE_ADDR'] = $original_ip;
	}
	if ( null === $original_title ) {
		unset( $GLOBALS['title'] );
	} else {
		$GLOBALS['title'] = $original_title;
	}
	if ( null === $original_menu ) {
		unset( $GLOBALS['submenu']['options-general.php'] );
	} else {
		$GLOBALS['submenu']['options-general.php'] = $original_menu;
	}
}

WP_CLI::success( 'Settings-page translations are escaped and preserve admin actions.' );
