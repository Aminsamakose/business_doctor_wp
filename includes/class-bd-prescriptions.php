<?php
/**
 * A prescription turns a reviewed diagnosis into a short list of interventions with concrete, owned,
 * dated actions. Like a diagnosis, it can be drafted by Claude or typed by staff, must pass review by
 * a second staff member (four-eyes), and only once approved does it materialise into real action-plan
 * rows and KPIs an owner can act on and check in against. Port of requestPrescription/
 * createPrescription/submitPrescription/reviewPrescription/materialise in Samakose AI's
 * src/services/clinical.ts, adapted to freeform items grounded to the platform's own dimensions
 * instead of a fixed intervention library, which this plugin does not have.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Prescriptions {

	/**
	 * Decodes a row.
	 *
	 * @param object|null $row Row.
	 * @return array|null
	 */
	private static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		$p                  = (array) $row;
		$p['id']            = (int) $p['id'];
		$p['diagnosis_id']  = (int) $p['diagnosis_id'];
		$p['org_id']        = (int) $p['org_id'];
		$p['ai_drafted']    = (bool) $p['ai_drafted'];
		$p['version']       = (int) $p['version'];
		$p['supersedes_id'] = $p['supersedes_id'] ? (int) $p['supersedes_id'] : null;
		$p['created_by']    = (int) $p['created_by'];
		$p['reviewed_by']   = $p['reviewed_by'] ? (int) $p['reviewed_by'] : null;
		$p['items']         = json_decode( (string) $p['items'], true ) ?: array();
		return $p;
	}

	/**
	 * One prescription by id.
	 *
	 * @param int $id Prescription id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['prescriptions']} WHERE id = %d", (int) $id ) ) );
	}

	/**
	 * Every prescription drafted against a diagnosis, newest first.
	 *
	 * @param int $diagnosis_id Diagnosis id.
	 * @return array[]
	 */
	public static function list_for_diagnosis( $diagnosis_id ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['prescriptions']} WHERE diagnosis_id = %d ORDER BY id DESC", (int) $diagnosis_id ) );
		return array_map( array( __CLASS__, 'hydrate' ), $rows ?: array() );
	}

	/**
	 * The current (latest, not superseded) prescription for a diagnosis, if any.
	 *
	 * @param int $diagnosis_id Diagnosis id.
	 * @return array|null
	 */
	public static function latest_for_diagnosis( $diagnosis_id ) {
		$all = self::list_for_diagnosis( $diagnosis_id );
		return $all ? $all[0] : null;
	}

	/**
	 * The approved prescription for a diagnosis, if any.
	 *
	 * @param int $diagnosis_id Diagnosis id.
	 * @return array|null
	 */
	public static function latest_approved( $diagnosis_id ) {
		foreach ( self::list_for_diagnosis( $diagnosis_id ) as $p ) {
			if ( 'approved' === $p['status'] ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Staff-configured deadline bounds, in days.
	 *
	 * @return array{min:int, max:int}
	 */
	public static function deadline_bounds() {
		return array(
			'min' => (int) BD_Settings::get( 'prescription_min_days' ),
			'max' => (int) BD_Settings::get( 'prescription_max_days' ),
		);
	}

	/**
	 * Asks Claude to draft a prescription from an approved diagnosis, validates it, and stores it.
	 *
	 * @param array $diagnosis Diagnosis, must be Reviewed.
	 * @param array $fw        The platform's published full framework (for its dimension list).
	 * @param int   $user_id   The staff member requesting the draft.
	 * @return array|WP_Error
	 */
	public static function request_ai_draft( array $diagnosis, array $fw, $user_id ) {
		if ( 'reviewed' !== $diagnosis['status'] ) {
			return new WP_Error( 'bd_not_reviewed', __( 'A prescription can only be drafted from a reviewed diagnosis.', 'business-doctor' ), array( 'status' => 409 ) );
		}
		$bounds  = self::deadline_bounds();
		$system  = BD_AI_Domain::prescription_system_prompt( $fw, $bounds['min'], $bounds['max'] );
		$payload = array(
			'summary'    => $diagnosis['summary'],
			'rootCauses' => $diagnosis['root_causes'],
			'priority'   => $diagnosis['priority'],
			'risks'      => $diagnosis['risks'],
		);
		$reply = BD_Claude::complete( $system, wp_json_encode( $payload ), 2500 );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$json = BD_AI_Domain::extract_json( $reply );
		if ( null === $json ) {
			return new WP_Error( 'bd_ai_bad_json', __( 'The AI reply could not be read as a prescription. Please try again or write it manually.', 'business-doctor' ), array( 'status' => 502 ) );
		}
		return self::create( $diagnosis, $fw, $json, $user_id, true );
	}

	/**
	 * Creates a new draft prescription (AI-drafted or typed by staff), validating it first. If an
	 * earlier prescription exists for this diagnosis it is superseded.
	 *
	 * @param array $diagnosis  Diagnosis this prescription is for.
	 * @param array $fw         Published full framework (for its dimension list).
	 * @param array $data       Candidate prescription: {items: [...]}.
	 * @param int   $user_id    The drafter.
	 * @param bool  $ai_drafted Whether this draft came from Claude.
	 * @return array|WP_Error
	 */
	public static function create( array $diagnosis, array $fw, array $data, $user_id, $ai_drafted = false ) {
		$bounds   = self::deadline_bounds();
		$problems = BD_AI_Domain::validate_prescription( $data, $fw['dimensions'], $bounds['min'], $bounds['max'] );
		if ( $problems ) {
			return new WP_Error( 'bd_invalid_prescription', implode( ' ', $problems ), array( 'status' => 422 ) );
		}
		global $wpdb;
		$t        = BD_Install::tables();
		$previous = self::latest_for_diagnosis( $diagnosis['id'] );
		$now      = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$t['prescriptions'],
			array(
				'diagnosis_id'  => $diagnosis['id'],
				'org_id'        => $diagnosis['org_id'],
				'platform'      => $diagnosis['platform'],
				'status'        => 'draft',
				'items'         => wp_json_encode( $data['items'] ),
				'ai_drafted'    => $ai_drafted ? 1 : 0,
				'version'       => $previous ? $previous['version'] + 1 : 1,
				'supersedes_id' => $previous ? $previous['id'] : null,
				'created_by'    => (int) $user_id,
				'created_at'    => $now,
			)
		);
		if ( $previous && in_array( $previous['status'], array( 'draft', 'in_review' ), true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $t['prescriptions'], array( 'status' => 'superseded' ), array( 'id' => $previous['id'] ) );
		}
		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Moves a draft prescription into review.
	 *
	 * @param array $prescription Prescription, must be a draft.
	 * @return array|WP_Error
	 */
	public static function submit_for_review( array $prescription ) {
		if ( 'draft' !== $prescription['status'] ) {
			return new WP_Error( 'bd_not_draft', __( 'Only a draft prescription can be submitted for review.', 'business-doctor' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$t['prescriptions'],
			array(
				'status'       => 'in_review',
				'submitted_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $prescription['id'] )
		);
		return self::get( $prescription['id'] );
	}

	/**
	 * Approves or returns a prescription in review. Four-eyes: the reviewer must not be the drafter.
	 * On approval, materialises the action plan and KPIs.
	 *
	 * @param array  $prescription Prescription, must be in_review.
	 * @param array  $org          The organisation it belongs to (for the owner's user id).
	 * @param int    $user_id      The reviewer.
	 * @param bool   $approve      True to approve, false to return for rework.
	 * @param string $note         Optional reviewer note (required when returning).
	 * @return array|WP_Error
	 */
	public static function review( array $prescription, array $org, $user_id, $approve, $note = '' ) {
		if ( 'in_review' !== $prescription['status'] ) {
			return new WP_Error( 'bd_not_in_review', __( 'Only a prescription submitted for review can be approved or returned.', 'business-doctor' ), array( 'status' => 409 ) );
		}
		if ( (int) $user_id === (int) $prescription['created_by'] ) {
			return new WP_Error( 'bd_four_eyes', __( 'A prescription cannot be reviewed by the same person who drafted it. Ask another member of staff to review it.', 'business-doctor' ), array( 'status' => 403 ) );
		}
		if ( ! $approve && '' === trim( (string) $note ) ) {
			return new WP_Error( 'bd_invalid', __( 'Please explain why this prescription is being returned.', 'business-doctor' ), array( 'status' => 422 ) );
		}
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$t['prescriptions'],
			array(
				'status'        => $approve ? 'approved' : 'returned',
				'reviewed_by'   => (int) $user_id,
				'reviewed_at'   => current_time( 'mysql', true ),
				'reviewer_note' => sanitize_textarea_field( (string) $note ),
			),
			array( 'id' => $prescription['id'] )
		);
		$updated = self::get( $prescription['id'] );
		if ( $approve ) {
			self::materialise( $updated, $org );
		}
		return $updated;
	}

	/**
	 * Turns an approved prescription's items into real action-plan rows and KPIs. Skips any action
	 * whose exact text is already open for this organisation, so re-materialising (should it ever be
	 * called twice) never duplicates work. Port of materialise() in src/services/clinical.ts.
	 *
	 * @param array $prescription Approved prescription.
	 * @param array $org          Organisation (for the owner's user id as assignee of owner-role actions).
	 * @return void
	 */
	private static function materialise( array $prescription, array $org ) {
		global $wpdb;
		$t   = BD_Install::tables();
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$open_texts = $wpdb->get_col( $wpdb->prepare( "SELECT text FROM {$t['actions']} WHERE org_id = %d AND status = 'open'", $prescription['org_id'] ) );

		foreach ( (array) $prescription['items'] as $i => $item ) {
			foreach ( (array) ( $item['actions'] ?? array() ) as $action ) {
				$text = (string) ( $action['text'] ?? '' );
				if ( '' === $text || in_array( $text, $open_texts, true ) ) {
					continue;
				}
				$owner_role  = 'owner' === ( $action['owner_role'] ?? '' ) ? 'owner' : 'staff';
				$assignee_id = 'owner' === $owner_role ? (int) $org['user_id'] : null;
				$due_days    = isset( $action['deadline_days'] ) ? (int) $action['deadline_days'] : 30;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert(
					$t['actions'],
					array(
						'org_id'          => $prescription['org_id'],
						'prescription_id' => $prescription['id'],
						'item_index'      => (int) $i,
						'item_title'      => mb_substr( (string) ( $item['title'] ?? '' ), 0, 191 ),
						'text'            => mb_substr( $text, 0, 255 ),
						'owner_role'      => $owner_role,
						'assignee_id'     => $assignee_id,
						'due_date'        => gmdate( 'Y-m-d', strtotime( $now . ' UTC' ) + $due_days * DAY_IN_SECONDS ),
						'status'          => 'open',
						'created_at'      => $now,
						'updated_at'      => $now,
					)
				);
			}
			if ( ! empty( $item['kpi_name'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$t['kpis']} WHERE org_id = %d AND name = %s",
						$prescription['org_id'],
						$item['kpi_name']
					)
				);
				if ( ! $exists ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->insert(
						$t['kpis'],
						array(
							'org_id'          => $prescription['org_id'],
							'prescription_id' => $prescription['id'],
							'name'            => mb_substr( (string) $item['kpi_name'], 0, 191 ),
							'created_at'      => $now,
						)
					);
				}
			}
		}
	}

	/**
	 * The fields an owner may see about an approved prescription.
	 *
	 * @param array $p Prescription.
	 * @return array
	 */
	public static function to_public( array $p ) {
		return array(
			'items'      => $p['items'],
			'approvedAt' => $p['reviewed_at'],
		);
	}
}
