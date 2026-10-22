<?php
/**
 * Uninstall, on a single site and on every site of a network.
 */

namespace BmltLocTest;

use BMLT_Localities_By_Service_Body as Plugin;

class LifecycleTest extends TestCase {

	private function uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'bmlt-localities-by-service-body/bmlt-localities-by-service-body.php' );
		}
		include dirname( BMLT_LOC_TEST_PLUGIN_FILE ) . '/uninstall.php';
	}

	private function plugin_rows() {
		global $wpdb;
		wp_cache_flush();
		return $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%bmlt\\_loc%' ORDER BY option_name" );
	}

	public function test_uninstall_removes_settings_and_cache() {
		$this->configure( array( 101 ) );
		do_shortcode( '[bmlt_localities]' );
		update_option( Plugin::GENERATION, 3 );
		$this->assertNotEmpty( preg_grep( '/^_transient_bmlt_loc_stale_/', $this->plugin_rows() ) );
		update_option( 'unrelated_bmlt_location', 'keep' );
		$this->uninstall();
		$this->assertSame( array( 'unrelated_bmlt_location' ), $this->plugin_rows() );
		delete_option( 'unrelated_bmlt_location' );
	}

	public function test_uninstall_on_a_network_cleans_every_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'multisite only (WP_MULTISITE=1)' );
		}
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		$this->configure( array( 102 ) );
		do_shortcode( '[bmlt_localities]' );
		$this->assertNotEmpty( $this->plugin_rows() );
		restore_current_blog();
		$this->configure( array( 101 ) );
		$this->uninstall();
		$this->assertSame( array(), $this->plugin_rows() );
		switch_to_blog( $site );
		$this->assertSame( array(), $this->plugin_rows() );
		restore_current_blog();
	}
}
