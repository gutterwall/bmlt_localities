<?php
/**
 * HTTP front for the fake BMLT root server:
 *
 *   php -S 127.0.0.1:8090 fake-bmlt/router.php
 *
 * The plugin needs HTTPS: put a TLS proxy in front (bin/e2e.sh uses nginx), then https://127.0.0.1:<port>/main_server
 *
 * Faults for testing what the plugin does when BMLT misbehaves, chosen by a path prefix:
 *   /down/main_server   every request answers HTTP 503
 *   /html/main_server   every request answers HTTP 200 with an HTML page (a wrong URL usually looks like this)
 *
 * or, for every path, by the first word of the file named by $FAKE_BMLT_FAULT_FILE ("down" or "html"; an empty
 * or missing file means no fault), so a test can take BMLT down without changing the plugin's settings.
 *
 * A request log (one JSON line per request) is appended to $FAKE_BMLT_LOG when that is set.
 */

require __DIR__ . '/src/FakeBmlt.php';

$bmlt_path = (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH );
$bmlt_log  = getenv( 'FAKE_BMLT_LOG' );
if ( $bmlt_log ) {
	file_put_contents( $bmlt_log, json_encode( array( 'path' => $bmlt_path, 'query' => $_GET ) ) . "\n", FILE_APPEND | LOCK_EX );
}

if ( ! preg_match( '#^/(?:(down|html)/)?main_server/client_interface/json/?$#', $bmlt_path, $bmlt_m ) ) {
	http_response_code( 404 );
	header( 'Content-Type: text/plain' );
	echo "Not found\n";
	return true;
}
$bmlt_fault = isset( $bmlt_m[1] ) ? $bmlt_m[1] : '';
$bmlt_file  = getenv( 'FAKE_BMLT_FAULT_FILE' );
if ( '' === $bmlt_fault && $bmlt_file && is_file( $bmlt_file ) ) {
	$bmlt_fault = trim( (string) file_get_contents( $bmlt_file ) );
}
if ( 'down' === $bmlt_fault ) {
	http_response_code( 503 );
	header( 'Content-Type: text/plain' );
	echo "Service unavailable\n";
	return true;
}
if ( 'html' === $bmlt_fault ) {
	header( 'Content-Type: text/html' );
	echo "<!DOCTYPE html><html><body><h1>Welcome</h1></body></html>\n";
	return true;
}

list( $bmlt_status, $bmlt_type, $bmlt_body ) = \BmltLocTest\FakeBmlt::handle( $_GET );
http_response_code( $bmlt_status );
header( 'Content-Type: ' . $bmlt_type . '; charset=utf-8' );
echo $bmlt_body;
return true;
