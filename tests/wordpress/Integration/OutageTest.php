<?php
/**
 * BMLT outages and wrong answers: what visitors and administrators see, the last good list, and retries.
 */

namespace BmltLocTest;

class OutageTest extends TestCase {

	public function faults() {
		return array(
			'HTTP 503'           => array( 'down', 'BMLT returned HTTP 503.' ),
			'HTML page'          => array( 'html', 'BMLT returned an unexpected response.' ),
			'JSON object'        => array( 'object', 'BMLT returned an unexpected response.' ),
			'no answer'          => array( 'timeout', 'BMLT could not be reached: cURL error 28' ),
		);
	}

	/**
	 * @dataProvider faults
	 */
	public function test_without_a_previous_list_visitors_see_a_notice_administrators_the_reason( $fault, $message ) {
		$this->configure( array( 101 ) );
		self::$fault = $fault;
		$this->assertSame( '<p class="bmlt-localities-error">Meeting locality data is temporarily unavailable.</p>', do_shortcode( '[bmlt_localities]' ) );
		wp_set_current_user( self::$admin );
		$this->assertStringContainsString( esc_html( $message ), do_shortcode( '[bmlt_localities]' ) );
	}

	public function test_failure_is_remembered_for_five_minutes_so_pages_stay_fast() {
		$this->configure( array( 101 ) );
		self::$fault = 'timeout';
		do_shortcode( '[bmlt_localities]' );
		do_shortcode( '[bmlt_localities]' );
		do_shortcode( '[bmlt_localities]' );
		$this->assertSame( array( 'GetServiceBodies' ), $this->switchers() );
		// Five minutes later BMLT is back.
		$this->expire_fresh_cache();
		self::$fault = '';
		$this->assertCount( 2, $this->rows( do_shortcode( '[bmlt_localities]' ) ) );
	}

	public function test_last_good_list_is_shown_while_bmlt_is_down() {
		$this->configure( array( 101, 102 ) );
		$before = do_shortcode( '[bmlt_localities]' );
		$this->assertCount( 3, $this->rows( $before ) );
		// An hour later, BMLT is down.
		$this->expire_fresh_cache();
		self::$fault    = 'down';
		self::$requests = array();
		$this->assertSame( $before, do_shortcode( '[bmlt_localities]' ) );
		$this->assertSame( $before, do_shortcode( '[bmlt_localities]' ) );
		$this->assertSame( array( 'GetServiceBodies', 'GetSearchResults' ), $this->switchers(), 'asked once, then not again for five minutes' );
	}

	public function test_meetings_failing_after_bodies_loaded() {
		$this->configure( array( 101 ) );
		Plugin_Access::bodies();
		self::$fault = 'down';
		$this->assertStringContainsString( 'temporarily unavailable', do_shortcode( '[bmlt_localities]' ) );
	}
}

/**
 * Calls the plugin's public helpers.
 */
class Plugin_Access {
	public static function bodies() {
		return \BMLT_Localities_By_Service_Body::bodies();
	}
}
