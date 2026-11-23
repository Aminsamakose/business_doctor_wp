<?php
/**
 * A diagnosis explaining the weakest areas of one submitted full assessment and why, drafted either
 * by Claude (grounded only in that assessment's own answered questions) or typed directly by staff,
 * and always reviewed by a second staff member before an owner ever sees it. Port of
 * requestDiagnosis/createDiagnosis/reviseDiagnosis/reviewDiagnosis in Samakose AI's
 * src/services/clinical.ts, simplified for this plugin's flat role model: the four-eyes rule here is
 * just "the reviewer is not the drafter", since there is no separate case/consultant hierarchy.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Case_Diagnoses {

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
		$d                     = (array) $row;
		$d['id']               = (int) $d['id'];
		$d['diagnostic_id']    = (int) $d['diagnostic_id'];
		$d['org_id']           = (int) $d['org_id'];
		$d['ai_drafted']       = (bool) $d['ai_drafted'];
		$d['version']          = (int) $d['version'];
		$d['supersedes_id']    = $d['supersedes_id'] ? (int) $d['supersedes_id'] : null;
		$d['created_by']       = (int) $d['created_by'];
		$d['reviewed_by']      = $d['reviewed_by'] ? (int) $d['reviewed_by'] : null;
		$d['model_confidence'] = null === $d['model_confidence'] ? null : (float) $d['model_confidence'];
		$d['root_causes']      = json_decode( (string) $d['root_causes'], true ) ?: array();
		$d['risks']            = json_decode( (string) $d['risks'], true ) ?: array();
		return $d;
	}

	/**
	 * One diagnosis by id.
	 *
	 * @param int $id Diagnosis id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['case_diagnoses']} WHERE id = %d", (int) $id ) ) );
	}

	/**
	 * Every diagnosis drafted for a diagnostic, newest first.
	 *
	 * @param int $diagnostic_id Diagnostic id.
	 * @return array[]
	 */
	public static function list_for_diagnostic( $diagnostic_id ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['case_diagnoses']} WHERE diagnostic_id = %d ORDER BY id DESC", (int) $diagnostic_id ) );
		return array_map( array( __CLASS__, 'hydrate' ), $rows ?: array() );
	}

	/**
	 * The current (latest, not superseded) diagnosis for a diagnostic, if any.
	 *
	 * @param int $diagnostic_id Diagnostic id.
	 * @return array|null
	 */
	public static function latest_for_diagnostic( $diagnostic_id ) {
		$all = self::list_for_diagnostic( $diagnostic_id );
		return $all ? $all[0] : null;
	}

	/**
	 * The latest Reviewed diagnosis for a diagnostic, the one an owner may be shown.
	 *
	 * @param int $diagnostic_id Diagnostic id.
	 * @return array|null
	 */
	public static function latest_reviewed( $diagnostic_id ) {
		foreach ( self::list_for_diagnostic( $diagnostic_id ) as $d ) {
			if ( 'reviewed' === $d['status'] ) {
				return $d;
			}
		}
		return null;
	}

	/**
	 * The question codes actually answered on a diagnostic: the only valid evidence codes a diagnosis
	 * may cite. Mirrors the original's rule that an AI (or human) draft may never invent evidence.
	 *
	 * @param int $diagnostic_id Diagnostic id.
	 * @return string[]
	 */
	public static function evidence_codes( $diagnostic_id ) {
		$answers = BD_Diagnostics::answers( $diagnostic_id );
		return array_keys( $answers );
	}

	/**
	 * Builds the grounded data Claude (or a staff member working manually) analyses: the diagnostic's
	 * scores and every answered question with its rating, evidence class and note. No figure that is
	 * not in this payload may be asserted by a diagnosis.
	 *
	 * @param array $diagnostic Diagnostic (see BD_Diagnostics::get()).
	 * @param array $fw         The framework it was scored under.
	 * @return array
	 */
	public static function build_evidence_payload( array $diagnostic, array $fw ) {
		$answers   = BD_Diagnostics::answers( $diagnostic['id'] );
		$by_code   = array();
		foreach ( $fw['questions'] as $q ) {
			if ( isset( $answers[ $q['code'] ] ) ) {
				$by_code[ $q['code'] ] = $q;
			}
		}
		$questions = array();
		foreach ( $answers as $code => $a ) {
			$q           = $by_code[ $code ] ?? array();
			$questions[] = array(
				'code'          => $code,
				'text'          => $q['text'] ?? '',
				'dimension'     => $q['dimension'] ?? '',
				'value'         => $a['value'],
				'notApplicable' => $a['notApplicable'],
				'evidenceClass' => $a['evidenceClass'],
				'evidenceNote'  => $a['evidenceNote'],
			);
		}
		return array(
			'platform'        => $diagnostic['platform'],
			'overallScore'    => $diagnostic['overall_score'],
			'maturity'        => $diagnostic['maturity'],
			'dimensionScores' => $diagnostic['dimension_scores'],
			'questions'       => $questions,
		);
	}

	/**
	 * Asks Claude to draft a diagnosis, validates its reply, and stores it as a new draft version.
	 * Validation failure is reported rather than silently retried, so staff can see exactly what the
	 * model got wrong and write the diagnosis manually instead if needed.
	 *
	 * @param array $diagnostic Diagnostic, must be submitted and scored.
	 * @param array $fw         The framework it was scored under.
	 * @param int   $user_id    The staff member requesting the draft (recorded as created_by).
	 * @return array|WP_Error
	 */
	public static function request_ai_draft( array $diagnostic, array $fw, $user_id ) {
		if ( 'submitted' !== $diagnostic['status'] ) {
			return new WP_Error( 'bd_not_submitted', __( 'Only a submitted, scored assessment can be diagnosed.', 'business-doctor' ), array( 'status' => 409 ) );
		}
		$payload = self::build_evidence_payload( $diagnostic, $fw );
		$system  = BD_AI_Domain::diagnosis_system_prompt( $fw );
		$reply   = BD_Claude::complete( $system, wp_json_encode( $payload ), 2000 );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$json = BD_AI_Domain::extract_json( $reply );
		if ( null === $json ) {
			return new WP_Error( 'bd_ai_bad_json', __( 'The AI reply could not be read as a diagnosis. Please try again or write the diagnosis manually.', 'business-doctor' ), array( 'status' => 502 ) );
		}
		return self::create( $diagnostic, $json, $user_id, true );
	}

	/**
	 * Creates a new draft diagnosis (AI-drafted or typed by staff), validating it first. If an earlier
	 * diagnosis exists for this diagnostic it is superseded.
	 *
	 * @param array $diagnostic Diagnostic.
	 * @param array $data       Candidate diagnosis fields: summary, root_causes, priority, risks,
	 *                          model_confidence.
	 * @param int   $user_id    The drafter.
	 * @param bool  $ai_drafted Whether this draft came from Claude.
	 * @return array|WP_Error
	 */
	public static function create( array $diagnostic, array $data, $user_id, $ai_drafted = false ) {
		$evidence_codes = self::evidence_codes( $diagnostic['id'] );
		$problems       = BD_AI_Domain::validate_diagnosis( $data, $evidence_codes );
		if ( $problems ) {
			return new WP_Error( 'bd_invalid_diagnosis', implode( ' ', $problems ), array( 'status' => 422 ) );
		}
		global $wpdb;
		$t        = BD_Install::tables();
		$previous = self::latest_for_diagnostic( $diagnostic['id'] );
		$now      = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$t['case_diagnoses'],
			array(
				'diagnostic_id'    => $diagnostic['id'],
				'org_id'           => $diagnostic['org_id'],
				'platform'         => $diagnostic['platform'],
				'status'           => 'draft',
				'summary'          => sanitize_textarea_field( (string) $data['summary'] ),
				'root_causes'      => wp_json_encode( $data['root_causes'] ),
				'priority'         => $data['priority'],
				'risks'            => wp_json_encode( $data['risks'] ),
				'model_confidence' => isset( $data['model_confidence'] ) ? (float) $data['model_confidence'] : null,
				'ai_drafted'       => $ai_drafted ? 1 : 0,
				'version'          => $previous ? $previous['version'] + 1 : 1,
				'supersedes_id'    => $previous ? $previous['id'] : null,
				'created_by'       => (int) $user_id,
				'created_at'       => $now,
			)
		);
		if ( $previous && 'draft' === $previous['status'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $t['case_diagnoses'], array( 'status' => 'superseded' ), array( 'id' => $previous['id'] ) );
		}
		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Approves or rejects a draft diagnosis. Four-eyes: the reviewer must not be the drafter.
	 *
	 * @param array  $diagnosis Diagnosis, must be a draft.
	 * @param int    $user_id   The reviewer.
	 * @param bool   $approve   True to mark Reviewed, false to Reject.
	 * @param string $note      Optional reviewer note (required on rejection).
	 * @return array|WP_Error
	 */
	public static function review( array $diagnosis, $user_id, $approve, $note = '' ) {
		if ( 'draft' !== $diagnosis['status'] ) {
			return new WP_Error( 'bd_not_draft', __( 'Only a draft diagnosis can be reviewed.', 'business-doctor' ), array( 'status' => 409 ) );
		}
		if ( (int) $user_id === (int) $diagnosis['created_by'] ) {
			return new WP_Error( 'bd_four_eyes', __( 'A diagnosis cannot be reviewed by the same person who drafted it. Ask another member of staff to review it.', 'business-doctor' ), array( 'status' => 403 ) );
		}
		if ( ! $approve && '' === trim( (string) $note ) ) {
			return new WP_Error( 'bd_invalid', __( 'Please explain why this diagnosis is being rejected.', 'business-doctor' ), array( 'status' => 422 ) );
		}
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$t['case_diagnoses'],
			array(
				'status'      => $approve ? 'reviewed' : 'rejected',
				'reviewed_by' => (int) $user_id,
				'reviewed_at' => current_time( 'mysql', true ),
				'review_note' => sanitize_textarea_field( (string) $note ),
			),
			array( 'id' => $diagnosis['id'] )
		);
		return self::get( $diagnosis['id'] );
	}

	/**
	 * The fields an owner may see once a diagnosis has been reviewed.
	 *
	 * @param array $d Diagnosis.
	 * @return array
	 */
	public static function to_public( array $d ) {
		return array(
			'summary'    => $d['summary'],
			'rootCauses' => $d['root_causes'],
			'priority'   => $d['priority'],
			'risks'      => $d['risks'],
			'reviewedAt' => $d['reviewed_at'],
		);
	}
}
