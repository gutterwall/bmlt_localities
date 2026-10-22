<?php
/**
 * State names, website links and ID lists.
 */

namespace BmltLocTest;

use BMLT_Localities_By_Service_Body as Plugin;

class HelpersTest extends TestCase {

	public function test_state_names() {
		foreach ( array( 'VA', 'va', 'Va.', ' Virginia ', 'VIRGINIA', 'virginia' ) as $in ) {
			$this->assertSame( 'Virginia', Plugin::state_name( $in ), $in );
		}
		$this->assertSame( 'District of Columbia', Plugin::state_name( 'dc' ) );
		$this->assertSame( 'U.S. Virgin Islands', Plugin::state_name( 'VI' ) );
		$this->assertSame( 'ON', Plugin::state_name( 'ON' ) );
		$this->assertSame( 'Ontario', Plugin::state_name( 'Ontario' ) );
		$this->assertSame( '', Plugin::state_name( null ) );
		$this->assertSame( '', Plugin::state_name( '  ' ) );
	}

	public function test_websites() {
		$cases = array(
			'https://example.org/'     => 'https://example.org/',
			'Https://Example.org'      => 'https://Example.org',
			'http://example.org'       => 'http://example.org',
			'example.org'              => 'https://example.org',
			'www.example.org/area?x=1' => 'https://www.example.org/area?x=1',
			'twinriversna.org./'       => 'https://twinriversna.org./',
			'javascript:alert(1)'      => '',
			'data:text/html,hi'        => '',
			'ftp://example.org'        => '',
			'mailto:a@example.org'     => '',
			'not a website'            => '',
			'https://'                 => '',
			''                         => '',
		);
		foreach ( $cases as $in => $out ) {
			$this->assertSame( $out, Plugin::website( (string) $in ), (string) $in );
		}
		$this->assertSame( 'https://example.org/?a=1&#038;b=2', Plugin::website( 'https://example.org/?a=1&b="2"' ) );
	}

	public function test_ids() {
		$this->assertSame( array( 12, 34 ), Plugin::ids( '12, 34,x,-5,0,12, 3.5' ) );
		$this->assertSame( array( 7, 8 ), Plugin::ids( array( '7', 8, '8', array( 9 ), null, true, ' 0' ) ) );
		$this->assertSame( array(), Plugin::ids( '' ) );
		$this->assertSame( array(), Plugin::ids( null ) );
	}
}
