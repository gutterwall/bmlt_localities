<?php
/**
 * Plugin Name:       BMLT Localities by Service Body
 * Plugin URI:        https://github.com/gutterwall/bmlt_localities
 * Description:       Displays unique meeting localities grouped by service body and state, from a BMLT root server.
 * Version:           1.4.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Richard Schellenberg
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bmlt-localities-by-service-body
 *
 * @package BMLT_Localities
 * @version 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page, BMLT client and the [bmlt_localities] shortcode.
 */
final class BMLT_Localities_By_Service_Body {
	const VERSION = '1.4.0';
	const OPTION  = 'bmlt_localities_settings';
	// Incremented on every save, so fresh cache entries from before the save are no longer used.
	const GENERATION = 'bmlt_localities_cache_generation';
	const PREFIX     = 'bmlt_loc_';
	const PAGE       = 'bmlt-localities';
	// How long a failed request is remembered before BMLT is asked again (pages stay fast during an outage).
	const RETRY = 5 * MINUTE_IN_SECONDS;
	// How long the last good answer is kept to show while BMLT is unreachable.
	const STALE = WEEK_IN_SECONDS;
	// Only the meeting fields the list needs are requested.
	const FIELDS = 'service_body_bigint,location_municipality,location_province';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_bmlt_localities_save', array( __CLASS__, 'save' ) );
		add_shortcode( 'bmlt_localities', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Saved settings: server (root server URL) and bodies (default service body IDs).
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), array( 'server' => '', 'bodies' => array() ) );
	}

	/**
	 * Positive integer IDs from an array or a comma-separated list.
	 *
	 * @param mixed $value IDs.
	 * @return int[]
	 */
	public static function ids( $value ) {
		if ( ! is_array( $value ) ) {
			$value = explode( ',', (string) $value );
		}
		$ids = array();
		foreach ( $value as $id ) {
			if ( ( is_int( $id ) || is_string( $id ) ) && preg_match( '/^\s*\d+\s*$/', (string) $id ) && (int) $id > 0 ) {
				$ids[] = (int) $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * A root server URL as entered (with or without /client_interface/json), or '' when it is not usable.
	 *
	 * @param string $value URL.
	 * @return string
	 */
	public static function server( $value ) {
		$url   = esc_url_raw( trim( (string) $value ) );
		$parts = wp_parse_url( $url );
		if ( ! $parts || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}
		return untrailingslashit( preg_replace( '~/(client_interface(?:/json)?|api/v1)/?$~i', '', $url ) );
	}

	/**
	 * A BMLT client_interface/json request, cached.
	 *
	 * Answers are kept for an hour (filter bmlt_localities_cache_ttl). When BMLT cannot be reached, the last good answer
	 * (up to a week old) is used instead, and BMLT is not asked again for five minutes.
	 *
	 * @param string $switcher BMLT switcher.
	 * @param string $query    Further query string (already encoded).
	 * @return array|WP_Error
	 */
	private static function fetch( $switcher, $query = '' ) {
		$settings = self::settings();
		if ( ! $settings['server'] ) {
			return new WP_Error( 'bmlt_missing_url', __( 'Set the BMLT root server URL first.', 'bmlt-localities-by-service-body' ) );
		}
		$url   = trailingslashit( $settings['server'] ) . 'client_interface/json/?switcher=' . rawurlencode( $switcher ) . ( '' !== $query ? '&' . $query : '' );
		$hash  = md5( $url );
		$fresh = self::PREFIX . (int) get_option( self::GENERATION, 0 ) . '_' . $hash;
		$stale = self::PREFIX . 'stale_' . $hash;

		$cached = get_transient( $fresh );
		if ( is_array( $cached ) && isset( $cached['data'] ) && is_array( $cached['data'] ) ) {
			return $cached['data'];
		}
		if ( is_array( $cached ) && isset( $cached['error'] ) ) {
			return new WP_Error( 'bmlt_unavailable', (string) $cached['error'] );
		}

		$data = self::request( $url );
		if ( ! is_wp_error( $data ) ) {
			$ttl = (int) apply_filters( 'bmlt_localities_cache_ttl', HOUR_IN_SECONDS, $switcher );
			set_transient( $fresh, array( 'data' => $data ), max( 60, $ttl ) );
			set_transient( $stale, array( 'data' => $data ), self::STALE );
			return $data;
		}
		$old = get_transient( $stale );
		if ( is_array( $old ) && isset( $old['data'] ) && is_array( $old['data'] ) ) {
			set_transient( $fresh, $old, self::RETRY );
			return $old['data'];
		}
		set_transient( $fresh, array( 'error' => $data->get_error_message() ), self::RETRY );
		return $data;
	}

	/**
	 * One request to BMLT; a JSON list, or an error.
	 *
	 * @param string $url URL.
	 * @return array|WP_Error
	 */
	private static function request( $url ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 2,
				'headers'             => array( 'Accept' => 'application/json' ),
				'limit_response_size' => 12000000,
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return new WP_Error( 'bmlt_http', sprintf( __( 'BMLT could not be reached: %s', 'bmlt-localities-by-service-body' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'bmlt_http', sprintf( __( 'BMLT returned HTTP %d.', 'bmlt-localities-by-service-body' ), $code ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ( array() !== $data && array_keys( $data ) !== range( 0, count( $data ) - 1 ) ) ) {
			return new WP_Error( 'bmlt_json', __( 'BMLT returned an unexpected response. Check the root server URL.', 'bmlt-localities-by-service-body' ) );
		}
		return $data;
	}

	/**
	 * Service bodies on the root server.
	 *
	 * @return array|WP_Error
	 */
	public static function bodies() {
		return self::fetch( 'GetServiceBodies' );
	}

	/**
	 * Published meetings of the given service bodies (each body's own meetings, not its children's).
	 *
	 * @param int[] $ids Service body IDs.
	 * @return array|WP_Error
	 */
	public static function meetings( array $ids ) {
		sort( $ids );
		$query = '';
		foreach ( $ids as $id ) {
			$query .= 'services[]=' . (int) $id . '&';
		}
		return self::fetch( 'GetSearchResults', $query . 'data_field_key=' . self::FIELDS );
	}

	/**
	 * Settings page menu entry.
	 *
	 * @return void
	 */
	public static function menu() {
		add_options_page(
			__( 'BMLT Localities', 'bmlt-localities-by-service-body' ),
			__( 'BMLT Localities', 'bmlt-localities-by-service-body' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'admin' )
		);
	}

	/**
	 * Settings page URL with a notice.
	 *
	 * @param string $notice Notice key.
	 * @return string
	 */
	private static function page_url( $notice ) {
		return add_query_arg( array( 'page' => self::PAGE, 'bmlt_notice' => $notice ), admin_url( 'options-general.php' ) );
	}

	/**
	 * Saves the settings form (admin-post.php).
	 *
	 * @return void
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'bmlt-localities-by-service-body' ), 403 );
		}
		check_admin_referer( 'bmlt_localities_save' );
		$url = self::server( isset( $_POST['server'] ) ? wp_unslash( $_POST['server'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by server().
		if ( ! $url ) {
			wp_safe_redirect( self::page_url( 'url' ) );
			exit;
		}
		$old = self::settings();
		if ( $url !== $old['server'] ) {
			// A new server has other service bodies: choose them again once its list is shown.
			$bodies = array();
		} elseif ( isset( $_POST['bodies_listed'] ) ) {
			$bodies = self::ids( isset( $_POST['bodies'] ) ? wp_unslash( (array) $_POST['bodies'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reduced to integers by ids().
		} else {
			// The list could not be loaded (BMLT unreachable), so the form had no checkboxes: keep the selection.
			$bodies = self::ids( $old['bodies'] );
		}
		sort( $bodies );
		update_option( self::OPTION, array( 'server' => $url, 'bodies' => $bodies ) );
		// Saving also refreshes the BMLT data.
		update_option( self::GENERATION, (int) get_option( self::GENERATION, 0 ) + 1 );
		wp_safe_redirect( self::page_url( $url !== $old['server'] ? 'server' : 'saved' ) );
		exit;
	}

	/**
	 * Settings page.
	 *
	 * @return void
	 */
	public static function admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::settings();
		$selected = self::ids( $settings['bodies'] );
		$bodies   = $settings['server'] ? self::bodies() : array();
		$notice   = isset( $_GET['bmlt_notice'] ) ? sanitize_key( wp_unslash( $_GET['bmlt_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.

		echo '<div class="wrap"><h1>' . esc_html__( 'BMLT Localities', 'bmlt-localities-by-service-body' ) . '</h1>';
		if ( 'url' === $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Enter a valid HTTPS BMLT root server URL, for example https://example.org/main_server.', 'bmlt-localities-by-service-body' ) . '</p></div>';
		} elseif ( 'server' === $notice ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Root server saved. Select the service bodies below and save again.', 'bmlt-localities-by-service-body' ) . '</p></div>';
		} elseif ( 'saved' === $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved. BMLT data has been refreshed.', 'bmlt-localities-by-service-body' ) . '</p></div>';
		}
		if ( is_wp_error( $bodies ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $bodies->get_error_message() ) . '</p>';
			if ( $selected ) {
				echo '<p>' . esc_html__( 'The service body list cannot be shown; saving keeps the current selection.', 'bmlt-localities-by-service-body' ) . '</p>';
			}
			echo '</div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bmlt_localities_save' );
		echo '<input type="hidden" name="action" value="bmlt_localities_save">';
		echo '<p><label for="bmlt-server">' . esc_html__( 'BMLT root server URL', 'bmlt-localities-by-service-body' ) . '</label></p>';
		echo '<p><input id="bmlt-server" type="url" required class="regular-text" name="server" placeholder="https://example.org/main_server" value="' . esc_attr( $settings['server'] ) . '"></p>';
		echo '<h2>' . esc_html__( 'Default service bodies', 'bmlt-localities-by-service-body' ) . '</h2>';
		echo '<p>' . wp_kses( __( 'Choose the service bodies shown by <code>[bmlt_localities]</code>. Child service bodies are selected separately.', 'bmlt-localities-by-service-body' ), array( 'code' => array() ) ) . '</p>';
		if ( is_array( $bodies ) && $settings['server'] ) {
			usort(
				$bodies,
				static function ( $a, $b ) {
					return strnatcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
				}
			);
			$found = array();
			echo '<input type="hidden" name="bodies_listed" value="1">';
			echo '<fieldset id="bmlt-bodies" style="max-height:320px;overflow:auto;background:#fff;padding:12px;border:1px solid #c3c4c7">';
			echo '<legend class="screen-reader-text">' . esc_html__( 'Service bodies', 'bmlt-localities-by-service-body' ) . '</legend>';
			foreach ( $bodies as $body ) {
				$id = is_array( $body ) ? self::ids( array( $body['id'] ?? '' ) ) : array();
				if ( ! $id ) {
					continue;
				}
				$id      = $id[0];
				$found[] = $id;
				$name    = trim( (string) ( $body['name'] ?? '' ) );
				echo '<label style="display:block;margin-bottom:6px"><input type="checkbox" name="bodies[]" value="' . esc_attr( (string) $id ) . '" ' . checked( in_array( $id, $selected, true ), true, false ) . '> '
					. esc_html( '' !== $name ? $name : __( 'Unnamed service body', 'bmlt-localities-by-service-body' ) ) . ' (' . esc_html( (string) $id ) . ')</label>';
			}
			echo '</fieldset>';
			$missing = array_diff( $selected, $found );
			if ( $missing ) {
				/* translators: %s: comma-separated service body IDs */
				echo '<p class="description">' . esc_html( sprintf( __( 'Selected service bodies no longer on the root server: %s. Saving removes them.', 'bmlt-localities-by-service-body' ), implode( ', ', $missing ) ) ) . '</p>';
			}
		}
		submit_button( __( 'Save settings', 'bmlt-localities-by-service-body' ) );
		echo '</form><p>' . wp_kses( __( 'Shortcode: <code>[bmlt_localities]</code> or <code>[bmlt_localities services="12,34"]</code>. The services attribute overrides the default selection.', 'bmlt-localities-by-service-body' ), array( 'code' => array() ) ) . '</p>';
		echo '<p>' . esc_html__( 'BMLT data is cached for an hour; saving refreshes it. If BMLT cannot be reached, the last list it sent (up to a week old) is shown.', 'bmlt-localities-by-service-body' ) . '</p></div>';
	}

	/**
	 * US state and territory names by postal code.
	 *
	 * @return string[]
	 */
	private static function state_names() {
		return array(
			'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado', // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'CT' => 'Connecticut', 'DE' => 'Delaware', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
			'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana',
			'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
			'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
			'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina',
			'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania',
			'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
			'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
			'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'DC' => 'District of Columbia', 'PR' => 'Puerto Rico',
			'VI' => 'U.S. Virgin Islands', 'GU' => 'Guam', 'AS' => 'American Samoa', 'MP' => 'Northern Mariana Islands',
		);
	}

	/**
	 * State as shown: a US postal code or name in any letter case becomes the full name; anything else is kept as
	 * entered.
	 *
	 * @param mixed $value location_province.
	 * @return string
	 */
	public static function state_name( $value ) {
		$value = trim( (string) $value );
		$key   = strtoupper( rtrim( $value, '.' ) );
		$names = self::state_names();
		if ( isset( $names[ $key ] ) ) {
			return $names[ $key ];
		}
		foreach ( $names as $name ) {
			if ( 0 === strcasecmp( $name, $value ) ) {
				return $name;
			}
		}
		return $value;
	}

	/**
	 * Case-insensitive key for grouping localities and states.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	private static function fold( $value ) {
		$value = preg_replace( '/\s+/u', ' ', $value );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value );
	}

	/**
	 * A service body's website as a link target, or '' (only http and https; "example.org" means https://example.org).
	 *
	 * @param string $url Website field.
	 * @return string
	 */
	public static function website( $url ) {
		$url = trim( $url );
		if ( '' !== $url && ! preg_match( '~^[a-z][a-z0-9+.-]*:~i', $url ) && preg_match( '~^[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}\.?([/?#:]|$)~i', $url ) ) {
			$url = 'https://' . $url;
		}
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! wp_parse_url( $url, PHP_URL_HOST ) ) {
			return '';
		}
		return esc_url( $url, array( 'http', 'https' ) );
	}

	/**
	 * The table's stylesheet, printed once however many lists a page has.
	 *
	 * @return void
	 */
	private static function enqueue_style() {
		if ( ! wp_style_is( 'bmlt-localities', 'registered' ) ) {
			wp_register_style( 'bmlt-localities', false, array(), self::VERSION );
			wp_add_inline_style( 'bmlt-localities', '.bmlt-localities-table{overflow-x:auto}.bmlt-localities-table table{width:100%;border-collapse:collapse;word-break:normal;overflow-wrap:break-word}.bmlt-localities-table tbody tr.bmlt-area-blue > *{background:#e8f4fc}.bmlt-localities-table tbody tr.bmlt-area-white > *{background:#fff}.bmlt-localities-table tbody tr > *{border:0;padding:.65em .8em;vertical-align:top}.bmlt-localities-table tbody tr.bmlt-area-start > *{border-top:4px solid #6c93ad}.bmlt-localities-table tbody th[scope="rowgroup"]{text-align:left;font-weight:600}' );
		}
		wp_enqueue_style( 'bmlt-localities' );
	}

	/**
	 * Error output: the reason for administrators, a short notice for visitors.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	private static function unavailable( WP_Error $error ) {
		if ( current_user_can( 'manage_options' ) ) {
			return '<p class="bmlt-localities-error">' . esc_html( $error->get_error_message() ) . '</p>';
		}
		return '<p class="bmlt-localities-error">' . esc_html__( 'Meeting locality data is temporarily unavailable.', 'bmlt-localities-by-service-body' ) . '</p>';
	}

	/**
	 * [bmlt_localities services="12,34"]
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts     = shortcode_atts( array( 'services' => null ), $atts, 'bmlt_localities' );
		$settings = self::settings();
		$ids      = self::ids( null === $atts['services'] ? $settings['bodies'] : $atts['services'] );
		if ( ! $settings['server'] || ! $ids ) {
			return current_user_can( 'manage_options' ) ? '<p>' . esc_html__( 'Configure the BMLT server and select service bodies under Settings → BMLT Localities.', 'bmlt-localities-by-service-body' ) . '</p>' : '';
		}
		$bodies = self::bodies();
		if ( is_wp_error( $bodies ) ) {
			return self::unavailable( $bodies );
		}
		$labels   = array();
		$phones   = array();
		$websites = array();
		foreach ( $bodies as $body ) {
			$id = is_array( $body ) ? self::ids( array( $body['id'] ?? '' ) ) : array();
			if ( $id && in_array( $id[0], $ids, true ) ) {
				$id              = $id[0];
				$name            = trim( (string) ( $body['name'] ?? '' ) );
				$labels[ $id ]   = '' !== $name ? $name : (string) $id;
				$phones[ $id ]   = trim( (string) ( $body['helpline'] ?? '' ) );
				$websites[ $id ] = self::website( (string) ( $body['url'] ?? '' ) );
			}
		}
		if ( ! $labels ) {
			return '<p>' . esc_html__( 'No matching service bodies were found.', 'bmlt-localities-by-service-body' ) . '</p>';
		}
		$meetings = self::meetings( array_keys( $labels ) );
		if ( is_wp_error( $meetings ) ) {
			return self::unavailable( $meetings );
		}
		// Each meeting counts for its own service body only; meetings of other bodies are ignored.
		$groups = array();
		foreach ( $meetings as $meeting ) {
			if ( ! is_array( $meeting ) ) {
				continue;
			}
			$id = self::ids( array( $meeting['service_body_bigint'] ?? '' ) );
			if ( ! $id || ! isset( $labels[ $id[0] ] ) ) {
				continue;
			}
			$city  = trim( preg_replace( '/\s+/u', ' ', (string) ( $meeting['location_municipality'] ?? '' ) ) );
			$state = self::state_name( $meeting['location_province'] ?? '' );
			if ( '' === $city || '' === $state ) {
				continue; // Virtual meetings without a place.
			}
			$skey = self::fold( $state );
			if ( ! isset( $groups[ $id[0] ][ $skey ] ) ) {
				$groups[ $id[0] ][ $skey ] = array( 'name' => $state, 'cities' => array() );
			}
			$ckey = self::fold( $city );
			if ( ! isset( $groups[ $id[0] ][ $skey ]['cities'][ $ckey ] ) ) {
				$groups[ $id[0] ][ $skey ]['cities'][ $ckey ] = $city;
			}
		}

		uasort( $labels, 'strnatcasecmp' );
		$rows = '';
		$band = 0;
		foreach ( $labels as $id => $label ) {
			if ( empty( $groups[ $id ] ) ) {
				continue;
			}
			$states = $groups[ $id ];
			uasort(
				$states,
				static function ( $a, $b ) {
					return strnatcasecmp( $a['name'], $b['name'] );
				}
			);
			$area = '' !== $websites[ $id ] ? '<a href="' . $websites[ $id ] . '">' . esc_html( $label ) . '</a>' : esc_html( $label );
			if ( '' !== $phones[ $id ] ) {
				$dial  = preg_replace( '/[^0-9+]/', '', $phones[ $id ] );
				$area .= ' - ' . ( preg_match( '/^\+?[0-9]{3,15}$/', $dial )
					? '<a href="' . esc_url( 'tel:' . $dial, array( 'tel' ) ) . '">' . esc_html( $phones[ $id ] ) . '</a>'
					: esc_html( $phones[ $id ] ) );
			}
			$class = 0 === $band % 2 ? 'bmlt-area-blue' : 'bmlt-area-white';
			$first = true;
			foreach ( $states as $state ) {
				$cities = $state['cities'];
				natcasesort( $cities );
				$rows .= '<tr class="' . esc_attr( $class . ( $first ? ' bmlt-area-start' : '' ) ) . '">';
				if ( $first ) {
					$rows .= '<th scope="rowgroup" rowspan="' . esc_attr( (string) count( $states ) ) . '">' . $area . '</th>';
				}
				$rows .= '<td>' . esc_html( $state['name'] ) . '</td><td>' . esc_html( implode( ', ', $cities ) ) . '</td></tr>';
				$first = false;
			}
			++$band;
		}
		if ( ! $rows ) {
			return '<p>' . esc_html__( 'No meeting localities found for the selected service bodies.', 'bmlt-localities-by-service-body' ) . '</p>';
		}
		self::enqueue_style();
		return '<div class="bmlt-localities-table"><table><thead><tr><th scope="col">' . esc_html__( 'Area - Phone Number', 'bmlt-localities-by-service-body' ) . '</th><th scope="col">' . esc_html__( 'State', 'bmlt-localities-by-service-body' ) . '</th><th scope="col">' . esc_html__( 'Localities', 'bmlt-localities-by-service-body' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
	}
}

BMLT_Localities_By_Service_Body::init();
