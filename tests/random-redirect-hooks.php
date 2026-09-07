<?php
/** Run with: wp eval-file tests/random-redirect-hooks.php */

$callback = static function ( $entry ) {
	return is_array( $entry['function'] ) && $entry['function'][0] instanceof Sneerly_Coherent_Random_Post && 'check_for_random_parameter' === $entry['function'][1];
};
$template = $GLOBALS['wp_filter']['template_redirect']->callbacks[0] ?? array();
$init     = $GLOBALS['wp_filter']['init']->callbacks ?? array();

foreach ( $init as $entries ) {
	foreach ( $entries as $entry ) {
		if ( $callback( $entry ) ) {
			throw new RuntimeException( 'Random redirect still runs on init.' );
		}
	}
}

foreach ( $template as $entry ) {
	if ( $callback( $entry ) ) {
		WP_CLI::success( 'Random redirect runs on template_redirect priority 0.' );
		return;
	}
}

throw new RuntimeException( 'Random redirect is not registered on template_redirect priority 0.' );
