<?php
/**
 * The [bmlt_localities] list: grouping, spelling variants, links, escaping, layout and the requests it makes.
 */

namespace BmltLocTest;

class ShortcodeTest extends TestCase {

	public function test_not_configured_hint_for_administrators_only() {
		$this->assertSame( '', do_shortcode( '[bmlt_localities]' ) );
		wp_set_current_user( self::$admin );
		$this->assertStringContainsString( 'Settings → BMLT Localities', do_shortcode( '[bmlt_localities]' ) );
		$this->configure( array() );
		$this->assertStringContainsString( 'select service bodies', do_shortcode( '[bmlt_localities]' ) );
		wp_set_current_user( self::$editor );
		$this->assertSame( '', do_shortcode( '[bmlt_localities]' ) );
		$this->assertSame( array(), self::$requests );
	}

	public function test_one_area_grouped_by_state_with_unique_localities() {
		$this->configure( array( 101 ) );
		$this->assertSame(
			array(
				// "VA", "Va", "va.", "Virginia" and "virginia" are one state; "Riverton", "riverton" and "  Riverton "
				// one locality (first spelling kept), sorted naturally ("Riverton 2" before "Riverton 10").
				array( 'Riverside Area - (555) 010-1001', 'Maryland', 'Eastbank' ),
				array( 'Riverside Area - (555) 010-1001', 'Virginia', 'Château Ville, Millbrook, Riverton, Riverton 2, Riverton 10' ),
			),
			$this->rows( do_shortcode( '[bmlt_localities]' ) )
		);
	}

	public function test_unpublished_virtual_and_incomplete_meetings_are_left_out() {
		$this->configure( array( 101, 107 ) );
		$html = do_shortcode( '[bmlt_localities]' );
		$this->assertStringNotContainsString( 'Secretville', $html, 'unpublished meeting' );
		$this->assertStringNotContainsString( 'Nowhere', $html, 'locality without a state' );
		$this->assertStringNotContainsString( 'Virtual Area', $html, 'a body with only virtual meetings has no rows' );
		$this->configure( array( 107, 105 ) );
		$this->assertStringContainsString( 'No meeting localities found', do_shortcode( '[bmlt_localities]' ) );
	}

	public function test_areas_sorted_by_name_with_alternating_bands_and_rowspans() {
		$this->configure( array( 104, 101, 103, 102, 201, 105 ) );
		$html  = do_shortcode( '[bmlt_localities]' );
		$xpath = $this->dom( $html );
		$areas = array();
		foreach ( $xpath->query( '//tbody/tr/th[@scope="rowgroup"]' ) as $th ) {
			$tr      = $th->parentNode;
			$areas[] = array( preg_replace( '/ - .*/', '', trim( $th->textContent ) ), $th->getAttribute( 'rowspan' ), $tr->getAttribute( 'class' ) );
		}
		$this->assertSame(
			array(
				array( 'Faraway Area', '1', 'bmlt-area-blue bmlt-area-start' ),
				array( 'Hillcrest Area', '1', 'bmlt-area-white bmlt-area-start' ),
				array( 'Lakeside Area', '1', 'bmlt-area-blue bmlt-area-start' ),
				array( 'Lakeside North', '1', 'bmlt-area-white bmlt-area-start' ),
				array( 'Riverside Area', '2', 'bmlt-area-blue bmlt-area-start' ),
			),
			$areas
		);
		// Second state row of Riverside: same band, no divider, no Area cell.
		$second = $xpath->query( '//tbody/tr' )->item( 5 );
		$this->assertSame( 'bmlt-area-blue', $second->getAttribute( 'class' ) );
		$this->assertSame( 0, $second->getElementsByTagName( 'th' )->length );
		$this->assertSame( array( 'Area - Phone Number', 'State', 'Localities' ), array_map( 'trim', array_map( static function ( $n ) { return $n->textContent; }, iterator_to_array( $xpath->query( '//thead//th' ) ) ) ) );
	}

	public function test_sub_area_is_listed_separately_not_merged_into_its_parent() {
		$this->configure( array( 103, 104 ) );
		$this->assertSame(
			array(
				array( 'Lakeside Area', 'Virginia', 'Laketown' ),
				array( 'Lakeside North - 555-0104', 'Virginia', 'Northshore' ),
			),
			$this->rows( do_shortcode( '[bmlt_localities]' ) )
		);
		$this->assertArrayNotHasKey( 'recursive', self::$requests[1][1] );
	}

	public function test_states_and_provinces() {
		$this->configure( array( 102, 201 ) );
		$this->assertSame(
			array(
				array( 'Faraway Area - 555-0201', 'ON', 'Distant City' ),
				array( 'Hillcrest Area - Call 555-0102 any time', 'Ohio', 'Hillcrest, Ridge' ),
			),
			$this->rows( do_shortcode( '[bmlt_localities]' ) )
		);
	}

	public function test_links_to_websites_and_phone_numbers() {
		$this->configure( array( 101, 102, 103, 104, 201 ) );
		$xpath = $this->dom( do_shortcode( '[bmlt_localities]' ) );
		$links = array();
		foreach ( $xpath->query( '//tbody//th//a' ) as $a ) {
			$links[ $a->textContent ] = $a->getAttribute( 'href' );
		}
		$this->assertSame(
			array(
				'Faraway Area'           => 'http://faraway.example.org',
				'555-0201'               => 'tel:5550201',
				'Hillcrest Area'         => 'https://hillcrest.example.org', // "hillcrest.example.org" in BMLT.
				'Call 555-0102 any time' => 'tel:5550102',
				'Lakeside North'         => 'https://north.example.org', // "Https://" in BMLT.
				'555-0104'               => 'tel:5550104',
				'Riverside Area'         => 'https://riverside.example.org/',
				'(555) 010-1001'         => 'tel:5550101001',
			),
			$links
		);
		// Lakeside's website is javascript:alert(1): its name is plain text, and it has no helpline.
		$lakeside = $xpath->query( '//tbody//th[contains(., "Lakeside Area")]' )->item( 0 );
		$this->assertSame( 0, $lakeside->getElementsByTagName( 'a' )->length );
		$this->assertSame( 'Lakeside Area', trim( $lakeside->textContent ) );
	}

	public function test_markup_from_bmlt_is_shown_as_text() {
		$this->configure( array( 106 ) );
		$html = do_shortcode( '[bmlt_localities]' );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$xpath = $this->dom( $html );
		$th    = $xpath->query( '//tbody//th' )->item( 0 );
		$this->assertSame( 'Markup <script>alert("x")</script> & Co Area - +1 555 010 6"><b>x</b>', trim( $th->textContent ) );
		$links = $xpath->query( '//tbody//th//a' );
		$this->assertSame( 'https://markup.example.org/?a=1&b=2', $links->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( 'tel:+15550106', $links->item( 1 )->getAttribute( 'href' ) );
		$this->assertSame( '<b>Bold</b> Town & Sons', $xpath->query( '//tbody//td' )->item( 1 )->textContent );
	}

	public function test_services_attribute_overrides_the_default_selection() {
		$this->configure( array( 101 ) );
		$rows = $this->rows( do_shortcode( '[bmlt_localities services="102, x, -4, 0"]' ) );
		$this->assertSame( array( 'Hillcrest Area - Call 555-0102 any time' ), array_unique( array_column( $rows, 0 ) ) );
		$this->assertStringContainsString( 'No matching service bodies', do_shortcode( '[bmlt_localities services="999"]' ) );
		wp_set_current_user( self::$admin );
		$this->assertStringContainsString( 'Configure the BMLT server', do_shortcode( '[bmlt_localities services="x"]' ) );
	}

	public function test_two_small_requests_then_the_cache() {
		$this->configure( array( 102, 101, 103 ) );
		do_shortcode( '[bmlt_localities]' );
		$this->assertSame( array( 'GetServiceBodies', 'GetSearchResults' ), $this->switchers() );
		$search = self::$requests[1][1];
		$this->assertSame( array( '101', '102', '103' ), $search['services'], 'every body in one request' );
		$this->assertSame( 'service_body_bigint,location_municipality,location_province', $search['data_field_key'] );
		$this->assertStringStartsWith( 'https://bmlt.test/main_server/client_interface/json/?switcher=GetSearchResults&services[]=101&', self::$requests[1][2] );
		// Same list again, and a page with the same bodies in another order: from the cache.
		do_shortcode( '[bmlt_localities]' );
		do_shortcode( '[bmlt_localities services="103,101,102"]' );
		$this->assertCount( 2, self::$requests );
	}

	public function test_meetings_of_bodies_not_asked_for_are_ignored() {
		// A server that ignores services[] and sends every meeting.
		self::$fault = 'ignore-services';
		$this->configure( array( 102 ) );
		$this->assertSame(
			array( array( 'Hillcrest Area - Call 555-0102 any time', 'Ohio', 'Hillcrest, Ridge' ) ),
			$this->rows( do_shortcode( '[bmlt_localities]' ) )
		);
	}

	public function test_stylesheet_is_enqueued_once_not_printed_inline() {
		$this->configure( array( 101 ) );
		$html = do_shortcode( '[bmlt_localities][bmlt_localities services="102"]' );
		$this->assertStringNotContainsString( '<style', $html );
		$this->assertSame( 2, substr_count( $html, '<table>' ) );
		$this->assertTrue( wp_style_is( 'bmlt-localities', 'enqueued' ) );
		ob_start();
		wp_print_styles( 'bmlt-localities' );
		$css = ob_get_clean();
		$this->assertSame( 1, substr_count( $css, '.bmlt-localities-table table' ) );
		$this->assertStringContainsString( 'bmlt-area-blue', $css );
	}

	public function test_root_server_with_object_or_odd_rows_does_not_break_the_page() {
		$this->configure( array( 101 ) );
		self::$fault = 'object';
		$this->assertStringContainsString( 'temporarily unavailable', do_shortcode( '[bmlt_localities]' ) );
	}
}
