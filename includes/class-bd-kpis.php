<?php
/**
 * The KPIs an approved prescription asks an organisation to track, and the check-ins logged against
 * them over time.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Kpis {

	/**
	 * Decodes a KPI row.
	 *
	 * @param object|null $row Row.
	 * @return array|null
	 */
	private static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		$k                    = (array) $row;
		$k['id']              = (int) $k['id'];
		$k['org_id']          = (int) $k['org_id'];
		$k['prescription_id'] = (int) $k['prescription_id'];
		return $k;
	}

	/**
	 * Decodes a check-in row.
	 *
	 * @param object|null $row Row.
	 * @return array|null
	 */
	private static function hydrate_checkin( $row ) {
		if ( ! $row ) {
			return null;
		}
		$c               = (array) $row;
		$c['id']         = (int) $c['id'];
		$c['kpi_id']     = (int) $c['kpi_id'];
		$c['created_by'] = (int) $c['created_by'];
		return $c;
	}

	/**
	 * One KPI by id.
	 *
	 * @param int $id KPI id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['kpis']} WHERE id = %d", (int) $id ) ) );
	}

	/**
	 * Every KPI for an organisation, each with its check-in history, newest check-in first.
	 *
	 * @param int $org_id Organisation id.
	 * @return array[]
	 */
	public static function for_org( $org_id ) {
		global $wpdb;
		$t    = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['kpis']} WHERE org_id = %d ORDER BY id ASC", (int) $org_id ) );
		$out  = array();
		foreach ( $rows ?: array() as $row ) {
			$k               = self::hydrate( $row );
			$k['checkins']   = self::checkins( $k['id'] );
			$out[]           = $k;
		}
		return $out;
	}

	/**
	 * A KPI's check-ins, newest first.
	 *
	 * @param int $kpi_id KPI id.
	 * @return array[]
	 */
	public static function checkins( $kpi_id ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['kpi_checkins']} WHERE kpi_id = %d ORDER BY id DESC", (int) $kpi_id ) );
		return array_map( array( __CLASS__, 'hydrate_checkin' ), $rows ?: array() );
	}

	/**
	 * Logs a check-in against a KPI.
	 *
	 * @param array  $kpi     KPI.
	 * @param string $value   The measured value, as the owner or staff member reports it.
	 * @param string $note    Optional note.
	 * @param int    $user_id The person logging it.
	 * @return array|WP_Error
	 */
	public static function add_checkin( array $kpi, $value, $note, $user_id ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return new WP_Error( 'bd_invalid', __( 'Enter a value for this check-in.', 'business-doctor' ), array( 'status' => 422 ) );
		}
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$t['kpi_checkins'],
			array(
				'kpi_id'     => $kpi['id'],
				'value'      => mb_substr( sanitize_text_field( $value ), 0, 64 ),
				'note'       => sanitize_textarea_field( (string) $note ),
				'created_by' => (int) $user_id,
				'created_at' => current_time( 'mysql', true ),
			)
		);
		return self::hydrate_checkin(
			(object) array(
				'id'         => $wpdb->insert_id,
				'kpi_id'     => $kpi['id'],
				'value'      => mb_substr( sanitize_text_field( $value ), 0, 64 ),
				'note'       => sanitize_textarea_field( (string) $note ),
				'created_by' => (int) $user_id,
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * The fields an owner may see about a KPI and its history.
	 *
	 * @param array $k KPI (with 'checkins' already attached, see for_org()).
	 * @return array
	 */
	public static function to_public( array $k ) {
		return array(
			'id'       => $k['id'],
			'name'     => $k['name'],
			'target'   => $k['target'],
			'checkins' => array_map(
				function ( $c ) {
					return array(
						'value'     => $c['value'],
						'note'      => $c['note'],
						'createdAt' => $c['created_at'],
					);
				},
				$k['checkins'] ?? array()
			),
		);
	}
}
