<?php
/**
 * Stand-alone checks for the Phase 3 payment logic that needs no live WordPress or Paystack
 * connection: php tests/run-payments.php
 *
 * Covers: BD_Paystack::verify_signature() (the webhook's only line of defence), and BD_Settings'
 * payment-related defaults/sanitisation. Stubs just enough of the WordPress option API for
 * BD_Settings and BD_Paystack to load standalone, the same way tests/run.php and
 * tests/run-diagnostic.php avoid needing a database.
 *
 * @package BusinessDoctor
 */

define( 'BD_TESTING', true );
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['bd_test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Minimal get_option() stub backed by an in-memory array.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['bd_test_options'] ) ? $GLOBALS['bd_test_options'][ $name ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Minimal update_option() stub.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 */
	function update_option( $name, $value ) {
		$GLOBALS['bd_test_options'][ $name ] = $value;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Minimal wp_json_encode() stub.
	 *
	 * @param mixed $data Data.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return wp_json_encode_real( $data );
	}
	/**
	 * Avoids recursive self-reference above while keeping the same name test code expects.
	 *
	 * @param mixed $data Data.
	 * @return string|false
	 */
	function wp_json_encode_real( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'add_settings_error' ) ) {
	/**
	 * No-op stub: BD_Settings::sanitize() calls this only on an invalid threshold combination, which
	 * these tests never exercise.
	 */
	function add_settings_error() {}
}
if ( ! function_exists( 'sanitize_email' ) ) {
	/**
	 * Minimal stub.
	 *
	 * @param string $v Value.
	 * @return string
	 */
	function sanitize_email( $v ) {
		return (string) $v;
	}
}
if ( ! function_exists( 'is_email' ) ) {
	/**
	 * Minimal stub.
	 *
	 * @param string $v Value.
	 * @return bool
	 */
	function is_email( $v ) {
		return (bool) filter_var( $v, FILTER_VALIDATE_EMAIL );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Minimal stub.
	 *
	 * @param string $v Value.
	 * @return string
	 */
	function esc_url_raw( $v ) {
		return (string) $v;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Minimal stub.
	 *
	 * @param string $v Value.
	 * @return string
	 */
	function sanitize_text_field( $v ) {
		return trim( (string) $v );
	}
}

if ( ! class_exists( 'BD_Frameworks' ) ) {
	/**
	 * Minimal stand-in for BD_Frameworks: BD_Settings::sanitize() only needs the platform codes.
	 */
	class BD_Frameworks {
		/**
		 * The three real platform codes, enough for BD_Settings::sanitize() to validate against.
		 *
		 * @return array
		 */
		public static function platforms() {
			return array(
				'SME360'      => array(),
				'AGRIFOOD360' => array(),
				'ESO360'      => array(),
			);
		}
	}
}

require __DIR__ . '/../includes/class-bd-settings.php';
require __DIR__ . '/../includes/class-bd-paystack.php';

$fails  = 0;
$checks = 0;

/**
 * Records one check.
 *
 * @param bool   $ok  Passed.
 * @param string $msg Message on failure.
 */
function bd_check( $ok, $msg ) {
	global $fails, $checks;
	$checks++;
	if ( ! $ok ) {
		$fails++;
		fwrite( STDERR, "FAIL: $msg\n" );
	}
}

/* BD_Settings defaults include the new payment fields, off by default. */
$defaults = BD_Settings::defaults();
bd_check( 0 === $defaults['payments_enabled'], 'payments_enabled defaults off' );
bd_check( '' === $defaults['paystack_secret_key'], 'paystack_secret_key defaults empty' );
bd_check( isset( $defaults['platform_prices_ghs']['SME360'] ), 'platform_prices_ghs has SME360' );

/* Sanitisation: a blank key submission keeps the previously saved key. */
update_option( 'bd_settings', array( 'paystack_secret_key' => 'sk_test_existing' ) );
$out = BD_Settings::sanitize( array( 'paystack_secret_key' => '' ) );
bd_check( 'sk_test_existing' === $out['paystack_secret_key'], 'Blank key submission keeps the saved key' );

$out2 = BD_Settings::sanitize( array( 'paystack_secret_key' => '  sk_test_new  ' ) );
bd_check( 'sk_test_new' === $out2['paystack_secret_key'], 'A new key is trimmed and saved' );

/* Prices: negative input is clamped to 0, unknown platforms are ignored. */
update_option( 'bd_settings', array() );
$out3 = BD_Settings::sanitize( array( 'platform_prices_ghs' => array(
	'SME360'  => -50,
	'BOGUS'   => 999,
) ) );
bd_check( 0.0 === (float) $out3['platform_prices_ghs']['SME360'], 'Negative price clamps to 0' );
bd_check( ! isset( $out3['platform_prices_ghs']['BOGUS'] ), 'Unknown platform is dropped from prices' );

/* BD_Paystack::verify_signature(): HMAC-SHA512 of the raw body, keyed with the secret. This is the
 * webhook's only authentication, so it must reject a wrong signature, a tampered body, and a call
 * with no secret key configured, and accept the correct one. */
update_option( 'bd_settings', array( 'paystack_secret_key' => 'sk_test_abc123' ) );
$body      = '{"event":"charge.success","data":{"reference":"BD-SME360-ABCDE12345","amount":50000,"currency":"GHS"}}';
$good_sig  = hash_hmac( 'sha512', $body, 'sk_test_abc123' );
bd_check( true === BD_Paystack::verify_signature( $body, $good_sig ), 'Correct signature verifies' );
bd_check( false === BD_Paystack::verify_signature( $body, 'deadbeef' ), 'Wrong signature is rejected' );
bd_check( false === BD_Paystack::verify_signature( $body . 'x', $good_sig ), 'Tampered body invalidates the signature' );
bd_check( false === BD_Paystack::verify_signature( $body, '' ), 'Empty signature is rejected' );

update_option( 'bd_settings', array( 'paystack_secret_key' => '' ) );
bd_check( false === BD_Paystack::is_configured(), 'Not configured without a key' );
bd_check( false === BD_Paystack::verify_signature( $body, $good_sig ), 'No key configured means every signature fails' );

update_option( 'bd_settings', array( 'paystack_secret_key' => 'sk_live_xyz' ) );
bd_check( true === BD_Paystack::is_live_key(), 'sk_live_ prefix is recognised as a live key' );
update_option( 'bd_settings', array( 'paystack_secret_key' => 'sk_test_xyz' ) );
bd_check( false === BD_Paystack::is_live_key(), 'sk_test_ prefix is not a live key' );

fwrite( STDOUT, "$checks checks, $fails failed\n" );
exit( $fails ? 1 : 0 );
