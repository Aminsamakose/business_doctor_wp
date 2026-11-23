<?php
/**
 * The full (paid-equivalent) evidence-weighted assessment a business owner takes: a diagnostic is
 * one attempt at one platform's published full framework, built up answer by answer and then
 * submitted for scoring. Unlike the free assessment, answers are kept (not discarded) so an owner
 * can resume a draft and staff can review the evidence behind a score.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Diagnostics {

	const EVIDENCE_CLASSES = array( 'Verified', 'Document-supported', 'Self-reported', 'Unverified', 'Missing' );
	const MIN_COMPLETION   = 0.9;

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
		$d                    = (array) $row;
		$d['id']              = (int) $d['id'];
		$d['org_id']          = (int) $d['org_id'];
		$d['user_id']         = (int) $d['user_id'];
		$d['framework_id']    = (int) $d['framework_id'];
		$d['framework_version'] = (int) $d['framework_version'];
		$d['overall_score']   = null === $d['overall_score'] ? null : (float) $d['overall_score'];
		$d['completion']      = null === $d['completion'] ? null : (float) $d['completion'];
		$d['dimension_scores'] = json_decode( (string) $d['dimension_scores'], true ) ?: array();
		$d['evidence_share']  = json_decode( (string) $d['evidence_share'], true ) ?: array();
		return $d;
	}

	/**
	 * One diagnostic by id.
	 *
	 * @param int $id Diagnostic id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['diagnostics']} WHERE id = %d", (int) $id ) ) );
	}

	/**
	 * The current draft (or most recent) diagnostic for a user and platform.
	 *
	 * @param int    $user_id  User id.
	 * @param string $platform Platform code.
	 * @return array|null
	 */
	public static function current_for( $user_id, $platform ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['diagnostics']} WHERE user_id = %d AND platform = %s ORDER BY id DESC LIMIT 1", (int) $user_id, $platform ) );
		return self::hydrate( $row );
	}

	/**
	 * Every diagnostic a user has started, newest first.
	 *
	 * @param int $user_id User id.
	 * @return array[]
	 */
	public static function for_user( $user_id ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['diagnostics']} WHERE user_id = %d ORDER BY id DESC", (int) $user_id ) );
		return array_map( array( __CLASS__, 'hydrate' ), $rows ?: array() );
	}

	/**
	 * Starts a new diagnostic, or returns the existing draft so there is never more than one open
	 * attempt per organisation.
	 *
	 * @param array $org      Organisation (see BD_Organisations).
	 * @param array $fw       Published full framework (see BD_Frameworks::published($platform, 'full')).
	 * @return array
	 */
	public static function start_or_resume( array $org, array $fw ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$draft = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['diagnostics']} WHERE org_id = %d AND status = 'draft'", $org['id'] ) );
		if ( $draft ) {
			return self::hydrate( $draft );
		}
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$t['diagnostics'],
			array(
				'org_id'            => $org['id'],
				'user_id'           => $org['user_id'],
				'platform'          => $org['platform'],
				'framework_id'      => $fw['id'],
				'framework_version' => $fw['version'],
				'status'            => 'draft',
				'started_at'        => $now,
				'updated_at'        => $now,
			)
		);
		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Every saved answer for a diagnostic, keyed by question code.
	 *
	 * @param int $diagnostic_id Diagnostic id.
	 * @return array<string, array>
	 */
	public static function answers( $diagnostic_id ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['diagnostic_answers']} WHERE diagnostic_id = %d", (int) $diagnostic_id ), ARRAY_A );
		$out  = array();
		foreach ( $rows ?: array() as $r ) {
			$out[ $r['question_code'] ] = array(
				'questionCode'  => $r['question_code'],
				'value'         => null === $r['value'] ? null : (int) $r['value'],
				'notApplicable' => (bool) $r['not_applicable'],
				'evidenceClass' => $r['evidence_class'],
				'evidenceNote'  => $r['evidence_note'],
				'attachmentId'  => $r['attachment_id'] ? (int) $r['attachment_id'] : null,
			);
		}
		return $out;
	}

	/**
	 * Saves (creates or updates) one answer on a draft diagnostic.
	 *
	 * @param array  $diagnostic Diagnostic, must be a draft.
	 * @param array  $question   The question being answered, from the published framework.
	 * @param array  $answer     Keys: value (0-4 or null), notApplicable, evidenceClass, evidenceNote, attachmentId.
	 * @return true|WP_Error
	 */
	public static function save_answer( array $diagnostic, array $question, array $answer ) {
		global $wpdb;
		if ( 'draft' !== $diagnostic['status'] ) {
			return new WP_Error( 'bd_submitted', __( 'This assessment has already been submitted and can no longer be changed.', 'business-doctor' ), array( 'status' => 409 ) );
		}
		$not_applicable = ! empty( $answer['notApplicable'] );
		if ( $not_applicable && ! BD_Diagnostic_Scoring::is_conditional( $question ) ) {
			return new WP_Error( 'bd_invalid', __( 'Only conditional questions may be marked not applicable.', 'business-doctor' ), array( 'status' => 422 ) );
		}
		$value = null;
		if ( ! $not_applicable ) {
			$value = isset( $answer['value'] ) ? (int) $answer['value'] : null;
			if ( null === $value || $value < 0 || $value > 4 ) {
				return new WP_Error( 'bd_invalid', __( 'Choose one of the five rating levels for this question.', 'business-doctor' ), array( 'status' => 422 ) );
			}
		}
		$evidence_class = (string) ( $answer['evidenceClass'] ?? 'Self-reported' );
		if ( ! in_array( $evidence_class, self::EVIDENCE_CLASSES, true ) ) {
			$evidence_class = 'Self-reported';
		}
		$t    = BD_Install::tables();
		$data = array(
			'value'          => $value,
			'not_applicable' => $not_applicable ? 1 : 0,
			'evidence_class' => $evidence_class,
			'evidence_note'  => sanitize_textarea_field( (string) ( $answer['evidenceNote'] ?? '' ) ),
			'attachment_id'  => ! empty( $answer['attachmentId'] ) ? (int) $answer['attachmentId'] : null,
			'updated_at'     => current_time( 'mysql', true ),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['diagnostic_answers']} WHERE diagnostic_id = %d AND question_code = %s", $diagnostic['id'], $question['code'] ) );
		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $t['diagnostic_answers'], $data, array( 'id' => (int) $existing ) );
		} else {
			$data['diagnostic_id'] = $diagnostic['id'];
			$data['question_code'] = $question['code'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $t['diagnostic_answers'], $data );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $t['diagnostics'], array( 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $diagnostic['id'] ) );
		return true;
	}

	/**
	 * Scores and submits a draft diagnostic, provided the completion gate is met. Mirrors
	 * validateSubmission()/scoreDiagnostic() in the original: a submission below the completion
	 * threshold is rejected rather than scored on partial evidence.
	 *
	 * @param array $diagnostic Diagnostic, must be a draft.
	 * @param array $fw         The framework it was started under.
	 * @return array|WP_Error The submitted diagnostic.
	 */
	public static function submit( array $diagnostic, array $fw ) {
		if ( 'draft' !== $diagnostic['status'] ) {
			return new WP_Error( 'bd_submitted', __( 'This assessment has already been submitted.', 'business-doctor' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$answers  = self::answers( $diagnostic['id'] );
		$response_list = array_values( $answers );
		$completion = BD_Diagnostic_Scoring::completion( $response_list, $fw['questions'], self::MIN_COMPLETION );
		if ( ! $completion['ok'] ) {
			/* translators: %d: percentage of the assessment completed so far. */
			return new WP_Error( 'bd_incomplete', sprintf( __( 'Please answer at least 90%% of the assessment before submitting. You have completed %d%% so far.', 'business-doctor' ), round( $completion['completion'] * 100 ) ), array( 'status' => 422 ) );
		}
		$scored = BD_Diagnostic_Scoring::score( $response_list, $fw['questions'] );
		$confidence = BD_Diagnostic_Scoring::confidence_class( $scored['evidenceShare'], true, true );

		$t    = BD_Install::tables();
		$now  = current_time( 'mysql', true );
		$data = array(
			'status'           => 'submitted',
			'overall_score'    => $scored['overall'],
			'maturity'         => $scored['maturity'],
			'confidence'       => $confidence,
			'completion'       => $completion['completion'],
			'dimension_scores' => wp_json_encode( $scored['dimensions'] ),
			'evidence_share'   => wp_json_encode( $scored['evidenceShare'] ),
			'submitted_at'     => $now,
			'scored_at'        => $now,
			'updated_at'       => $now,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $t['diagnostics'], $data, array( 'id' => $diagnostic['id'] ) );
		$result = self::get( $diagnostic['id'] );
		do_action( 'bd_diagnostic_submitted', $result );
		return $result;
	}

	/**
	 * Lists diagnostics for the staff admin screen, newest first, joined to the organisation name.
	 *
	 * @param array $filters  Keys: platform, status.
	 * @param int   $per_page Page size, 0 for all.
	 * @param int   $page     Page number from 1.
	 * @return array{items: array[], total: int}
	 */
	public static function query( array $filters = array(), $per_page = 20, $page = 1 ) {
		global $wpdb;
		$t     = BD_Install::tables();
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['platform'] ) ) {
			$where[] = 'd.platform = %s';
			$args[]  = $filters['platform'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'd.status = %s';
			$args[]  = $filters['status'];
		}
		$sql_where = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$t['diagnostics']} d WHERE $sql_where";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );

		$sql = "SELECT d.*, o.name AS org_name FROM {$t['diagnostics']} d LEFT JOIN {$t['organisations']} o ON o.id = d.org_id WHERE $sql_where ORDER BY d.id DESC";
		if ( $per_page > 0 ) {
			$sql   .= ' LIMIT %d OFFSET %d';
			$args[] = (int) $per_page;
			$args[] = (int) max( 0, ( $page - 1 ) * $per_page );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );
		$items = array();
		foreach ( $rows ?: array() as $row ) {
			$org_name = $row->org_name;
			$d        = self::hydrate( $row );
			$d['org_name'] = $org_name;
			$items[]  = $d;
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * The fields an owner may see about their own diagnostic.
	 *
	 * @param array $d Diagnostic.
	 * @return array
	 */
	public static function to_public( array $d ) {
		return array(
			'id'              => $d['id'],
			'platform'        => $d['platform'],
			'platformName'    => BD_Frameworks::short_name( $d['platform'] ),
			'status'          => $d['status'],
			'overallScore'    => $d['overall_score'],
			'maturity'        => $d['maturity'],
			'confidence'      => $d['confidence'],
			'completion'      => $d['completion'],
			'dimensionScores' => $d['dimension_scores'],
			'evidenceShare'   => $d['evidence_share'],
			'startedAt'       => $d['started_at'],
			'submittedAt'     => $d['submitted_at'],
		);
	}
}
