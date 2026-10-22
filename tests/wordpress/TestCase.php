<?php
/**
 * Base class: the plugin pointed at the fake BMLT root server (https://bmlt.test/main_server), BMLT requests
 * answered in-process and recorded, redirects caught, and users of each kind.
 */

namespace BmltLocTest;

use BMLT_Localities_By_Service_Body as Plugin;

/**
 * Thrown instead of redirecting (wp_redirect would exit).
 */
class Redirected extends \Exception {
	/**
	 * Location.
	 *
	 * @var string
	 */
	public $location;

	public function __construct( $location ) {
		parent::__construct( 'Redirect to ' . $location );
		$this->location = $location;
	}
}

abstract class TestCase extends \WP_UnitTestCase {

	const BMLT = 'https://bmlt.test/main_server';

	/** @var int */
	public static $admin;
	/** @var int */
	public static $editor;

	/**
	 * Requests BMLT received in this test: [switcher, query array, url].
	 *
	 * @var array
	 */
	public static $requests = array();

	/**
	 * Fault: '' (none), 'down' (HTTP 503), 'html' (HTTP 200 HTML page), 'object' (a JSON object, not a list),
	 * 'timeout' (no answer), 'ignore-services' (every meeting, whatever services[] asked for).
	 *
	 * @var string
	 */
	public static $fault = '';

	public static function wpSetUpBeforeClass( \WP_UnitTest_Factory $factory ) {
		self::$admin  = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor = $factory->user->create( array( 'role' => 'editor' ) );
		if ( is_multisite() ) {
			grant_super_admin( self::$admin );
		}
	}

	public function set_up() {
		parent::set_up();
		self::$requests = array();
		self::$fault    = '';
		add_filter( 'pre_http_request', array( __CLASS__, 'fake_http' ), 10, 3 );
		add_filter( 'wp_redirect', array( __CLASS__, 'throw_redirect' ), 1 );
		wp_set_current_user( 0 );
		$_POST = array();
		$_GET  = array();
		$_REQUEST = array();
		wp_styles();
		wp_dequeue_style( 'bmlt-localities' );
		wp_deregister_style( 'bmlt-localities' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( __CLASS__, 'fake_http' ), 10 );
		remove_filter( 'wp_redirect', array( __CLASS__, 'throw_redirect' ), 1 );
		$_POST = array();
		$_GET  = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Saved settings, as the settings page stores them.
	 *
	 * @param int[]  $bodies Default service bodies.
	 * @param string $server Root server URL.
	 */
	protected function configure( array $bodies = array( 101 ), $server = self::BMLT ) {
		update_option( Plugin::OPTION, array( 'server' => $server, 'bodies' => $bodies ) );
	}

	/**
	 * Answers requests to bmlt.test from the fake BMLT root server; nothing else may leave the test.
	 */
	public static function fake_http( $pre, $args, $url ) {
		$parts = wp_parse_url( $url );
		if ( ! isset( $parts['host'] ) || 'bmlt.test' !== $parts['host'] ) {
			return new \WP_Error( 'http_request_failed', 'Tests do not make network requests: ' . $url );
		}
		$get = array();
		parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $get );
		self::$requests[] = array( isset( $get['switcher'] ) ? $get['switcher'] : '', $get, $url );
		if ( 'timeout' === self::$fault ) {
			return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}
		if ( 'down' === self::$fault ) {
			return self::response( 503, 'text/plain', 'Service unavailable' );
		}
		if ( 'html' === self::$fault ) {
			return self::response( 200, 'text/html', '<!DOCTYPE html><html><body>Welcome</body></html>' );
		}
		if ( 'object' === self::$fault ) {
			return self::response( 200, 'application/json', '{"error":"no"}' );
		}
		if ( ! preg_match( '#^/main_server/client_interface/json/?$#', isset( $parts['path'] ) ? $parts['path'] : '' ) ) {
			return self::response( 404, 'text/html', 'Not found' );
		}
		if ( 'ignore-services' === self::$fault ) {
			unset( $get['services'] );
		}
		list( $status, $type, $body ) = FakeBmlt::handle( $get );
		return self::response( $status, $type, $body );
	}

	private static function response( $status, $type, $body ) {
		return array(
			'headers'  => array( 'content-type' => $type ),
			'body'     => $body,
			'response' => array( 'code' => $status, 'message' => get_status_header_desc( $status ) ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Switchers requested so far.
	 *
	 * @return string[]
	 */
	protected function switchers() {
		return array_column( self::$requests, 0 );
	}

	/**
	 * @throws Redirected Always.
	 */
	public static function throw_redirect( $location ) {
		throw new Redirected( $location );
	}

	/**
	 * Submits the settings form as the current user; returns the redirect target.
	 *
	 * @param array $post Fields (server, bodies, bodies_listed).
	 * @param bool  $nonce Whether to send a valid nonce.
	 * @return string
	 */
	protected function save( array $post, $nonce = true ) {
		$_POST                     = $post + array( 'action' => 'bmlt_localities_save' );
		$_POST['_wpnonce']         = $nonce ? wp_create_nonce( 'bmlt_localities_save' ) : 'bad';
		$_REQUEST                  = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		// The form posts slashed data, as WordPress adds slashes to $_POST.
		$_POST    = wp_slash( $_POST );
		$_REQUEST = $_POST;
		try {
			Plugin::save();
		} catch ( Redirected $r ) {
			return $r->location;
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
		$this->fail( 'save() did not redirect' );
	}

	/**
	 * The settings page's HTML.
	 */
	protected function admin_page( array $get = array() ) {
		$_GET = $get;
		ob_start();
		Plugin::admin();
		$html = ob_get_clean();
		$_GET = array();
		return $html;
	}

	/**
	 * Table rows of the list as [area cell text, state, localities].
	 *
	 * @param string $html Shortcode output.
	 * @return array
	 */
	protected function rows( $html ) {
		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();
		$rows = array();
		$area = '';
		foreach ( $doc->getElementsByTagName( 'tbody' )->item( 0 )->getElementsByTagName( 'tr' ) as $tr ) {
			$th = $tr->getElementsByTagName( 'th' );
			if ( $th->length ) {
				$area = trim( $th->item( 0 )->textContent );
			}
			$td     = $tr->getElementsByTagName( 'td' );
			$rows[] = array( $area, $td->item( 0 )->textContent, $td->item( 1 )->textContent );
		}
		return $rows;
	}

	/**
	 * The page as DOM, for attribute checks.
	 */
	protected function dom( $html ) {
		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();
		return new \DOMXPath( $doc );
	}

	/**
	 * Ages every fresh cache entry out (as if the hour had passed); the week-long last-good copies stay.
	 */
	protected function expire_fresh_cache() {
		global $wpdb;
		$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_bmlt\\_loc\\_%' AND option_name NOT LIKE '%\\_stale\\_%'" );
		foreach ( $names as $name ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		}
		wp_cache_flush();
	}
}
