<?php
/**
 * The settings page and its save handler.
 */

namespace BmltLocTest;

use BMLT_Localities_By_Service_Body as Plugin;

class SettingsTest extends TestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::$admin );
	}

	public function test_menu_entry_for_administrators() {
		global $submenu;
		$submenu = array();
		set_current_screen( 'dashboard' );
		Plugin::menu();
		$this->assertSame( 'bmlt-localities', $submenu['options-general.php'][0][2] );
		$this->assertSame( 'manage_options', $submenu['options-general.php'][0][1] );
		set_current_screen( 'front' );
	}

	public function test_only_administrators_can_save() {
		wp_set_current_user( self::$editor );
		try {
			$this->save( array( 'server' => self::BMLT ) );
			$this->fail( 'saved' );
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'not allowed', $e->getMessage() );
		}
		$this->assertFalse( get_option( Plugin::OPTION ) );
	}

	public function test_nonce_is_required() {
		try {
			$this->save( array( 'server' => self::BMLT ), false );
			$this->fail( 'saved' );
		} catch ( \WPDieException $e ) {
			$this->assertFalse( get_option( Plugin::OPTION ) );
		}
	}

	public function server_urls() {
		return array(
			'root'                     => array( 'https://bmlt.test/main_server', 'https://bmlt.test/main_server' ),
			'trailing slash'           => array( 'https://bmlt.test/main_server/', 'https://bmlt.test/main_server' ),
			'client_interface/json'    => array( 'https://bmlt.test/main_server/client_interface/json/', 'https://bmlt.test/main_server' ),
			'client_interface'         => array( ' https://bmlt.test/main_server/client_interface ', 'https://bmlt.test/main_server' ),
			'api/v1'                   => array( 'https://bmlt.test/main_server/api/v1', 'https://bmlt.test/main_server' ),
			'upper-case scheme'        => array( 'HTTPS://bmlt.test/main_server', 'https://bmlt.test/main_server' ),
			'http'                     => array( 'http://bmlt.test/main_server', '' ),
			'credentials'              => array( 'https://user:pass@bmlt.test/main_server', '' ),
			'query'                    => array( 'https://bmlt.test/main_server?switcher=x', '' ),
			'fragment'                 => array( 'https://bmlt.test/main_server#x', '' ),
			'javascript'               => array( 'javascript:alert(1)', '' ),
			'not a URL'                => array( 'main server', '' ),
			'empty'                    => array( '', '' ),
		);
	}

	/**
	 * @dataProvider server_urls
	 */
	public function test_server_url_is_checked_and_normalised( $input, $expected ) {
		$this->assertSame( $expected, Plugin::server( $input ) );
	}

	public function test_invalid_url_is_refused_and_settings_kept() {
		$this->configure( array( 101 ) );
		$to = $this->save( array( 'server' => 'http://bmlt.test/main_server', 'bodies_listed' => '1', 'bodies' => array( 102 ) ) );
		$this->assertStringContainsString( 'bmlt_notice=url', $to );
		$this->assertSame( array( 'server' => self::BMLT, 'bodies' => array( 101 ) ), get_option( Plugin::OPTION ) );
		$this->assertStringContainsString( 'Enter a valid HTTPS BMLT root server URL', $this->admin_page( array( 'bmlt_notice' => 'url' ) ) );
	}

	public function test_first_save_then_choose_bodies() {
		$to = $this->save( array( 'server' => self::BMLT . '/client_interface/json/', 'bodies' => array( 101 ) ) );
		$this->assertStringContainsString( 'page=bmlt-localities', $to );
		$this->assertStringContainsString( 'bmlt_notice=server', $to );
		$this->assertSame( array( 'server' => self::BMLT, 'bodies' => array() ), get_option( Plugin::OPTION ), 'bodies are chosen from the new server\'s list' );
		$page = $this->admin_page( array( 'bmlt_notice' => 'server' ) );
		$this->assertStringContainsString( 'Select the service bodies below and save again.', $page );
		$this->assertStringContainsString( 'name="bodies_listed"', $page );

		$to = $this->save( array( 'server' => self::BMLT, 'bodies_listed' => '1', 'bodies' => array( '103', '101', 'x', '-2', '0', '101', array( 5 ) ) ) );
		$this->assertStringContainsString( 'bmlt_notice=saved', $to );
		$this->assertSame( array( 'server' => self::BMLT, 'bodies' => array( 101, 103 ) ), get_option( Plugin::OPTION ) );
		$this->assertStringContainsString( 'Settings saved', $this->admin_page( array( 'bmlt_notice' => 'saved' ) ) );
	}

	public function test_unticking_every_body_clears_the_selection() {
		$this->configure( array( 101, 102 ) );
		$this->save( array( 'server' => self::BMLT, 'bodies_listed' => '1' ) );
		$this->assertSame( array(), get_option( Plugin::OPTION )['bodies'] );
	}

	public function test_saving_while_bmlt_is_down_keeps_the_selection() {
		$this->configure( array( 101, 102 ) );
		self::$fault = 'down';
		$page        = $this->admin_page();
		$this->assertStringContainsString( 'BMLT returned HTTP 503.', $page );
		$this->assertStringContainsString( 'saving keeps the current selection', $page );
		$this->assertStringNotContainsString( 'bodies_listed', $page );
		$this->assertStringNotContainsString( 'name="bodies[]"', $page );
		// The form as the browser sends it: no checkboxes, so no bodies[].
		$this->save( array( 'server' => self::BMLT ) );
		$this->assertSame( array( 101, 102 ), get_option( Plugin::OPTION )['bodies'] );
	}

	public function test_changing_server_clears_the_selection() {
		$this->configure( array( 101 ) );
		$to = $this->save( array( 'server' => 'https://bmlt.test/other_server', 'bodies_listed' => '1', 'bodies' => array( 101 ) ) );
		$this->assertStringContainsString( 'bmlt_notice=server', $to );
		$this->assertSame( array( 'server' => 'https://bmlt.test/other_server', 'bodies' => array() ), get_option( Plugin::OPTION ) );
	}

	public function test_saving_refreshes_bmlt_data() {
		$this->configure( array( 101 ) );
		do_shortcode( '[bmlt_localities]' );
		$this->assertCount( 2, self::$requests );
		do_shortcode( '[bmlt_localities]' );
		$this->assertCount( 2, self::$requests );
		$this->save( array( 'server' => self::BMLT, 'bodies_listed' => '1', 'bodies' => array( 101 ) ) );
		do_shortcode( '[bmlt_localities]' );
		$this->assertCount( 4, self::$requests );
	}

	public function test_settings_page_lists_bodies_sorted_checked_and_escaped() {
		$this->configure( array( 102, 106 ) );
		$page  = $this->admin_page();
		$xpath = $this->dom( $page );
		$boxes = array();
		foreach ( $xpath->query( '//fieldset[@id="bmlt-bodies"]//input[@type="checkbox"]' ) as $input ) {
			$boxes[ trim( $input->parentNode->textContent ) ] = $input->hasAttribute( 'checked' );
		}
		$this->assertSame(
			array(
				'Empty Area (105)'                                    => false,
				'Faraway Area (201)'                                  => false,
				'Fictional Region (100)'                              => false,
				'Hillcrest Area (102)'                                => true,
				'Lakeside Area (103)'                                 => false,
				'Lakeside North (104)'                                => false,
				'Markup <script>alert("x")</script> & Co Area (106)' => true,
				'Other Region (200)'                                  => false,
				'Riverside Area (101)'                                => false,
				'Virtual Area (107)'                                  => false,
			),
			$boxes
		);
		$this->assertStringNotContainsString( '<script>alert', $page );
		$this->assertSame( self::BMLT, $xpath->query( '//input[@name="server"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 1, $xpath->query( '//input[@name="_wpnonce"]' )->length );
	}

	public function test_selected_bodies_gone_from_the_server_are_pointed_out() {
		$this->configure( array( 101, 998, 999 ) );
		$this->assertStringContainsString( 'no longer on the root server: 998, 999', $this->admin_page() );
	}

	public function test_page_is_empty_for_other_users() {
		wp_set_current_user( self::$editor );
		$this->assertSame( '', $this->admin_page() );
	}

	public function test_damaged_or_old_settings() {
		update_option( Plugin::OPTION, 'garbage' );
		$this->assertSame( array( 'server' => '', 'bodies' => array() ), Plugin::settings() );
		// Settings written by a hand edit or an older version: bodies as a comma list.
		update_option( Plugin::OPTION, array( 'server' => self::BMLT, 'bodies' => '101, 102' ) );
		$this->assertCount( 3, $this->rows( do_shortcode( '[bmlt_localities]' ) ) );
	}
}
