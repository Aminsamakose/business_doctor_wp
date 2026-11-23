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
		foreach ( self::owner_user_id( $email ) ? BD_Organisations::for_user( self::owner_user_id( $email ) ) : array() as $org ) {
			$items[] = array(
				'group_id'    => 'business-doctor-owner',
				'group_label' => __( 'Business Doctor organisation profiles', 'business-doctor' ),
				'item_id'     => 'bd-org-' . $org['id'],
				'data'        => array(
					array( 'name' => __( 'Platform', 'business-doctor' ), 'value' => BD_Frameworks::short_name( $org['platform'] ) ),
					array( 'name' => __( 'Organisation name', 'business-doctor' ), 'value' => $org['name'] ),
					array( 'name' => __( 'Sector', 'business-doctor' ), 'value' => (string) $org['sector'] ),
					array( 'name' => __( 'Region', 'business-doctor' ), 'value' => (string) $org['region'] ),
					array( 'name' => __( 'Phone', 'business-doctor' ), 'value' => (string) $org['phone'] ),
					array( 'name' => __( 'Website', 'business-doctor' ), 'value' => (string) $org['website'] ),
				),
			);
		}
		foreach ( self::owner_user_id( $email ) ? BD_Diagnostics::for_user( self::owner_user_id( $email ) ) : array() as $d ) {
			$items[] = array(
				'group_id'    => 'business-doctor-owner',
				'group_label' => __( 'Business Doctor organisation profiles', 'business-doctor' ),
				'item_id'     => 'bd-diagnostic-' . $d['id'],
				'data'        => array(
					array( 'name' => __( 'Platform', 'business-doctor' ), 'value' => BD_Frameworks::short_name( $d['platform'] ) ),
					array( 'name' => __( 'Status', 'business-doctor' ), 'value' => ucfirst( $d['status'] ) ),
					array( 'name' => __( 'Overall score', 'business-doctor' ), 'value' => null === $d['overall_score'] ? '' : $d['overall_score'] . ' (' . $d['maturity'] . ')' ),
					array( 'name' => __( 'Started', 'business-doctor' ), 'value' => (string) $d['started_at'] ),
					array( 'name' => __( 'Submitted', 'business-doctor' ), 'value' => (string) $d['submitted_at'] ),
					array( 'name' => __( 'Your individual answers and any evidence notes or files, used to calculate these scores', 'business-doctor' ), 'value' => __( 'Available on request from the site administrator, or erase this record to remove them.', 'business-doctor' ) ),
				),
			);
		}
		foreach ( self::owner_user_id( $email ) ? self::payments_for_user( self::owner_user_id( $email ) ) : array() as $p ) {
			$items[] = array(
				'group_id'    => 'business-doctor-owner',
				'group_label' => __( 'Business Doctor organisation profiles', 'business-doctor' ),
				'item_id'     => 'bd-payment-' . $p['id'],
				'data'        => array(
					array( 'name' => __( 'Platform', 'business-doctor' ), 'value' => BD_Frameworks::short_name( $p['platform'] ) ),
					array( 'name' => __( 'Amount', 'business-doctor' ), 'value' => 'GHS ' . $p['amount_ghs'] ),
					array( 'name' => __( 'Status', 'business-doctor' ), 'value' => ucfirst( $p['status'] ) ),
					array( 'name' => __( 'Invoice number', 'business-doctor' ), 'value' => (string) $p['invoice_number'] ),
					array( 'name' => __( 'Date', 'business-doctor' ), 'value' => (string) $p['created_at'] ),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * A user's payment rows.
	 *
	 * @param int $user_id User id.
	 * @return array[]
	 */
	private static function payments_for_user( $user_id ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['payments']} WHERE user_id = %d ORDER BY id DESC", (int) $user_id ) );
		return array_map( 'get_object_vars', $rows ?: array() );
	}

	/**
	 * The business-owner WordPress user id for an email address, if one exists.
	 *
	 * @param string $email Email.
	 * @return int|null
	 */
	private static function owner_user_id( $email ) {
		$user = get_user_by( 'email', $email );
		return ( $user && BD_Accounts::is_owner( $user->ID ) ) ? $user->ID : null;
	}

	/**
	 * Erases a person's results, organisation profiles, full assessments and any evidence files
	 * attached to their answers. Does not delete the WordPress user account itself, the same way
	 * erasure never deletes the account that requested it; only the Business Doctor data it holds.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public static function erase( $email ) {
		$ids     = wp_list_pluck( self::rows_for( $email ), 'id' );
		$removed = BD_Results::delete( $ids );

		$user_id = self::owner_user_id( $email );
		if ( $user_id ) {
			global $wpdb;
			$t = BD_Install::tables();
			foreach ( BD_Diagnostics::for_user( $user_id ) as $d ) {
				foreach ( BD_Diagnostics::answers( $d['id'] ) as $a ) {
					if ( ! empty( $a['attachmentId'] ) ) {
						wp_delete_attachment( (int) $a['attachmentId'], true );
					}
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->delete( $t['diagnostic_answers'], array( 'diagnostic_id' => $d['id'] ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->delete( $t['diagnostics'], array( 'id' => $d['id'] ) );
				$removed++;
			}
			foreach ( BD_Organisations::for_user( $user_id ) as $org ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->delete( $t['organisations'], array( 'id' => $org['id'] ) );
				$removed++;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $t['entitlements'], array( 'user_id' => $user_id ) );
		}

		$retained = false;
		$messages = array();
		if ( $user_id && self::payments_for_user( $user_id ) ) {
			// Payment and invoice records are kept for accounting and tax purposes, the same way a
			// shop keeps its sales ledger after a customer asks to be forgotten. They already hold no
			// evidence of the assessment itself, only that a purchase of a given amount took place.
			$retained   = true;
			$messages[] = __( 'Payment and invoice records are kept for accounting purposes.', 'business-doctor' );
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => $retained,
			'messages'       => $messages,
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
		$text = '<p>' . __( 'When you take a free Business Health Assessment we store your name, email address, the assessment you chose, your scores and the time you finished. Your individual answers are used to calculate your scores and are not stored. We keep a salted hash of your IP address for abuse prevention, not the address itself. Acknowledging this notice is not consent to marketing.', 'business-doctor' ) . '</p>'
			. '<p>' . __( 'If you register for the full Business Health Assessment, we additionally store your account details, your organisation profile, your individual answers, any evidence notes or supporting documents you attach, and your scores. You may request a copy or deletion of this data at any time.', 'business-doctor' ) . '</p>'
			. '<p>' . __( 'If you pay for the full assessment online, payment is processed by Paystack; we store the amount, status and an invoice number, but never your card or mobile money details. Payment and invoice records are kept after a deletion request for accounting purposes, as is normal for a sales record.', 'business-doctor' ) . '</p>';
		wp_add_privacy_policy_content( 'Business Doctor', wp_kses_post( $text ) );
	}
}
