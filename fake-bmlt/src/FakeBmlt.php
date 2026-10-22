<?php
/**
 * A fake BMLT root server for testing: the client_interface/json/ switchers the plugin uses, over a fixed,
 * fictional dataset. No WordPress needed; used in-process by the PHPUnit suite and over HTTP (router.php) by the
 * end-to-end test and for manual testing.
 *
 * Faithful to the BMLT root server's JSON client interface (bmlt-enabled/bmlt-root-server 3.x/4.x):
 *   - every value is a string, ids included ("12", "0");
 *   - GetServiceBodies gives each body's helpline and url (website) as entered by its administrator, so they come
 *     in every form: "8005550100", "(555) 010-1001", "", "example.org" without a scheme, "Https://...";
 *   - GetSearchResults returns only published meetings, takes services[] (or services=, or a comma list),
 *     recursive=1 to include every service body below the ones asked for, and data_field_key to choose the fields;
 *   - location_province is whatever was typed: "VA", "Va", "va.", "Virginia", "virginia", "ON";
 *   - unknown switchers answer HTTP 400.
 *
 * Everything is fictional. Phone numbers use the NANP 555-01xx fictional range.
 */

namespace BmltLocTest;

final class FakeBmlt {

	const VERSION = '4.1.0';

	/**
	 * Service bodies: id => [parent_id, name, type, helpline, url].
	 *
	 * 100 Fictional Region
	 *   101 Riverside Area       many spellings of the same localities and states; a virtual meeting; an unpublished one
	 *   102 Hillcrest Area       website without a scheme; a phone number with words
	 *   103 Lakeside Area        no helpline; a javascript: website (shown as plain text)
	 *     104 Lakeside North     a sub-Area: listed on its own, never merged into Lakeside
	 *   105 Empty Area           no meetings
	 *   106 Markup Area          markup in its name, localities and phone; international helpline
	 *   107 Virtual Area         only virtual meetings (no locality)
	 * 200 Other Region
	 *   201 Faraway Area         Canadian meetings (ON stays ON)
	 */
	const SERVICE_BODIES = array(
		100 => array( 0, 'Fictional Region', 'RS', '8005550100', 'https://region.example.org' ),
		101 => array( 100, 'Riverside Area', 'AS', '(555) 010-1001', 'https://riverside.example.org/' ),
		102 => array( 100, 'Hillcrest Area', 'AS', 'Call 555-0102 any time', 'hillcrest.example.org' ),
		103 => array( 100, 'Lakeside Area', 'AS', '', 'javascript:alert(1)' ),
		104 => array( 103, 'Lakeside North', 'AS', '555-0104', 'Https://north.example.org' ),
		105 => array( 100, 'Empty Area', 'AS', '555-0105', 'https://empty.example.org' ),
		106 => array( 100, 'Markup <script>alert("x")</script> & Co Area', 'AS', '+1 555 010 6"><b>x</b>', 'https://markup.example.org/?a=1&b="2"' ),
		107 => array( 100, 'Virtual Area', 'AS', '555-0107', '' ),
		200 => array( 0, 'Other Region', 'RS', '', '' ),
		201 => array( 200, 'Faraway Area', 'AS', '555-0201', 'http://faraway.example.org' ),
	);

	/**
	 * Meetings: id => [service body, name, municipality, province, published].
	 */
	const MEETINGS = array(
		1001 => array( 101, 'Just for Today', 'Riverton', 'VA', '1' ),
		1002 => array( 101, 'Riverside Serenity', 'riverton', 'Va', '1' ),
		1003 => array( 101, 'Noon Group', '  Riverton ', 'va.', '1' ),
		1004 => array( 101, 'Millbrook Monday', 'Millbrook', 'Virginia', '1' ),
		1005 => array( 101, 'Hidden Meeting', 'Secretville', 'VA', '0' ),
		1006 => array( 101, 'Online Recovery', '', '', '1' ),
		1007 => array( 101, 'Line Group', 'Eastbank', 'MD', '1' ),
		1008 => array( 101, 'Café Clean', 'Château  Ville', 'virginia', '1' ),
		1009 => array( 101, 'Ten Group', 'Riverton 10', 'VA', '1' ),
		1010 => array( 101, 'Two Group', 'Riverton 2', 'VA', '1' ),
		1011 => array( 101, 'State only', '', 'VA', '1' ),
		1012 => array( 101, 'Town only', 'Nowhere', '', '1' ),
		2001 => array( 102, 'Hilltop Hope', 'Hillcrest', 'OH', '1' ),
		2002 => array( 102, 'Steps on the Hill', 'Ridge', 'oh', '1' ),
		3001 => array( 103, 'Lakeside Living', 'Laketown', 'VA', '1' ),
		3002 => array( 104, 'North Shore Group', 'Northshore', 'VA', '1' ),
		6001 => array( 106, 'Markup', '<b>Bold</b> Town & Sons', 'NC', '1' ),
		7001 => array( 107, 'Zoom Group', '', 'VA', '1' ),
		9001 => array( 201, 'Faraway Friends', 'Distant City', 'ON', '1' ),
	);

	/**
	 * Service body ids at or below the given ones.
	 *
	 * @param int[] $ids Ids.
	 * @return int[]
	 */
	public static function descendants( array $ids ) {
		$out   = array();
		$queue = $ids;
		while ( $queue ) {
			$id = (int) array_shift( $queue );
			if ( isset( $out[ $id ] ) ) {
				continue;
			}
			$out[ $id ] = $id;
			foreach ( self::SERVICE_BODIES as $child => $sb ) {
				if ( $sb[0] === $id ) {
					$queue[] = $child;
				}
			}
		}
		return array_values( $out );
	}

	/**
	 * Answer one request.
	 *
	 * @param array $get Query parameters, as PHP parses them ($_GET).
	 * @return array{0:int,1:string,2:string} HTTP status, content type, body.
	 */
	public static function handle( array $get ) {
		$switcher = isset( $get['switcher'] ) ? (string) $get['switcher'] : '';
		switch ( $switcher ) {
			case 'GetServerInfo':
				return self::json(
					array(
						array(
							'version'    => self::VERSION,
							'versionInt' => '4001000',
							'langs'      => 'en',
							'nativeLang' => 'en',
						),
					)
				);
			case 'GetServiceBodies':
				$rows = array();
				foreach ( self::SERVICE_BODIES as $id => $sb ) {
					$rows[] = array(
						'id'            => (string) $id,
						'parent_id'     => (string) $sb[0],
						'name'          => $sb[1],
						'description'   => '',
						'type'          => $sb[2],
						'url'           => $sb[4],
						'helpline'      => $sb[3],
						'world_id'      => 'AR' . $id,
						'contact_email' => '',
					);
				}
				return self::json( $rows );
			case 'GetSearchResults':
				return self::json( self::search( $get ) );
			default:
				return array( 400, 'text/plain', 'Unknown switcher' );
		}
	}

	/**
	 * GetSearchResults.
	 *
	 * @param array $get Query.
	 * @return array
	 */
	private static function search( array $get ) {
		$services = array();
		if ( isset( $get['services'] ) ) {
			foreach ( (array) $get['services'] as $s ) {
				foreach ( explode( ',', (string) $s ) as $id ) {
					if ( (int) $id > 0 ) {
						$services[] = (int) $id;
					}
				}
			}
		}
		if ( $services && isset( $get['recursive'] ) && '1' === (string) $get['recursive'] ) {
			$services = self::descendants( $services );
		}
		$fields = isset( $get['data_field_key'] ) && '' !== (string) $get['data_field_key'] ? explode( ',', (string) $get['data_field_key'] ) : null;
		$rows   = array();
		foreach ( self::MEETINGS as $id => $m ) {
			if ( '1' !== $m[4] || ( $services && ! in_array( $m[0], $services, true ) ) ) {
				continue;
			}
			$row = array(
				'id_bigint'             => (string) $id,
				'meeting_name'          => $m[1],
				'weekday_tinyint'       => '2',
				'start_time'            => '19:00:00',
				'duration_time'         => '01:00:00',
				'service_body_bigint'   => (string) $m[0],
				'location_text'         => 'Hall ' . $id,
				'location_street'       => $id . ' Main Street',
				'location_municipality' => $m[2],
				'location_province'     => $m[3],
				'location_postal_code_1' => '00000',
				'published'             => $m[4],
				'comments'              => str_repeat( 'Meeting notes. ', 40 ),
			);
			if ( null !== $fields ) {
				$row = array_intersect_key( $row, array_flip( $fields ) );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * JSON response.
	 *
	 * @param mixed $data Data.
	 * @return array
	 */
	private static function json( $data ) {
		return array( 200, 'application/json', (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}
