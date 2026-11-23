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
		self::upgrade_frameworks_table();
		self::create_tables();
		self::seed_frameworks();
		self::seed_full_frameworks();
		if ( false === get_option( BD_Settings::OPTION ) ) {
			add_option( BD_Settings::OPTION, BD_Settings::defaults() );
		}
		// Administrators manage the plugin; grant 'manage_business_doctor' to other roles
		// (for example a staff member who follows up leads) with any role-editor plugin.
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( 'manage_business_doctor' ) ) {
			$admin->add_cap( 'manage_business_doctor' );
		}
		self::add_business_owner_role();
		update_option( 'bd_db_version', BD_DB_VERSION );
	}

	/**
	 * Registers the Business Owner role (phase 2: accounts for the full, evidence-based
	 * assessment). Re-adding the capability on every activation/upgrade recovers it if another
	 * plugin ever stomps on the role; it never removes a capability something else added.
	 */
	private static function add_business_owner_role() {
		$role = get_role( 'business_owner' );
		if ( ! $role ) {
			add_role(
				'business_owner',
				__( 'Business Owner', 'business-doctor' ),
				array(
					'read'                  => true,
					'take_business_diagnostic' => true,
				)
			);
		} elseif ( ! $role->has_cap( 'take_business_diagnostic' ) ) {
			$role->add_cap( 'take_business_diagnostic' );
		}
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( 'manage_business_diagnostics' ) ) {
			$admin->add_cap( 'manage_business_diagnostics' );
		}
	}

	/**
	 * Upgrades the version-1 wp_bd_frameworks table (free assessment only) to version 2's shape,
	 * which also holds the full assessment's question banks: adds a 'purpose' column (defaulting
	 * every existing row to 'free', so nothing already published changes meaning) and widens the
	 * platform+version unique key to platform+purpose+version, since a platform code like SME360 now
	 * has one published row per purpose rather than one overall. dbDelta() can add a column or an
	 * index but never drops one, so the old index is dropped explicitly here, guarded so re-running
	 * this (every activation checks bd_db_version) is always a no-op once applied.
	 */
	private static function upgrade_frameworks_table() {
		global $wpdb;
		$t = self::tables();
		if ( ! self::table_exists( $t['frameworks'] ) ) {
			return; // Fresh install: create_tables() below makes it with the current shape directly.
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$has_purpose = $wpdb->get_var( "SHOW COLUMNS FROM {$t['frameworks']} LIKE 'purpose'" );
		if ( ! $has_purpose ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "ALTER TABLE {$t['frameworks']} ADD COLUMN purpose varchar(16) NOT NULL DEFAULT 'free' AFTER platform" );
		}
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$t['frameworks']}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$names   = wp_list_pluck( $indexes ?: array(), 'Key_name' );
		if ( in_array( 'platform_version', $names, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "ALTER TABLE {$t['frameworks']} DROP INDEX platform_version" );
		}
		if ( ! in_array( 'platform_purpose_version', $names, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "ALTER TABLE {$t['frameworks']} ADD UNIQUE KEY platform_purpose_version (platform, purpose, version)" );
		}
	}

	/**
	 * Whether a table exists in the current database.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
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
			'frameworks'         => $wpdb->prefix . 'bd_frameworks',
			'results'            => $wpdb->prefix . 'bd_results',
			'organisations'      => $wpdb->prefix . 'bd_organisations',
			'diagnostics'        => $wpdb->prefix . 'bd_diagnostics',
			'diagnostic_answers' => $wpdb->prefix . 'bd_diagnostic_answers',
			'payments'           => $wpdb->prefix . 'bd_payments',
			'entitlements'       => $wpdb->prefix . 'bd_entitlements',
			'webhook_events'     => $wpdb->prefix . 'bd_webhook_events',
			'case_diagnoses'     => $wpdb->prefix . 'bd_case_diagnoses',
			'prescriptions'      => $wpdb->prefix . 'bd_prescriptions',
			'actions'            => $wpdb->prefix . 'bd_actions',
			'kpis'               => $wpdb->prefix . 'bd_kpis',
			'kpi_checkins'       => $wpdb->prefix . 'bd_kpi_checkins',
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
  purpose varchar(16) NOT NULL DEFAULT 'free',
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
  UNIQUE KEY platform_purpose_version (platform,purpose,version),
  KEY platform_purpose_status (platform,purpose,status)
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

		// Phase 2: business-owner accounts and the full, evidence-based assessment.
		dbDelta(
			"CREATE TABLE {$t['organisations']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  platform varchar(32) NOT NULL,
  name varchar(191) NOT NULL,
  sector varchar(120) NULL,
  region varchar(120) NULL,
  district varchar(120) NULL,
  size_band varchar(32) NULL,
  registration_status varchar(32) NULL,
  phone varchar(32) NULL,
  website varchar(191) NULL,
  notes text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_platform (user_id,platform),
  KEY platform (platform)
) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$t['diagnostics']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  org_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  platform varchar(32) NOT NULL,
  framework_id bigint(20) unsigned NOT NULL,
  framework_version int(10) unsigned NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'draft',
  overall_score decimal(4,1) NULL,
  maturity varchar(16) NULL,
  confidence varchar(16) NULL,
  completion decimal(4,3) NULL,
  dimension_scores longtext NULL,
  evidence_share longtext NULL,
  started_at datetime NOT NULL,
  submitted_at datetime NULL,
  scored_at datetime NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY org_id (org_id),
  KEY user_id (user_id),
  KEY platform_status (platform,status)
) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$t['diagnostic_answers']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  diagnostic_id bigint(20) unsigned NOT NULL,
  question_code varchar(20) NOT NULL,
  value tinyint unsigned NULL,
  not_applicable tinyint(1) NOT NULL DEFAULT 0,
  evidence_class varchar(32) NULL,
  evidence_note text NULL,
  attachment_id bigint(20) unsigned NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY diagnostic_question (diagnostic_id,question_code)
) $charset;"
		);

		// Phase 3: Paystack checkout, entitlements that unlock the full assessment, and a minimal
		// invoice record generated once a payment settles.
		dbDelta(
			"CREATE TABLE {$t['payments']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  org_id bigint(20) unsigned NOT NULL,
  platform varchar(32) NOT NULL,
  reference varchar(64) NOT NULL,
  amount_ghs decimal(10,2) NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'pending',
  invoice_number varchar(32) NULL,
  provider_payload longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY reference (reference),
  KEY org_id (org_id),
  KEY user_id (user_id),
  KEY status (status)
) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$t['entitlements']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  org_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  platform varchar(32) NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'active',
  granted_via varchar(16) NOT NULL DEFAULT 'purchase',
  payment_id bigint(20) unsigned NULL,
  granted_by bigint(20) unsigned NULL,
  granted_at datetime NOT NULL,
  revoked_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY org_platform (org_id,platform),
  KEY user_id (user_id)
) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$t['webhook_events']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  provider varchar(16) NOT NULL,
  event_id varchar(191) NOT NULL,
  type varchar(64) NOT NULL,
  payload longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY provider_event (provider,event_id)
) $charset;"
		);

		// Phase 4: an AI-drafted (or manually written) diagnosis and prescription for a submitted full
		// assessment, each reviewed by a second staff member (four-eyes: the reviewer may never be the
		// drafter), and the action plan and KPIs an approved prescription produces.
		dbDelta(
			"CREATE TABLE {$t['case_diagnoses']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  diagnostic_id bigint(20) unsigned NOT NULL,
  org_id bigint(20) unsigned NOT NULL,
  platform varchar(32) NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'draft',
  summary longtext NULL,
  root_causes longtext NULL,
  priority varchar(16) NULL,
  risks longtext NULL,
  model_confidence decimal(3,2) NULL,
  ai_drafted tinyint(1) NOT NULL DEFAULT 0,
  version int(10) unsigned NOT NULL DEFAULT 1,
  supersedes_id bigint(20) unsigned NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  reviewed_by bigint(20) unsigned NULL,
  reviewed_at datetime NULL,
  review_note text NULL,
  PRIMARY KEY  (id),
  KEY diagnostic_id (diagnostic_id),
  KEY org_id (org_id)
) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$t['prescriptions']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  diagnosis_id bigint(20) unsigned NOT NULL,
  org_id bigint(20) unsigned NOT NULL,
  platform varchar(32) NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'draft',
  items longtext NULL,
  ai_drafted tinyint(1) NOT NULL DEFAULT 0,
  version int(10) unsigned NOT NULL DEFAULT 1,
  supersedes_id bigint(20) unsigned NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  submitted_at datetime NULL,
  reviewed_by bigint(20) unsigned NULL,
  reviewed_at datetime NULL,
  reviewer_note text NULL,
  PRIMARY KEY  (id),
  KEY diagnosis_id (diagnosis_id),
  KEY org_id (org_id),
  KEY status (status)
) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$t['actions']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  org_id bigint(20) unsigned NOT NULL,
  prescription_id bigint(20) unsigned NOT NULL,
  item_index int(10) unsigned NOT NULL DEFAULT 0,
  item_title varchar(191) NULL,
  text varchar(255) NOT NULL,
  owner_role varchar(16) NOT NULL DEFAULT 'owner',
  assignee_id bigint(20) unsigned NULL,
  due_date date NULL,
  status varchar(16) NOT NULL DEFAULT 'open',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  done_at datetime NULL,
  PRIMARY KEY  (id),
  KEY org_id (org_id),
  KEY prescription_id (prescription_id),
  KEY status (status)
) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$t['kpis']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  org_id bigint(20) unsigned NOT NULL,
  prescription_id bigint(20) unsigned NOT NULL,
  name varchar(191) NOT NULL,
  target varchar(64) NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY org_id (org_id),
  KEY prescription_id (prescription_id)
) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$t['kpi_checkins']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kpi_id bigint(20) unsigned NOT NULL,
  value varchar(64) NOT NULL,
  note text NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY kpi_id (kpi_id)
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
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['frameworks']} WHERE platform = %s AND purpose = 'free'", $platform ) );
			if ( (int) $exists > 0 ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$t['frameworks'],
				array(
					'platform'     => $platform,
					'purpose'      => 'free',
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

	/**
	 * Publishes version 1 of each approved full (evidence-based) question bank if the platform has
	 * none yet. Same never-overwrite rule as seed_frameworks(): once an administrator has a draft or
	 * published version of their own for a platform+purpose, this leaves it alone.
	 */
	public static function seed_full_frameworks() {
		global $wpdb;
		$t    = self::tables();
		$file = BD_DIR . 'data/full-frameworks.json';
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
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['frameworks']} WHERE platform = %s AND purpose = 'full'", $platform ) );
			if ( (int) $exists > 0 ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$t['frameworks'],
				array(
					'platform'     => $platform,
					'purpose'      => 'full',
					'code'         => $platform . '_FULL',
					'name'         => $fw['name'],
					'audience'     => $fw['audience'],
					'version'      => 1,
					'status'       => 'published',
					'dimensions'   => wp_json_encode( $fw['dimensions'] ),
					'questions'    => wp_json_encode( $fw['questions'] ),
					'note'         => 'Approved full 100-question evidence-based assessment, imported from Samakose AI.',
					'created_at'   => $now,
					'published_at' => $now,
				)
			);
		}
	}
}
