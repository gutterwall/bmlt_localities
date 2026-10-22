<?php
/**
 * WordPress integration tests for BMLT Localities by Service Body.
 *
 *   WP_TESTS_DIR=/path/to/tests-lib vendor/bin/phpunit -c tests/wordpress/phpunit.xml.dist
 *   (WP_MULTISITE=1 runs the same tests on a multisite network.)
 *
 * The plugin's BMLT requests are answered in-process by the fake BMLT root server (fake-bmlt/).
 * BMLT_LOC_PLUGIN_FILE tests another copy of the plugin (for example the previous release, to see tests fail).
 */

$bmlt_loc_root  = dirname( __DIR__, 2 );
$bmlt_loc_tests = getenv( 'WP_TESTS_DIR' );
if ( ! $bmlt_loc_tests || ! file_exists( $bmlt_loc_tests . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Set WP_TESTS_DIR to a WordPress test library (bin/install-wp-tests.sh).\n" );
	exit( 1 );
}
$bmlt_loc_file = getenv( 'BMLT_LOC_PLUGIN_FILE' ) ? getenv( 'BMLT_LOC_PLUGIN_FILE' ) : $bmlt_loc_root . '/bmlt-localities-by-service-body/bmlt-localities-by-service-body.php';
define( 'BMLT_LOC_TEST_PLUGIN_FILE', $bmlt_loc_file );

require_once $bmlt_loc_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $bmlt_loc_root . '/fake-bmlt/src/FakeBmlt.php';
require_once $bmlt_loc_tests . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require BMLT_LOC_TEST_PLUGIN_FILE;
	}
);

require $bmlt_loc_tests . '/includes/bootstrap.php';
require_once __DIR__ . '/TestCase.php';
