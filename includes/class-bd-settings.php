<?php
/**
 * Plugin settings, stored as one option array.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Settings {

	const OPTION = 'bd_settings';

	/**
	 * Default values for every setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'critical_below'     => 40,
			'needs_below'        => 60,
			'developing_below'   => 80,
			'enabled_platforms'  => array( 'SME360', 'AGRIFOOD360', 'ESO360' ),
			'notify_email'       => get_option( 'admin_email' ),
			'notify_on_complete' => 1,
			'full_assessment_url' => '',
			'privacy_url'        => '',
			'brand_name'         => 'Business Doctor',
			'brand_byline'       => 'by Samakose',
			'site_label'         => 'www.samakose.com',
			'trust_proxy'        => 0,
			'rate_limit'         => 10,
			'delete_on_uninstall' => 0,
		);
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Band thresholds in the shape BD_Scoring expects.
	 *
	 * @return array
	 */
	public static function thresholds() {
		$s = self::all();
		return array(
			'critical_below'   => (float) $s['critical_below'],
			'needs_below'      => (float) $s['needs_below'],
			'developing_below' => (float) $s['developing_below'],
		);
	}

	/**
	 * Cleans submitted settings. Thresholds must rise strictly from 1 to 100.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$d   = self::defaults();
		$old = self::all();
		$in  = is_array( $input ) ? $input : array();
		$out = array();

		$c  = isset( $in['critical_below'] ) ? (float) $in['critical_below'] : $d['critical_below'];
		$n  = isset( $in['needs_below'] ) ? (float) $in['needs_below'] : $d['needs_below'];
		$dv = isset( $in['developing_below'] ) ? (float) $in['developing_below'] : $d['developing_below'];
		if ( $c > 0 && $c < $n && $n < $dv && $dv <= 100 ) {
			$out['critical_below']   = $c;
			$out['needs_below']      = $n;
			$out['developing_below'] = $dv;
		} else {
			add_settings_error( self::OPTION, 'bd_thresholds', __( 'Band thresholds must rise from low to high and stay between 1 and 100. The previous thresholds were kept.', 'business-doctor' ) );
			$out['critical_below']   = $old['critical_below'];
			$out['needs_below']      = $old['needs_below'];
			$out['developing_below'] = $old['developing_below'];
		}

		$platforms                = isset( $in['enabled_platforms'] ) && is_array( $in['enabled_platforms'] ) ? $in['enabled_platforms'] : array();
		$out['enabled_platforms'] = array_values( array_intersect( array_keys( BD_Frameworks::platforms() ), array_map( 'bd_sanitize_code', $platforms ) ) );

		$email               = isset( $in['notify_email'] ) ? sanitize_email( $in['notify_email'] ) : '';
		$out['notify_email'] = is_email( $email ) ? $email : '';
		$out['notify_on_complete'] = empty( $in['notify_on_complete'] ) ? 0 : 1;

		$out['full_assessment_url'] = isset( $in['full_assessment_url'] ) ? esc_url_raw( $in['full_assessment_url'] ) : '';
		$out['privacy_url']         = isset( $in['privacy_url'] ) ? esc_url_raw( $in['privacy_url'] ) : '';
		foreach ( array( 'brand_name', 'brand_byline', 'site_label' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) ? sanitize_text_field( $in[ $k ] ) : $d[ $k ];
		}
		$out['trust_proxy']         = empty( $in['trust_proxy'] ) ? 0 : 1;
		$out['rate_limit']          = isset( $in['rate_limit'] ) ? max( 1, min( 100, (int) $in['rate_limit'] ) ) : $d['rate_limit'];
		$out['delete_on_uninstall'] = empty( $in['delete_on_uninstall'] ) ? 0 : 1;
		return $out;
	}
}

if ( ! function_exists( 'bd_sanitize_code' ) ) {
	/**
	 * Keeps only A-Z, 0-9 and underscore, upper case. Used for platform codes.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	function bd_sanitize_code( $value ) {
		return strtoupper( preg_replace( '/[^A-Za-z0-9_]/', '', (string) $value ) );
	}
}
