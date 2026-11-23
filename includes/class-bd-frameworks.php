<?php
/**
 * Versioned question banks. Exactly one version per platform is published at a time;
 * results record the version they were taken under, so editing a bank never changes past results.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Frameworks {

	/**
	 * Supported platforms and their public labels.
	 *
	 * @return array<string, array{label: string, short: string}>
	 */
	public static function platforms() {
		return array(
			'SME360'      => array(
				'label' => __( 'SME360: small and growing businesses', 'business-doctor' ),
				'short' => 'SME360',
			),
			'AGRIFOOD360' => array(
				'label' => __( 'AgriFood360: farms, agribusinesses and cooperatives', 'business-doctor' ),
				'short' => 'AgriFood360',
			),
			'ESO360'      => array(
				'label' => __( 'ESO360: accelerators, incubators and support organisations', 'business-doctor' ),
				'short' => 'ESO360',
			),
		);
	}

	/**
	 * Display name for a platform code.
	 *
	 * @param string $platform Platform code.
	 * @return string
	 */
	public static function short_name( $platform ) {
		$p = self::platforms();
		return isset( $p[ $platform ] ) ? $p[ $platform ]['short'] : $platform;
	}

	/**
	 * Decodes the JSON columns of a framework row.
	 *
	 * @param object|null $row Database row.
	 * @return array|null
	 */
	private static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		$f               = (array) $row;
		$f['id']         = (int) $f['id'];
		$f['version']    = (int) $f['version'];
		$f['dimensions'] = json_decode( $f['dimensions'], true ) ?: array();
		$f['questions']  = json_decode( $f['questions'], true ) ?: array();
		return $f;
	}

	/**
	 * The published version for a platform.
	 *
	 * @param string $platform Platform code.
	 * @return array|null
	 */
	public static function published( $platform ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['frameworks']} WHERE platform = %s AND status = 'published' ORDER BY version DESC LIMIT 1", $platform ) );
		return self::hydrate( $row );
	}

	/**
	 * One framework version by id.
	 *
	 * @param int $id Framework id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['frameworks']} WHERE id = %d", $id ) ) );
	}

	/**
	 * Every version of every platform, newest first within each platform.
	 *
	 * @return array[]
	 */
	public static function all_versions() {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$t['frameworks']} ORDER BY platform ASC, version DESC" );
		return array_map( array( __CLASS__, 'hydrate' ), $rows ?: array() );
	}

	/**
	 * Copies a version into a new draft with the next version number, or returns the platform's
	 * existing draft so there is never more than one.
	 *
	 * @param int $from_id Version to copy.
	 * @return int|WP_Error Draft id.
	 */
	public static function create_draft( $from_id ) {
		global $wpdb;
		$t    = BD_Install::tables();
		$from = self::get( $from_id );
		if ( ! $from ) {
			return new WP_Error( 'bd_not_found', __( 'That question bank was not found.', 'business-doctor' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$draft = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['frameworks']} WHERE platform = %s AND status = 'draft' LIMIT 1", $from['platform'] ) );
		if ( $draft ) {
			return (int) $draft;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$next = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version) FROM {$t['frameworks']} WHERE platform = %s", $from['platform'] ) ) + 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			$t['frameworks'],
			array(
				'platform'   => $from['platform'],
				'code'       => $from['code'],
				'name'       => $from['name'],
				'audience'   => $from['audience'],
				'version'    => $next,
				'status'     => 'draft',
				'dimensions' => wp_json_encode( $from['dimensions'] ),
				'questions'  => wp_json_encode( $from['questions'] ),
				/* translators: %d: version number copied from. */
				'note'       => sprintf( __( 'Draft copied from version %d.', 'business-doctor' ), $from['version'] ),
				'created_by' => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
			)
		);
		return $ok ? (int) $wpdb->insert_id : new WP_Error( 'bd_db', __( 'The draft could not be created.', 'business-doctor' ) );
	}

	/**
	 * Validates a question list: unique codes, a dimension, text and four options each.
	 *
	 * @param array $questions Questions.
	 * @return true|WP_Error
	 */
	public static function validate_questions( array $questions ) {
		if ( count( $questions ) < 5 ) {
			return new WP_Error( 'bd_invalid', __( 'A question bank needs at least 5 questions.', 'business-doctor' ) );
		}
		$codes = array();
		foreach ( $questions as $i => $q ) {
			$n = $i + 1;
			foreach ( array( 'code', 'dimension', 'text' ) as $k ) {
				if ( empty( $q[ $k ] ) || '' === trim( (string) $q[ $k ] ) ) {
					/* translators: 1: question number, 2: field name. */
					return new WP_Error( 'bd_invalid', sprintf( __( 'Question %1$d is missing its %2$s.', 'business-doctor' ), $n, $k ) );
				}
			}
			foreach ( array( 'A', 'B', 'C', 'D' ) as $l ) {
				if ( empty( $q['options'][ $l ] ) || '' === trim( (string) $q['options'][ $l ] ) ) {
					/* translators: 1: question number, 2: answer letter. */
					return new WP_Error( 'bd_invalid', sprintf( __( 'Question %1$d is missing answer %2$s.', 'business-doctor' ), $n, $l ) );
				}
			}
			if ( isset( $codes[ $q['code'] ] ) ) {
				/* translators: %s: question code. */
				return new WP_Error( 'bd_invalid', sprintf( __( 'The code %s is used twice.', 'business-doctor' ), $q['code'] ) );
			}
			$codes[ $q['code'] ] = true;
		}
		return true;
	}

	/**
	 * Dimensions in the order they first appear in the question list.
	 *
	 * @param array $questions Questions.
	 * @return string[]
	 */
	public static function dimensions_of( array $questions ) {
		$seen = array();
		foreach ( $questions as $q ) {
			$seen[ $q['dimension'] ] = true;
		}
		return array_keys( $seen );
	}

	/**
	 * Saves edits to a draft. Published and retired versions cannot be edited.
	 *
	 * @param int    $id        Draft id.
	 * @param string $name      Display name.
	 * @param string $audience  Who it is for.
	 * @param array  $questions Questions.
	 * @param string $note      Change note.
	 * @return true|WP_Error
	 */
	public static function save_draft( $id, $name, $audience, array $questions, $note ) {
		global $wpdb;
		$t = BD_Install::tables();
		$f = self::get( $id );
		if ( ! $f || 'draft' !== $f['status'] ) {
			return new WP_Error( 'bd_not_draft', __( 'Only a draft can be edited. Create a draft first.', 'business-doctor' ) );
		}
		$valid = self::validate_questions( $questions );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$t['frameworks'],
			array(
				'name'       => $name,
				'audience'   => $audience,
				'questions'  => wp_json_encode( array_values( $questions ) ),
				'dimensions' => wp_json_encode( self::dimensions_of( $questions ) ),
				'note'       => $note,
			),
			array( 'id' => $id )
		);
		return true;
	}

	/**
	 * Publishes a draft and retires the platform's previous published version.
	 *
	 * @param int $id Draft id.
	 * @return true|WP_Error
	 */
	public static function publish( $id ) {
		global $wpdb;
		$t = BD_Install::tables();
		$f = self::get( $id );
		if ( ! $f || 'draft' !== $f['status'] ) {
			return new WP_Error( 'bd_not_draft', __( 'Only a draft can be published.', 'business-doctor' ) );
		}
		$valid = self::validate_questions( $f['questions'] );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		// One transaction, so a platform is never left with no live version or with two.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'START TRANSACTION' );
		$retired   = $wpdb->query( $wpdb->prepare( "UPDATE {$t['frameworks']} SET status = 'retired' WHERE platform = %s AND status = 'published'", $f['platform'] ) );
		$published = $wpdb->update(
			$t['frameworks'],
			array(
				'status'       => 'published',
				'published_at' => current_time( 'mysql', true ),
			),
			array(
				'id'     => $id,
				'status' => 'draft',
			)
		);
		if ( false === $retired || 1 !== $published ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'bd_db', __( 'The draft could not be published. Nothing was changed.', 'business-doctor' ) );
		}
		$wpdb->query( 'COMMIT' );
		// phpcs:enable
		return true;
	}

	/**
	 * Deletes a draft.
	 *
	 * @param int $id Draft id.
	 * @return true|WP_Error
	 */
	public static function delete_draft( $id ) {
		global $wpdb;
		$t = BD_Install::tables();
		$f = self::get( $id );
		if ( ! $f || 'draft' !== $f['status'] ) {
			return new WP_Error( 'bd_not_draft', __( 'Only a draft can be deleted.', 'business-doctor' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $t['frameworks'], array( 'id' => $id ) );
		return true;
	}

	/**
	 * Questions as served to the public form: no weights, nothing internal.
	 *
	 * @param array $framework Framework.
	 * @return array[]
	 */
	public static function public_questions( array $framework ) {
		return array_map(
			static function ( $q ) {
				return array(
					'code'      => (string) $q['code'],
					'dimension' => (string) $q['dimension'],
					'text'      => (string) $q['text'],
					'options'   => array(
						'A' => (string) $q['options']['A'],
						'B' => (string) $q['options']['B'],
						'C' => (string) $q['options']['C'],
						'D' => (string) $q['options']['D'],
					),
				);
			},
			$framework['questions']
		);
	}
}
