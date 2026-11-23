<?php
/**
 * One organisation profile per business owner per platform. A business owner can run the full
 * assessment for more than one platform (e.g. SME360 and ESO360) but has at most one organisation
 * profile per platform, enforced by a unique key on (user_id, platform).
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Organisations {

	/**
	 * Fields an owner may set on their own organisation.
	 *
	 * @return string[]
	 */
	public static function fields() {
		return array( 'name', 'sector', 'region', 'district', 'size_band', 'registration_status', 'phone', 'website', 'notes' );
	}

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
		$o            = (array) $row;
		$o['id']      = (int) $o['id'];
		$o['user_id'] = (int) $o['user_id'];
		return $o;
	}

	/**
	 * A user's organisation for a platform.
	 *
	 * @param int    $user_id  User id.
	 * @param string $platform Platform code.
	 * @return array|null
	 */
	public static function get_for( $user_id, $platform ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['organisations']} WHERE user_id = %d AND platform = %s", $user_id, $platform ) ) );
	}

	/**
	 * One organisation by id.
	 *
	 * @param int $id Organisation id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['organisations']} WHERE id = %d", (int) $id ) ) );
	}

	/**
	 * Every organisation belonging to a user, across platforms.
	 *
	 * @param int $user_id User id.
	 * @return array[]
	 */
	public static function for_user( $user_id ) {
		global $wpdb;
		$t = BD_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['organisations']} WHERE user_id = %d ORDER BY created_at DESC", (int) $user_id ) );
		return array_map( array( __CLASS__, 'hydrate' ), $rows ?: array() );
	}

	/**
	 * Validates and sanitises the fields an owner may submit.
	 *
	 * @param array $data Raw POST-like data.
	 * @return array|WP_Error Sanitised fields, or an error naming what is missing.
	 */
	public static function sanitize( array $data ) {
		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		if ( '' === trim( $name ) ) {
			return new WP_Error( 'bd_invalid', __( 'Enter the organisation name.', 'business-doctor' ), array( 'status' => 422 ) );
		}
		$website = trim( (string) ( $data['website'] ?? '' ) );
		return array(
			'name'                => mb_substr( $name, 0, 200 ),
			'sector'              => mb_substr( sanitize_text_field( (string) ( $data['sector'] ?? '' ) ), 0, 120 ),
			'region'              => mb_substr( sanitize_text_field( (string) ( $data['region'] ?? '' ) ), 0, 120 ),
			'district'            => mb_substr( sanitize_text_field( (string) ( $data['district'] ?? '' ) ), 0, 120 ),
			'size_band'           => mb_substr( sanitize_text_field( (string) ( $data['size_band'] ?? '' ) ), 0, 60 ),
			'registration_status' => mb_substr( sanitize_text_field( (string) ( $data['registration_status'] ?? '' ) ), 0, 60 ),
			'phone'               => mb_substr( sanitize_text_field( (string) ( $data['phone'] ?? '' ) ), 0, 40 ),
			'website'             => '' === $website ? '' : esc_url_raw( $website ),
			'notes'               => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
		);
	}

	/**
	 * Creates or updates a user's organisation profile for a platform.
	 *
	 * @param int    $user_id  User id.
	 * @param string $platform Platform code.
	 * @param array  $data     Raw fields, see sanitize().
	 * @return array|WP_Error The saved organisation.
	 */
	public static function save( $user_id, $platform, array $data ) {
		global $wpdb;
		$fields = self::sanitize( $data );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$t        = BD_Install::tables();
		$existing = self::get_for( $user_id, $platform );
		$now      = current_time( 'mysql', true );
		if ( $existing ) {
			$fields['updated_at'] = $now;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $t['organisations'], $fields, array( 'id' => $existing['id'] ) );
			return self::get( $existing['id'] );
		}
		$fields['user_id']    = (int) $user_id;
		$fields['platform']   = $platform;
		$fields['created_at'] = $now;
		$fields['updated_at'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( $t['organisations'], $fields );
		if ( ! $ok ) {
			return new WP_Error( 'bd_db', __( 'Your organisation profile could not be saved. Please try again.', 'business-doctor' ), array( 'status' => 500 ) );
		}
		return self::get( (int) $wpdb->insert_id );
	}
}
