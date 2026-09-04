<?php
/**
 * Test bootstrap.
 *
 * These are unit tests, not integration tests: they load the plugin classes
 * whose logic is independent of the database and stub the handful of
 * WordPress functions those classes touch. That keeps the suite runnable with
 * nothing but PHP and PHPUnit, which is the difference between a suite that
 * runs on every change and one that nobody sets up.
 *
 * Anything that genuinely needs $wpdb (the queue repository's queries, the
 * AJAX handlers) is out of scope here and is covered by the static checks in
 * tools/verify.py plus manual testing.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPNC_PLUGIN_FILE', dirname( __DIR__ ) . '/wp-news-collector.php' );
define( 'WPNC_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'WPNC_PLUGIN_URL', 'https://example.test/wp-content/plugins/wp-news-collector/' );
define( 'WPNC_VERSION', '1.3.0' );

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

/**
 * In-memory option store, reset between tests.
 */
class WPNC_Test_Options {

	/**
	 * @var array
	 */
	public static $values = array();

	/**
	 * Settings notices captured from add_settings_error().
	 *
	 * @var array
	 */
	public static $notices = array();

	/**
	 * Reset all state.
	 */
	public static function reset() {
		self::$values  = array();
		self::$notices = array();
	}
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, WPNC_Test_Options::$values )
		? WPNC_Test_Options::$values[ $name ]
		: $default;
}

function update_option( $name, $value, $autoload = null ) {
	WPNC_Test_Options::$values[ $name ] = $value;

	return true;
}

function delete_option( $name ) {
	unset( WPNC_Test_Options::$values[ $name ] );

	return true;
}

function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	WPNC_Test_Options::$notices[] = array(
		'setting' => $setting,
		'code'    => $code,
		'message' => $message,
		'type'    => $type,
	);
}

function absint( $value ) {
	return abs( (int) $value );
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function sanitize_text_field( $value ) {
	$value = strip_tags( (string) $value );

	return trim( preg_replace( '/[\r\n\t ]+/', ' ', $value ) );
}

function wp_strip_all_tags( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function wp_specialchars_decode( $value ) {
	return html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_url_raw( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}

	// Close enough to WordPress for these tests: require a scheme we allow
	// and a host, and reject anything with control characters or spaces.
	if ( preg_match( '/[\s\x00-\x1f]/', $url ) ) {
		return '';
	}

	$parts = wp_parse_url( $url );
	if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}

	if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		return '';
	}

	return $url;
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

function wp_parse_url( $url, $component = -1 ) {
	$parts = parse_url( $url );
	if ( false === $parts ) {
		return false;
	}

	if ( -1 === $component ) {
		return $parts;
	}

	$map = array(
		PHP_URL_SCHEME => 'scheme',
		PHP_URL_HOST   => 'host',
		PHP_URL_PATH   => 'path',
	);

	$key = isset( $map[ $component ] ) ? $map[ $component ] : null;

	return ( $key && isset( $parts[ $key ] ) ) ? $parts[ $key ] : null;
}

function term_exists( $term, $taxonomy = '' ) {
	return in_array( (int) $term, WPNC_Test_Options::$values['__terms'] ?? array(), true ) ? array( 'term_id' => (int) $term ) : null;
}

function get_userdata( $user_id ) {
	return in_array( (int) $user_id, WPNC_Test_Options::$values['__users'] ?? array(), true )
		? (object) array( 'ID' => (int) $user_id )
		: false;
}

function wp_trim_words( $text, $words = 55, $more = null ) {
	$parts = preg_split( '/\s+/', trim( (string) $text ) );
	if ( count( $parts ) <= $words ) {
		return implode( ' ', $parts );
	}

	return implode( ' ', array_slice( $parts, 0, $words ) ) . ( null === $more ? '' : $more );
}

function wp_kses_post( $value ) {
	return (string) $value;
}


function wp_date( $format, $timestamp = null ) {
	return gmdate( $format, $timestamp );
}

function __( $text, $domain = '' ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return esc_url_raw( $url );
}

function add_query_arg( $key, $value, $url ) {
	$glue = false === strpos( $url, '?' ) ? '?' : '&';

	return $url . $glue . $key . '=' . $value;
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

require_once WPNC_PLUGIN_DIR . 'includes/class-settings.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-filter.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-feed-reader.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-queue-repository.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-ai-providers.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-ai-keys.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-ai-rewriter.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-template.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-scheduler.php';
