<?php
/**
 * Simple per-visitor rate limiting for the public endpoints, using transients.
 * Visitors are identified by a salted hash of their IP address; the address itself is never stored.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Rate_Limit {

	/**
	 * The visitor's IP address. Proxy headers are read only when the site owner says the site sits
	 * behind a trusted proxy (for example Cloudflare), because visitors can forge those headers.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( BD_Settings::get( 'trust_proxy' ) ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ) as $h ) {
				if ( ! empty( $_SERVER[ $h ] ) ) {
					$first = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ) )[0] );
					if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
						return $first;
					}
				}
			}
		}
		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : 'unknown';
	}

	/**
	 * Salted hash of the visitor's IP address.
	 *
	 * @return string 64 hex characters.
	 */
	public static function ip_hash() {
		return hash_hmac( 'sha256', self::client_ip(), wp_salt( 'auth' ) );
	}

	/**
	 * Counts a hit and says whether the visitor is still under the limit.
	 *
	 * @param string $action Bucket name.
	 * @param int    $limit  Hits allowed per window.
	 * @param int    $window Window in seconds.
	 * @return bool True when allowed.
	 */
	public static function hit( $action, $limit, $window = 900 ) {
		$key  = 'bd_rl_' . md5( $action . '|' . self::ip_hash() );
		$data = get_transient( $key );
		$now  = time();
		if ( ! is_array( $data ) || $data['reset'] <= $now ) {
			$data = array(
				'count' => 0,
				'reset' => $now + $window,
			);
		}
		$data['count']++;
		set_transient( $key, $data, max( 1, $data['reset'] - $now ) );
		return $data['count'] <= $limit;
	}
}
