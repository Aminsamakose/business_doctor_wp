<?php
/**
 * Creates and upgrades the plugin's database tables and seeds the approved question banks.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Install {

	/**
	 * Runs on plugin activation.
	 */
	public static function activate() {
		self::create_tables();
		self::seed_frameworks();
		if ( false === get_option( BD_Settings::OPTION ) ) {
			add_option( BD_Settings::OPTION, BD_Settings::defaults() );
		}
		// Administrators manage the plugin; grant 'manage_business_doctor' to other roles
		// (for example a staff member who follows up leads) with any role-editor plugin.
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( 'manage_business_doctor' ) ) {
			$admin->add_cap( 'manage_business_doctor' );
		}
		update_option( 'bd_db_version', BD_DB_VERSION );
	}

	/**
	 * Applies schema changes after a plugin update, without needing reactivation.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'bd_db_version' ) !== BD_DB_VERSION ) {
			self::activate();
		}
	}

	/**
	 * Table names.
	 *
	 * @return array{frameworks: string, results: string}
	 */
	public static function tables() {
		global $wpdb;
		return array(
			'frameworks' => $wpdb->prefix . 'bd_frameworks',
			'results'    => $wpdb->prefix . 'bd_results',
		);
	}

	/**
	 * Creates or updates tables with dbDelta. Safe to run repeatedly.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::tables();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$t['frameworks']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  platform varchar(32) NOT NULL,
  code varchar(64) NOT NULL,
  name varchar(191) NOT NULL,
  audience text NULL,
  version int(10) unsigned NOT NULL DEFAULT 1,
  status varchar(16) NOT NULL DEFAULT 'draft',
  dimensions longtext NOT NULL,
  questions longtext NOT NULL,
  note text NULL,
  created_by bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  published_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY platform_version (platform,version),
  KEY platform_status (platform,status)
) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$t['results']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token char(32) NOT NULL,
  platform varchar(32) NOT NULL,
  framework_id bigint(20) unsigned NOT NULL,
  framework_version int(10) unsigned NOT NULL,
  full_name varchar(191) NOT NULL,
  email varchar(191) NOT NULL,
  email_norm varchar(150) NOT NULL,
  overall_score decimal(4,1) NOT NULL,
  classification varchar(32) NOT NULL,
  dimension_scores longtext NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'new',
  notes text NULL,
  ip_hash char(64) NULL,
  completed_at datetime NOT NULL,
  emailed_at datetime NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  UNIQUE KEY email_platform (email_norm,platform),
  KEY platform (platform),
  KEY classification (classification),
  KEY status (status),
  KEY completed_at (completed_at)
) $charset;"
		);
	}

	/**
	 * Publishes version 1 of each approved free question bank if the platform has none yet.
	 * Never overwrites a bank an administrator has already created or edited.
	 */
	public static function seed_frameworks() {
		global $wpdb;
		$t    = self::tables();
		$file = BD_DIR . 'data/frameworks.json';
		if ( ! is_readable( $file ) ) {
			return;
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) ) {
			return;
		}
		$now = current_time( 'mysql', true );
		foreach ( $data as $platform => $fw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['frameworks']} WHERE platform = %s", $platform ) );
			if ( (int) $exists > 0 ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$t['frameworks'],
				array(
					'platform'     => $platform,
					'code'         => $fw['code'],
					'name'         => $fw['name'],
					'audience'     => $fw['audience'],
					'version'      => 1,
					'status'       => 'published',
					'dimensions'   => wp_json_encode( $fw['dimensions'] ),
					'questions'    => wp_json_encode( $fw['questions'] ),
					'note'         => 'Approved free 25-question bank, imported from Samakose AI (published 16 October 2026).',
					'created_at'   => $now,
					'published_at' => $now,
				)
			);
		}
	}
}
