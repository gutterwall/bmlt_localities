<?php
/**
 * Plugin Name: BMLT Localities by Service Body
 * Description: Displays unique meeting localities grouped by service body and state.
 * Version: 1.3.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class BMLT_Localities_By_Service_Body {
    const OPTION = 'bmlt_localities_settings';
    const TTL = HOUR_IN_SECONDS;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_bmlt_localities_save', array( __CLASS__, 'save' ) );
        add_shortcode( 'bmlt_localities', array( __CLASS__, 'shortcode' ) );
    }

    private static function settings() {
        return wp_parse_args( get_option( self::OPTION, array() ), array( 'server' => '', 'bodies' => array() ) );
    }

    private static function ids( $value ) {
        if ( ! is_array( $value ) ) { $value = explode( ',', (string) $value ); }
        return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
    }

    private static function server( $value ) {
        $url = esc_url_raw( trim( (string) $value ) );
        $parts = wp_parse_url( $url );
        if ( ! $parts || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) { return ''; }
        $path = preg_replace( '~/(client_interface(?:/json)?|api/v1)/?$~i', '', $path );
        return untrailingslashit( preg_replace( '~/(client_interface(?:/json)?|api/v1)/?$~i', '', $url ) );
    }

    private static function fetch( $switcher, $args = array() ) {
        $settings = self::settings();
        if ( ! $settings['server'] ) { return new WP_Error( 'bmlt_missing_url', 'Set the BMLT server URL first.' ); }
        $url = trailingslashit( $settings['server'] ) . 'client_interface/json/';
        $url = add_query_arg( array_merge( array( 'switcher' => $switcher ), $args ), $url );
        $key = 'bmlt_loc_' . md5( $url );
        $cached = get_transient( $key );
        if ( false !== $cached ) { return $cached; }
        $response = wp_safe_remote_get( $url, array( 'timeout' => 20, 'redirection' => 2, 'headers' => array( 'Accept' => 'application/json' ), 'limit_response_size' => 12000000 ) );
        if ( is_wp_error( $response ) ) { return $response; }
        if ( 200 !== wp_remote_retrieve_response_code( $response ) ) { return new WP_Error( 'bmlt_http', 'BMLT returned HTTP ' . wp_remote_retrieve_response_code( $response ) . '.' ); }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || array_keys( $data ) !== range( 0, count( $data ) - 1 ) && array() !== $data ) { return new WP_Error( 'bmlt_json', 'BMLT returned an unexpected response.' ); }
        set_transient( $key, $data, self::TTL );
        return $data;
    }

    private static function bodies() { return self::fetch( 'GetServiceBodies' ); }

    public static function menu() {
        add_options_page( 'BMLT Localities', 'BMLT Localities', 'manage_options', 'bmlt-localities', array( __CLASS__, 'admin' ) );
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Access denied.' ); }
        check_admin_referer( 'bmlt_localities_save' );
        $url = self::server( isset( $_POST['server'] ) ? wp_unslash( $_POST['server'] ) : '' );
        if ( ! $url ) {
            wp_safe_redirect( add_query_arg( 'bmlt_notice', 'url', admin_url( 'options-general.php?page=bmlt-localities' ) ) ); exit;
        }
        $old = self::settings();
        $selected = self::ids( isset( $_POST['bodies'] ) ? wp_unslash( $_POST['bodies'] ) : array() );
        // When the URL changes, load its body list before accepting selected IDs.
        update_option( self::OPTION, array( 'server' => $url, 'bodies' => $url === $old['server'] ? $selected : array() ) );
        wp_safe_redirect( add_query_arg( 'bmlt_notice', 'saved', admin_url( 'options-general.php?page=bmlt-localities' ) ) ); exit;
    }

    public static function admin() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $settings = self::settings();
        $bodies = $settings['server'] ? self::bodies() : array();
        echo '<div class="wrap"><h1>BMLT Localities</h1>';
        $notice = isset( $_GET['bmlt_notice'] ) ? sanitize_key( wp_unslash( $_GET['bmlt_notice'] ) ) : '';
        if ( 'url' === $notice ) { echo '<div class="notice notice-error"><p>Enter a valid HTTPS BMLT root server URL.</p></div>'; }
        if ( 'saved' === $notice ) { echo '<div class="notice notice-success"><p>Settings saved. If you changed the server, select the service bodies and save again.</p></div>'; }
        if ( is_wp_error( $bodies ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $bodies->get_error_message() ) . '</p></div>'; }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'bmlt_localities_save' );
        echo '<input type="hidden" name="action" value="bmlt_localities_save"><p><label for="bmlt-server">BMLT root server URL</label></p><p><input id="bmlt-server" type="url" required class="regular-text" name="server" placeholder="https://example.org/main_server" value="' . esc_attr( $settings['server'] ) . '"></p>';
        echo '<h2>Default service bodies</h2><p>Choose the service bodies shown by <code>[bmlt_localities]</code>. Child service bodies are selected separately.</p>';
        if ( is_array( $bodies ) ) {
            usort( $bodies, function( $a, $b ) { return strcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) ); } );
            echo '<fieldset style="max-height:320px;overflow:auto;background:#fff;padding:12px;border:1px solid #c3c4c7">';
            foreach ( $bodies as $body ) {
                $id = absint( $body['id'] ?? 0 );
                if ( ! $id ) { continue; }
                echo '<label style="display:block;margin-bottom:6px"><input type="checkbox" name="bodies[]" value="' . esc_attr( $id ) . '" ' . checked( in_array( $id, self::ids( $settings['bodies'] ), true ), true, false ) . '> ' . esc_html( $body['name'] ?? 'Unnamed service body' ) . ' (' . esc_html( $id ) . ')</label>';
            }
            echo '</fieldset>';
        }
        submit_button( 'Save settings' );
        echo '</form><p>Shortcode: <code>[bmlt_localities]</code> or <code>[bmlt_localities services="12,34"]</code>. The services attribute overrides the default selection.</p></div>';
    }

    private static function state_name( $value ) {
        $names = array( 'AL'=>'Alabama','AK'=>'Alaska','AZ'=>'Arizona','AR'=>'Arkansas','CA'=>'California','CO'=>'Colorado','CT'=>'Connecticut','DE'=>'Delaware','FL'=>'Florida','GA'=>'Georgia','HI'=>'Hawaii','ID'=>'Idaho','IL'=>'Illinois','IN'=>'Indiana','IA'=>'Iowa','KS'=>'Kansas','KY'=>'Kentucky','LA'=>'Louisiana','ME'=>'Maine','MD'=>'Maryland','MA'=>'Massachusetts','MI'=>'Michigan','MN'=>'Minnesota','MS'=>'Mississippi','MO'=>'Missouri','MT'=>'Montana','NE'=>'Nebraska','NV'=>'Nevada','NH'=>'New Hampshire','NJ'=>'New Jersey','NM'=>'New Mexico','NY'=>'New York','NC'=>'North Carolina','ND'=>'North Dakota','OH'=>'Ohio','OK'=>'Oklahoma','OR'=>'Oregon','PA'=>'Pennsylvania','RI'=>'Rhode Island','SC'=>'South Carolina','SD'=>'South Dakota','TN'=>'Tennessee','TX'=>'Texas','UT'=>'Utah','VT'=>'Vermont','VA'=>'Virginia','WA'=>'Washington','WV'=>'West Virginia','WI'=>'Wisconsin','WY'=>'Wyoming','DC'=>'District of Columbia','PR'=>'Puerto Rico','VI'=>'U.S. Virgin Islands','GU'=>'Guam','AS'=>'American Samoa','MP'=>'Northern Mariana Islands' );
        $value = trim( (string) $value );
        return $names[ strtoupper( $value ) ] ?? $value;
    }

    public static function shortcode( $atts ) {
        $atts = shortcode_atts( array( 'services' => null ), $atts, 'bmlt_localities' );
        $settings = self::settings();
        $ids = self::ids( null === $atts['services'] ? $settings['bodies'] : $atts['services'] );
        if ( ! $settings['server'] || ! $ids ) { return current_user_can( 'manage_options' ) ? '<p>Configure the BMLT server and select service bodies under Settings → BMLT Localities.</p>' : ''; }
        $bodies = self::bodies();
        if ( is_wp_error( $bodies ) ) { return current_user_can( 'manage_options' ) ? '<p>' . esc_html( $bodies->get_error_message() ) . '</p>' : ''; }
        $labels = array();
        $phones = array();
        $websites = array();
        foreach ( $bodies as $body ) {
            $id = absint( $body['id'] ?? 0 );
            if ( $id && in_array( $id, $ids, true ) ) {
                $labels[ $id ] = (string) ( $body['name'] ?? $id );
                $phones[ $id ] = trim( (string) ( $body['helpline'] ?? $body['helpline_number'] ?? '' ) );
                $websites[ $id ] = trim( (string) ( $body['url'] ?? $body['website'] ?? '' ) );
            }
        }
        if ( ! $labels ) { return '<p>No matching service bodies were found.</p>'; }
        // Query each body explicitly, then verify each returned meeting's assigned body.
        $groups = array();
        foreach ( $labels as $id => $label ) {
            $meetings = self::fetch( 'GetSearchResults', array( 'services' => $id ) );
            if ( is_wp_error( $meetings ) ) { return current_user_can( 'manage_options' ) ? '<p>' . esc_html( $meetings->get_error_message() ) . '</p>' : '<p>Meeting locality data is temporarily unavailable.</p>'; }
            foreach ( $meetings as $meeting ) {
                if ( ! is_array( $meeting ) || absint( $meeting['service_body_bigint'] ?? 0 ) !== $id ) { continue; }
                $city = trim( (string) ( $meeting['location_municipality'] ?? '' ) );
                $state = self::state_name( $meeting['location_province'] ?? '' );
                if ( '' === $city || '' === $state ) { continue; }
                $groups[ $id ][ $state ][ strtolower( $city ) ] = $city;
            }
        }
        uasort( $labels, 'strnatcasecmp' );
        $rows = '';
        $area_index = 0;
        foreach ( $labels as $id => $label ) {
            if ( empty( $groups[ $id ] ) ) { continue; }
            uksort( $groups[ $id ], 'strnatcasecmp' );
            $website = $websites[ $id ];
            $scheme = wp_parse_url( $website, PHP_URL_SCHEME );
            $area = $website && in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) && wp_parse_url( $website, PHP_URL_HOST )
                ? '<a href="' . esc_url( $website ) . '">' . esc_html( $label ) . '</a>'
                : esc_html( $label );
            if ( '' !== $phones[ $id ] ) {
                $dial = preg_replace( '/[^0-9+]/', '', $phones[ $id ] );
                $area .= ' - ' . ( preg_match( '/^\+?[0-9]{3,15}$/', $dial )
                    ? '<a href="' . esc_url( 'tel:' . $dial ) . '">' . esc_html( $phones[ $id ] ) . '</a>'
                    : esc_html( $phones[ $id ] ) );
            }
            $band = 0 === $area_index % 2 ? 'bmlt-area-blue' : 'bmlt-area-white';
            $first = true;
            $state_count = count( $groups[ $id ] );
            foreach ( $groups[ $id ] as $state => $cities ) {
                natcasesort( $cities );
                $rows .= '<tr class="' . esc_attr( $band . ( $first ? ' bmlt-area-start' : '' ) ) . '">';
                if ( $first ) {
                    $rows .= '<th scope="rowgroup" rowspan="' . esc_attr( $state_count ) . '">' . $area . '</th>';
                }
                $rows .= '<td>' . esc_html( $state ) . '</td><td>' . esc_html( implode( ', ', $cities ) ) . '</td></tr>';
                $first = false;
            }
            ++$area_index;
        }
        if ( ! $rows ) { return '<p>No meeting localities found for the selected service bodies.</p>'; }
        $style = '<style>.bmlt-localities-table table{width:100%;border-collapse:collapse}.bmlt-localities-table tbody tr.bmlt-area-blue > *{background:#e8f4fc}.bmlt-localities-table tbody tr.bmlt-area-white > *{background:#fff}.bmlt-localities-table tbody tr > *{border:0;padding:.65em .8em;vertical-align:top}.bmlt-localities-table tbody tr.bmlt-area-start > *{border-top:4px solid #6c93ad}.bmlt-localities-table tbody th[scope="rowgroup"]{text-align:left;font-weight:600}</style>';
        return $style . '<div class="bmlt-localities-table" style="overflow-x:auto"><table><thead><tr><th scope="col">Area - Phone Number</th><th scope="col">State</th><th scope="col">Localities</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }
}
BMLT_Localities_By_Service_Body::init();
