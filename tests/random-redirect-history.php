<?php
/** Run with: wp eval-file tests/random-redirect-history.php */

$original_types = get_option( 'sneerly_coherent_post_types', null );
$original_limit = get_option( 'sneerly_coherent_history_limit', null );
$original_user  = get_current_user_id();
$original_ip    = $_SERVER['REMOTE_ADDR'] ?? null;
$original_get   = $_GET;
$fixture_ip     = 'suite-history-' . wp_generate_uuid4();
$history_key    = 'sneerly_coherent_random_history_' . md5( $fixture_ip );
$post_id        = 0;
$second_id      = 0;
$redirects     = 0;
$permalinks    = 0;
$cancel        = static function () use ( &$redirects ) {
	++$redirects;
	return false;
};
$missing       = static function () use ( &$permalinks ) {
	++$permalinks;
	return false;
};

try {
	register_post_type( 'suite_history_test', array( 'public' => true ) );
	update_option( 'sneerly_coherent_post_types', array( 'suite_history_test' ) );
	update_option( 'sneerly_coherent_history_limit', 10 );
	$post_id = wp_insert_post( array(
		'post_type' => 'suite_history_test',
		'post_status' => 'publish',
		'post_title' => 'Random redirect history fixture',
	), true );
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( $post_id->get_error_message() );
	}
	wp_set_current_user( 0 );
	$_SERVER['REMOTE_ADDR'] = $fixture_ip;
	$_GET['random'] = '';
	$plugin = new Sneerly_Coherent_Random_Post();
	if ( ! get_permalink( $post_id ) ) {
		throw new RuntimeException( 'Fixture has no permalink.' );
	}

	foreach ( array( array( -1 ), array( $post_id ) ) as $history ) {
		foreach ( array( 'wp_redirect', 'post_type_link' ) as $hook ) {
			set_transient( $history_key, $history, 3600 );
			$redirects = 0;
			$permalinks = 0;
			// Always prevent production exit, even if the permalink guard regresses.
			add_filter( 'wp_redirect', $cancel, PHP_INT_MAX );
			if ( 'post_type_link' === $hook ) {
				add_filter( 'post_type_link', $missing, PHP_INT_MAX );
			}
			try {
				$plugin->check_for_random_parameter();
				if ( 'post_type_link' === $hook && ( 0 === $permalinks || false !== get_permalink( $post_id ) ) ) {
					throw new RuntimeException( 'Missing permalink path was not exercised.' );
				}
				if ( $redirects !== ( 'wp_redirect' === $hook ? 1 : 0 ) ) {
					throw new RuntimeException( 'Unexpected redirect attempt: ' . $hook );
				}
			} finally {
				remove_filter( 'wp_redirect', $cancel, PHP_INT_MAX );
				remove_filter( 'post_type_link', $missing, PHP_INT_MAX );
			}
			if ( $history !== get_transient( $history_key ) ) {
				throw new RuntimeException( 'Failed redirect changed history: ' . $hook );
			}
		}
	}

	$select = new ReflectionMethod( $plugin, 'get_random_post' );
	$record = new ReflectionMethod( $plugin, 'add_to_history' );
	$select->setAccessible( true );
	$record->setAccessible( true );
	foreach ( array( false, true ) as $exhausted ) {
		$history = $exhausted ? array( $post_id, -1 ) : array( -1 );
		set_transient( $history_key, $history, 3600 );
		$selected = $select->invoke( $plugin );
		if ( ! $selected || $selected->ID !== $post_id || get_transient( $history_key ) !== $history ) {
			throw new RuntimeException( 'Selection changed history or chose the wrong fixture.' );
		}
		// Keep the exhausted snapshot unchanged; update the nonexhausted history.
		set_transient( $history_key, array( $post_id, -1 ), 3600 );
		$record->invoke( $plugin, $post_id );
		$expected = $exhausted ? array( $post_id ) : array( $post_id, $post_id, -1 );
		if ( get_transient( $history_key ) !== $expected ) {
			throw new RuntimeException( 'History reset ignored the original selection state.' );
		}
	}
	// Create this only after the single-post exhaustion cases above.
	$second_id = wp_insert_post( array(
		'post_type' => 'suite_history_test',
		'post_status' => 'publish',
		'post_title' => 'Second random redirect history fixture',
	), true );
	if ( is_wp_error( $second_id ) || ! $second_id ) {
		throw new RuntimeException( 'Second fixture creation failed.' );
	}
	$other = new Sneerly_Coherent_Random_Post();
	$next_id = $post_id;
	$force_selection = static function ( $posts, $query ) use ( &$next_id ) {
		return array( 'suite_history_test' ) === $query->get( 'post_type' )
			? array( get_post( $next_id ) ) : $posts;
	};
	add_filter( 'posts_pre_query', $force_selection, 10, 2 );
	try {
		foreach ( array( 'visible', 'stale-value', 'stale-miss' ) as $mode ) {
			$old_history = array( $post_id, $second_id );
			if ( 'stale-miss' === $mode ) {
				delete_transient( $history_key );
			} else {
				set_transient( $history_key, $old_history, 3600 );
			}
			$next_id = $post_id;
			$first = $select->invoke( $plugin );
			$next_id = $second_id;
			$second = $select->invoke( $other );
			if ( ! $first || ! $second || $first->ID !== $post_id || $second->ID !== $second_id ) {
				throw new RuntimeException( 'Overlap test did not select distinct fixtures.' );
			}
			$record->invoke( $plugin, $first->ID );
			// Restore the second request's stale local cache after the first writes both rows.
			if ( 'stale-value' === $mode ) {
				wp_cache_set( '_transient_' . $history_key, $old_history, 'options' );
				wp_cache_set( '_transient_timeout_' . $history_key, time() - 1, 'options' );
			} elseif ( 'stale-miss' === $mode ) {
				$missing_keys = wp_cache_get( 'notoptions', 'options' );
				$missing_keys = is_array( $missing_keys ) ? $missing_keys : array();
				foreach ( array( '_transient_', '_transient_timeout_' ) as $prefix ) {
					wp_cache_delete( $prefix . $history_key, 'options' );
					$missing_keys[ $prefix . $history_key ] = true;
				}
				wp_cache_set( 'notoptions', $missing_keys, 'options' );
			}
			$record->invoke( $other, $second->ID );
			if ( array( $second->ID, $first->ID ) !== get_transient( $history_key ) ) {
				throw new RuntimeException( 'Lost intervening history: ' . $mode );
			}
		}
	} finally {
		remove_filter( 'posts_pre_query', $force_selection, 10 );
	}

} finally {
	delete_transient( $history_key );
	if ( is_int( $second_id ) && $second_id > 0 ) {
		wp_delete_post( $second_id, true );
	}
	if ( is_int( $post_id ) && $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
	if ( null === $original_types ) {
		delete_option( 'sneerly_coherent_post_types' );
	} else {
		update_option( 'sneerly_coherent_post_types', $original_types );
	}
	unregister_post_type( 'suite_history_test' );
	if ( null === $original_limit ) {
		delete_option( 'sneerly_coherent_history_limit' );
	} else {
		update_option( 'sneerly_coherent_history_limit', $original_limit );
	}
	wp_set_current_user( $original_user );
	$_GET = $original_get;
	if ( null === $original_ip ) {
		unset( $_SERVER['REMOTE_ADDR'] );
	} else {
		$_SERVER['REMOTE_ADDR'] = $original_ip;
	}
}

WP_CLI::success( 'Failed redirects preserve history; overlapping selections retain committed entries.' );
