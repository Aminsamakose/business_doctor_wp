<?php
/**
 * Connects results to WordPress's own personal-data tools (Tools > Export / Erase Personal Data)
 * and suggests privacy-policy text.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Privacy {

	/**
	 * Registers exporter, eraser and policy text.
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
	}

	/**
	 * Adds the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['business-doctor'] = array(
			'exporter_friendly_name' => __( 'Business Doctor assessments', 'business-doctor' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['business-doctor'] = array(
			'eraser_friendly_name' => __( 'Business Doctor assessments', 'business-doctor' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Results for an email address.
	 *
	 * @param string $email Email.
	 * @return array[]
	 */
	private static function rows_for( $email ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['results']} WHERE email_norm = %s", substr( BD_Results::normalise_email( $email ), 0, 150 ) ) );
		return array_map( array( 'BD_Results', 'hydrate' ), $rows ?: array() );
	}

	/**
	 * Exports a person's results.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public static function export( $email ) {
		$items = array();
		foreach ( self::rows_for( $email ) as $r ) {
			$dims = array();
			foreach ( $r['dimension_scores'] as $d ) {
				$dims[] = $d['dimension'] . ': ' . $d['score'];
			}
			$items[] = array(
				'group_id'    => 'business-doctor',
				'group_label' => __( 'Business Doctor assessments', 'business-doctor' ),
				'item_id'     => 'bd-result-' . $r['id'],
				'data'        => array(
					array( 'name' => __( 'Platform', 'business-doctor' ), 'value' => BD_Frameworks::short_name( $r['platform'] ) ),
					array( 'name' => __( 'Name', 'business-doctor' ), 'value' => $r['full_name'] ),
					array( 'name' => __( 'Email', 'business-doctor' ), 'value' => $r['email'] ),
					array( 'name' => __( 'Overall score', 'business-doctor' ), 'value' => $r['overall_score'] . ' (' . $r['classification'] . ')' ),
					array( 'name' => __( 'Dimension scores', 'business-doctor' ), 'value' => implode( '; ', $dims ) ),
					array( 'name' => __( 'Completed', 'business-doctor' ), 'value' => $r['completed_at'] . ' UTC' ),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Erases a person's results.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public static function erase( $email ) {
		$ids     = wp_list_pluck( self::rows_for( $email ), 'id' );
		$removed = BD_Results::delete( $ids );
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Suggested text for the site's privacy policy.
	 */
	public static function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . __( 'When you take a free Business Health Assessment we store your name, email address, the assessment you chose, your scores and the time you finished. Your individual answers are used to calculate your scores and are not stored. We keep a salted hash of your IP address for abuse prevention, not the address itself. Acknowledging this notice is not consent to marketing.', 'business-doctor' ) . '</p>';
		wp_add_privacy_policy_content( 'Business Doctor', wp_kses_post( $text ) );
	}
}
