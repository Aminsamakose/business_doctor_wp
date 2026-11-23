<?php
/**
 * Completed free assessments. Each result is also a lead that staff can triage.
 * Individual answers are scored and then discarded; only the scores are kept.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Results {

	const STATUSES = array( 'new', 'contacted', 'converted', 'closed', 'spam' );

	/**
	 * Labels for lead statuses.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels() {
		return array(
			'new'       => __( 'New', 'business-doctor' ),
			'contacted' => __( 'Contacted', 'business-doctor' ),
			'converted' => __( 'Converted', 'business-doctor' ),
			'closed'    => __( 'Closed', 'business-doctor' ),
			'spam'      => __( 'Spam', 'business-doctor' ),
		);
	}

	/**
	 * The message shown when an email has already used its free assessment for a platform.
	 *
	 * @return array
	 */
	public static function already_used() {
		return array(
			'eligible' => false,
			'title'    => __( 'Your Free Assessment Has Already Been Used', 'business-doctor' ),
			'body'     => __( 'You have already completed your free assessment for this platform using this email address. You can explore the full Business Health Assessment for a more comprehensive diagnosis and personalised improvement plan.', 'business-doctor' ),
		);
	}

	/**
	 * Lower-cased, trimmed email.
	 *
	 * @param string $email Raw email.
	 * @return string
	 */
	public static function normalise_email( $email ) {
		return strtolower( trim( (string) $email ) );
	}

	/**
	 * Whether an email may still take the free assessment for a platform.
	 *
	 * @param string $email    Email.
	 * @param string $platform Platform code.
	 * @return bool
	 */
	public static function is_eligible( $email, $platform ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['results']} WHERE email_norm = %s AND platform = %s LIMIT 1", substr( self::normalise_email( $email ), 0, 150 ), $platform ) );
		return ! $id;
	}

	/**
	 * Scores the answers and stores the result.
	 *
	 * @param string $platform  Platform code.
	 * @param string $full_name Name.
	 * @param string $email     Email.
	 * @param array  $answers   Map of question code => letter.
	 * @return array|WP_Error Public result, the already-used message, or an error.
	 */
	public static function complete( $platform, $full_name, $email, array $answers ) {
		global $wpdb;
		$t  = BD_Install::tables();
		$fw = BD_Frameworks::published( $platform );
		if ( ! $fw ) {
			return new WP_Error( 'bd_unavailable', __( 'This assessment is not available right now.', 'business-doctor' ), array( 'status' => 404 ) );
		}
		if ( ! self::is_eligible( $email, $platform ) ) {
			return self::already_used();
		}
		try {
			$scored = BD_Scoring::score( $answers, $fw['questions'], BD_Settings::thresholds() );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'bd_answers', __( 'Please answer every question before seeing your result.', 'business-doctor' ), array( 'status' => 422 ) );
		}

		$now   = current_time( 'mysql', true );
		$token = bin2hex( random_bytes( 16 ) );
		$row   = array(
			'token'             => $token,
			'platform'          => $platform,
			'framework_id'      => $fw['id'],
			'framework_version' => $fw['version'],
			'full_name'         => $full_name,
			'email'             => $email,
			'email_norm'        => substr( self::normalise_email( $email ), 0, 150 ),
			'overall_score'     => $scored['overall'],
			'classification'    => $scored['classification'],
			'dimension_scores'  => wp_json_encode( $scored['dimensions'] ),
			'status'            => 'new',
			'ip_hash'           => BD_Rate_Limit::ip_hash(),
			'completed_at'      => $now,
			'updated_at'        => $now,
		);
		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( $t['results'], $row );
		$wpdb->suppress_errors( false );
		if ( ! $ok ) {
			// The unique key on (email, platform) closes the race between two simultaneous submissions.
			if ( ! self::is_eligible( $email, $platform ) ) {
				return self::already_used();
			}
			return new WP_Error( 'bd_db', __( 'Your result could not be saved. Please try again.', 'business-doctor' ), array( 'status' => 500 ) );
		}
		$row['id'] = (int) $wpdb->insert_id;
		do_action( 'bd_result_completed', $row );
		return self::to_public( self::get_by_token( $token ) );
	}

	/**
	 * A result by its public token.
	 *
	 * @param string $token 32 hex characters.
	 * @return array|null
	 */
	public static function get_by_token( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $token ) ) {
			return null;
		}
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['results']} WHERE token = %s", $token ) ) );
	}

	/**
	 * A result by id.
	 *
	 * @param int $id Result id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['results']} WHERE id = %d", $id ) ) );
	}

	/**
	 * Decodes a result row.
	 *
	 * @param object|null $row Row.
	 * @return array|null
	 */
	public static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		$r                     = (array) $row;
		$r['id']               = (int) $r['id'];
		$r['overall_score']    = (float) $r['overall_score'];
		$r['dimension_scores'] = json_decode( $r['dimension_scores'], true ) ?: array();
		return $r;
	}

	/**
	 * The fields a visitor may see about their own result.
	 *
	 * @param array|null $r Result.
	 * @return array|null
	 */
	public static function to_public( $r ) {
		if ( ! $r ) {
			return null;
		}
		$findings = BD_Scoring::findings( $r['dimension_scores'] );
		return array(
			'eligible'        => true,
			'token'           => $r['token'],
			'reference'       => self::reference( $r ),
			'platform'        => $r['platform'],
			'platformName'    => BD_Frameworks::short_name( $r['platform'] ),
			'fullName'        => $r['full_name'],
			'overallScore'    => $r['overall_score'],
			'classification'  => $r['classification'],
			'dimensionScores' => $r['dimension_scores'],
			'strengths'       => $findings['strengths'],
			'priorities'      => $findings['priorities'],
			'completedAt'     => gmdate( 'c', (int) strtotime( $r['completed_at'] . ' UTC' ) ),
			'reportUrl'       => BD_Report::url( $r['token'] ),
		);
	}

	/**
	 * Short human reference, as in the original reports.
	 *
	 * @param array $r Result.
	 * @return string
	 */
	public static function reference( array $r ) {
		return 'REF-' . strtoupper( substr( $r['token'], 0, 8 ) );
	}

	/**
	 * Updates a lead's status and notes.
	 *
	 * @param int         $id     Result id.
	 * @param string      $status New status.
	 * @param string|null $notes  Notes, or null to leave unchanged.
	 * @return bool
	 */
	public static function update_lead( $id, $status, $notes = null ) {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		$t    = BD_Install::tables();
		$data = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( null !== $notes ) {
			$data['notes'] = $notes;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update( $t['results'], $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Deletes results by id.
	 *
	 * @param int[] $ids Result ids.
	 * @return int Rows deleted.
	 */
	public static function delete( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$t            = BD_Install::tables();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t['results']} WHERE id IN ($placeholders)", $ids ) );
	}

	/**
	 * Builds the WHERE clause shared by the list screen and the CSV export.
	 *
	 * @param array $filters Keys: platform, classification, status, s.
	 * @return array{0: string, 1: array}
	 */
	public static function where( array $filters ) {
		global $wpdb;
		$sql  = array( '1=1' );
		$args = array();
		if ( ! empty( $filters['platform'] ) ) {
			$sql[]  = 'platform = %s';
			$args[] = $filters['platform'];
		}
		if ( ! empty( $filters['classification'] ) ) {
			$sql[]  = 'classification = %s';
			$args[] = $filters['classification'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$sql[]  = 'status = %s';
			$args[] = $filters['status'];
		}
		if ( ! empty( $filters['s'] ) ) {
			$like   = '%' . $wpdb->esc_like( $filters['s'] ) . '%';
			$sql[]  = '(full_name LIKE %s OR email LIKE %s)';
			$args[] = $like;
			$args[] = $like;
		}
		return array( implode( ' AND ', $sql ), $args );
	}

	/**
	 * Lists results for the admin screens.
	 *
	 * @param array  $filters  Filters.
	 * @param string $orderby  Column.
	 * @param string $order    ASC or DESC.
	 * @param int    $per_page Page size, 0 for all.
	 * @param int    $page     Page number from 1.
	 * @return array{items: array[], total: int}
	 */
	public static function query( array $filters, $orderby = 'completed_at', $order = 'DESC', $per_page = 20, $page = 1 ) {
		global $wpdb;
		$t       = BD_Install::tables();
		$allowed = array( 'completed_at', 'overall_score', 'full_name', 'platform', 'classification', 'status' );
		$orderby = in_array( $orderby, $allowed, true ) ? $orderby : 'completed_at';
		$order   = 'ASC' === strtoupper( (string) $order ) ? 'ASC' : 'DESC';
		list( $where, $args ) = self::where( $filters );

		$count_sql = "SELECT COUNT(*) FROM {$t['results']} WHERE $where";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );

		$sql = "SELECT * FROM {$t['results']} WHERE $where ORDER BY $orderby $order, id DESC";
		if ( $per_page > 0 ) {
			$sql   .= ' LIMIT %d OFFSET %d';
			$args[] = (int) $per_page;
			$args[] = (int) max( 0, ( $page - 1 ) * $per_page );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );
		return array(
			'items' => array_map( array( __CLASS__, 'hydrate' ), $rows ?: array() ),
			'total' => $total,
		);
	}

	/**
	 * Headline numbers for the dashboard.
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;
		$t     = BD_Install::tables();
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['results']}" );
		$last30      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['results']} WHERE completed_at >= %s", $since ) );
		$avg         = $wpdb->get_var( "SELECT AVG(overall_score) FROM {$t['results']}" );
		$new         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['results']} WHERE status = 'new'" );
		$by_platform = $wpdb->get_results( "SELECT platform, COUNT(*) n, AVG(overall_score) avg FROM {$t['results']} GROUP BY platform", ARRAY_A );
		$by_band     = $wpdb->get_results( "SELECT classification, COUNT(*) n FROM {$t['results']} GROUP BY classification", ARRAY_A );
		$weak        = $wpdb->get_results( "SELECT platform, dimension_scores FROM {$t['results']} WHERE status <> 'spam'", ARRAY_A );
		// phpcs:enable

		$dims = array();
		foreach ( $weak ?: array() as $w ) {
			foreach ( json_decode( $w['dimension_scores'], true ) ?: array() as $d ) {
				$k = $w['platform'] . '|' . $d['dimension'];
				if ( ! isset( $dims[ $k ] ) ) {
					$dims[ $k ] = array(
						'platform'  => $w['platform'],
						'dimension' => $d['dimension'],
						'sum'       => 0,
						'n'         => 0,
					);
				}
				$dims[ $k ]['sum'] += (float) $d['score'];
				$dims[ $k ]['n']++;
			}
		}
		$dimension_avgs = array_map(
			static function ( $d ) {
				return array(
					'platform'  => $d['platform'],
					'dimension' => $d['dimension'],
					'avg'       => round( $d['sum'] / $d['n'], 1 ),
					'n'         => $d['n'],
				);
			},
			array_values( $dims )
		);
		usort(
			$dimension_avgs,
			static function ( $a, $b ) {
				return $a['avg'] <=> $b['avg'];
			}
		);

		return array(
			'total'       => $total,
			'last30'      => $last30,
			'avg'         => null === $avg ? null : round( (float) $avg, 1 ),
			'new'         => $new,
			'by_platform' => $by_platform ?: array(),
			'by_band'     => $by_band ?: array(),
			'weakest'     => array_slice( $dimension_avgs, 0, 5 ),
		);
	}

	/**
	 * Records that the result was emailed.
	 *
	 * @param int $id Result id.
	 */
	public static function mark_emailed( $id ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $t['results'], array( 'emailed_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $id ) );
	}
}
