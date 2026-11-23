<?php
/**
 * Admin menu, dashboard, results and leads, CSV export and settings.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Admin {

	const CAP = 'manage_business_doctor';

	/**
	 * Hooks admin screens and actions.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_bd_export', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_post_bd_update_lead', array( __CLASS__, 'update_lead' ) );
		add_action( 'admin_post_bd_delete_result', array( __CLASS__, 'delete_result' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BD_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Whether the current user may manage the plugin.
	 *
	 * @return bool
	 */
	public static function can() {
		return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
	}

	/**
	 * The capability used for menus: administrators always pass through manage_options.
	 *
	 * @return string
	 */
	private static function menu_cap() {
		return current_user_can( self::CAP ) ? self::CAP : 'manage_options';
	}

	/**
	 * Admin menu.
	 */
	public static function menu() {
		$cap = self::menu_cap();
		add_menu_page( __( 'Business Doctor', 'business-doctor' ), __( 'Business Doctor', 'business-doctor' ), $cap, 'business-doctor', array( __CLASS__, 'dashboard' ), 'dashicons-heart', 26 );
		add_submenu_page( 'business-doctor', __( 'Dashboard', 'business-doctor' ), __( 'Dashboard', 'business-doctor' ), $cap, 'business-doctor', array( __CLASS__, 'dashboard' ) );
		add_submenu_page( 'business-doctor', __( 'Results and Leads', 'business-doctor' ), __( 'Results and Leads', 'business-doctor' ), $cap, 'bd-results', array( __CLASS__, 'results' ) );
		add_submenu_page( 'business-doctor', __( 'Question Banks', 'business-doctor' ), __( 'Question Banks', 'business-doctor' ), $cap, 'bd-frameworks', array( 'BD_Frameworks_Admin', 'page' ) );
		add_submenu_page( 'business-doctor', __( 'Settings', 'business-doctor' ), __( 'Settings', 'business-doctor' ), 'manage_options', 'bd-settings', array( __CLASS__, 'settings' ) );
	}

	/**
	 * Admin styles on the plugin's own screens only.
	 *
	 * @param string $hook Screen hook.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'business-doctor' ) && false === strpos( (string) $hook, 'bd-' ) ) {
			return;
		}
		wp_enqueue_style( 'business-doctor-admin', BD_URL . 'assets/css/admin.css', array(), BD_VERSION );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=bd-settings' ) ) . '">' . esc_html__( 'Settings', 'business-doctor' ) . '</a>' );
		return $links;
	}

	/**
	 * Admin notice from a redirect.
	 */
	private static function notice() {
		if ( empty( $_GET['bd_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$msgs = array(
			'saved'     => __( 'Saved.', 'business-doctor' ),
			'deleted'   => __( 'Deleted.', 'business-doctor' ),
			'published' => __( 'Published. New assessments now use this version; earlier results keep the version they were taken under.', 'business-doctor' ),
			'draft'     => __( 'Draft ready. Edit it below, then publish when it has been reviewed.', 'business-doctor' ),
			'error'     => __( 'That did not work. Please try again.', 'business-doctor' ),
		);
		$key = sanitize_key( wp_unslash( $_GET['bd_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $msgs[ $key ] ) ) {
			echo '<div class="notice notice-' . ( 'error' === $key ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( $msgs[ $key ] ) . '</p></div>';
		}
		if ( ! empty( $_GET['bd_err'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['bd_err'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Dashboard screen.
	 */
	public static function dashboard() {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'business-doctor' ) );
		}
		$st      = BD_Results::stats();
		$bands   = array_fill_keys( BD_Scoring::BANDS, 0 );
		foreach ( $st['by_band'] as $b ) {
			$bands[ $b['classification'] ] = (int) $b['n'];
		}
		$max_band = max( 1, max( $bands ) );
		?>
		<div class="wrap bd-admin">
			<h1><?php esc_html_e( 'Business Doctor', 'business-doctor' ); ?></h1>
			<?php self::notice(); ?>
			<div class="bd-tiles">
				<div class="bd-tile"><span><?php esc_html_e( 'Assessments completed', 'business-doctor' ); ?></span><strong><?php echo esc_html( number_format_i18n( $st['total'] ) ); ?></strong></div>
				<div class="bd-tile"><span><?php esc_html_e( 'Last 30 days', 'business-doctor' ); ?></span><strong><?php echo esc_html( number_format_i18n( $st['last30'] ) ); ?></strong></div>
				<div class="bd-tile"><span><?php esc_html_e( 'Average score', 'business-doctor' ); ?></span><strong><?php echo null === $st['avg'] ? '&ndash;' : esc_html( $st['avg'] ); ?></strong></div>
				<div class="bd-tile"><span><?php esc_html_e( 'New leads to follow up', 'business-doctor' ); ?></span><strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=bd-results&status=new' ) ); ?>"><?php echo esc_html( number_format_i18n( $st['new'] ) ); ?></a></strong></div>
			</div>

			<div class="bd-columns">
				<div class="bd-panel">
					<h2><?php esc_html_e( 'Results by band', 'business-doctor' ); ?></h2>
					<ul class="bd-bars">
						<?php foreach ( $bands as $band => $n ) : ?>
							<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=bd-results&classification=' . rawurlencode( $band ) ) ); ?>"><?php echo esc_html( $band ); ?></a><span class="bd-bar"><span class="bd-band-<?php echo esc_attr( sanitize_title( $band ) ); ?>" style="width:<?php echo esc_attr( round( $n / $max_band * 100 ) ); ?>%"></span></span><b><?php echo esc_html( $n ); ?></b></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<div class="bd-panel">
					<h2><?php esc_html_e( 'By platform', 'business-doctor' ); ?></h2>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Platform', 'business-doctor' ); ?></th><th><?php esc_html_e( 'Results', 'business-doctor' ); ?></th><th><?php esc_html_e( 'Average', 'business-doctor' ); ?></th></tr></thead><tbody>
					<?php if ( ! $st['by_platform'] ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No results yet.', 'business-doctor' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $st['by_platform'] as $p ) : ?>
						<tr><td><?php echo esc_html( BD_Frameworks::short_name( $p['platform'] ) ); ?></td><td><?php echo esc_html( $p['n'] ); ?></td><td><?php echo esc_html( round( (float) $p['avg'], 1 ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
				</div>
				<div class="bd-panel">
					<h2><?php esc_html_e( 'Weakest dimensions overall', 'business-doctor' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Where respondents score lowest: a guide to which services and training to offer first.', 'business-doctor' ); ?></p>
					<table class="widefat striped"><tbody>
					<?php if ( ! $st['weakest'] ) : ?>
						<tr><td><?php esc_html_e( 'No results yet.', 'business-doctor' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $st['weakest'] as $w ) : ?>
						<tr><td><?php echo esc_html( $w['dimension'] ); ?> <span class="description">(<?php echo esc_html( BD_Frameworks::short_name( $w['platform'] ) ); ?>)</span></td><td><?php echo esc_html( $w['avg'] ); ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
				</div>
			</div>

			<div class="bd-panel">
				<h2><?php esc_html_e( 'Put the assessment on a page', 'business-doctor' ); ?></h2>
				<p><?php esc_html_e( 'Add a Shortcode block to any page and paste one of these:', 'business-doctor' ); ?></p>
				<table class="widefat striped"><tbody>
					<tr><td><code>[business_doctor_assessment]</code></td><td><?php esc_html_e( 'Visitors choose SME360, AgriFood360 or ESO360.', 'business-doctor' ); ?></td></tr>
					<tr><td><code>[business_doctor_assessment platform="SME360"]</code></td><td><?php esc_html_e( 'SME360 only.', 'business-doctor' ); ?></td></tr>
					<tr><td><code>[business_doctor_assessment platform="AGRIFOOD360"]</code></td><td><?php esc_html_e( 'AgriFood360 only.', 'business-doctor' ); ?></td></tr>
					<tr><td><code>[business_doctor_assessment platform="ESO360"]</code></td><td><?php esc_html_e( 'ESO360 only.', 'business-doctor' ); ?></td></tr>
				</tbody></table>
			</div>
		</div>
		<?php
	}

	/**
	 * Results list, or one result when action=view.
	 */
	public static function results() {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'business-doctor' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['action'] ) && 'view' === $_GET['action'] && ! empty( $_GET['id'] ) ) {
			self::view_result( absint( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$table = new BD_Results_Table();
		$table->process_bulk_action();
		$table->prepare_items();
		?>
		<div class="wrap bd-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Results and Leads', 'business-doctor' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'bd_export' ), $table->current_filters() ), admin_url( 'admin-post.php' ) ), 'bd_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'business-doctor' ); ?></a>
			<hr class="wp-header-end">
			<?php self::notice(); ?>
			<form method="get">
				<input type="hidden" name="page" value="bd-results">
				<?php $table->search_box( __( 'Search name or email', 'business-doctor' ), 'bd-search' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * One result with lead controls.
	 *
	 * @param int $id Result id.
	 */
	private static function view_result( $id ) {
		$r = BD_Results::get( $id );
		if ( ! $r ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Result not found.', 'business-doctor' ) . '</p></div>';
			return;
		}
		$f = BD_Scoring::findings( $r['dimension_scores'] );
		?>
		<div class="wrap bd-admin">
			<h1><?php echo esc_html( $r['full_name'] ); ?> <span class="bd-ref"><?php echo esc_html( BD_Results::reference( $r ) ); ?></span></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=bd-results' ) ); ?>">&larr; <?php esc_html_e( 'All results', 'business-doctor' ); ?></a></p>
			<?php self::notice(); ?>
			<div class="bd-columns">
				<div class="bd-panel">
					<p class="bd-big bd-band-text-<?php echo esc_attr( sanitize_title( $r['classification'] ) ); ?>"><?php echo esc_html( $r['overall_score'] ); ?><small>/100</small> <?php echo esc_html( $r['classification'] ); ?></p>
					<table class="form-table" role="presentation">
						<tr><th><?php esc_html_e( 'Email', 'business-doctor' ); ?></th><td><a href="mailto:<?php echo esc_attr( $r['email'] ); ?>"><?php echo esc_html( $r['email'] ); ?></a></td></tr>
						<tr><th><?php esc_html_e( 'Platform', 'business-doctor' ); ?></th><td><?php echo esc_html( BD_Frameworks::short_name( $r['platform'] ) ); ?> <?php /* translators: %d: version number. */ echo esc_html( sprintf( __( '(question bank version %d)', 'business-doctor' ), $r['framework_version'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Completed', 'business-doctor' ); ?></th><td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $r['completed_at'] . ' UTC' ) ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Result emailed', 'business-doctor' ); ?></th><td><?php echo $r['emailed_at'] ? esc_html( wp_date( get_option( 'date_format' ), (int) strtotime( $r['emailed_at'] . ' UTC' ) ) ) : esc_html__( 'No', 'business-doctor' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Strengths', 'business-doctor' ); ?></th><td><?php echo esc_html( implode( ', ', $f['strengths'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Priority areas', 'business-doctor' ); ?></th><td><?php echo esc_html( implode( ', ', $f['priorities'] ) ); ?></td></tr>
					</table>
					<p><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( BD_Report::url( $r['token'] ) ); ?>"><?php esc_html_e( 'Open printable report', 'business-doctor' ); ?></a></p>
				</div>
				<div class="bd-panel">
					<h2><?php esc_html_e( 'Dimension scores', 'business-doctor' ); ?></h2>
					<ul class="bd-bars">
						<?php foreach ( $r['dimension_scores'] as $d ) : ?>
							<li><span><?php echo esc_html( $d['dimension'] ); ?></span><span class="bd-bar"><span style="width:<?php echo esc_attr( max( 0, min( 100, (float) $d['score'] ) ) ); ?>%"></span></span><b><?php echo esc_html( $d['score'] ); ?></b></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<div class="bd-panel">
					<h2><?php esc_html_e( 'Lead follow-up', 'business-doctor' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'bd_update_lead_' . $r['id'] ); ?>
						<input type="hidden" name="action" value="bd_update_lead">
						<input type="hidden" name="id" value="<?php echo esc_attr( $r['id'] ); ?>">
						<p><label for="bd-status"><strong><?php esc_html_e( 'Status', 'business-doctor' ); ?></strong></label><br>
						<select id="bd-status" name="status">
							<?php foreach ( BD_Results::status_labels() as $k => $label ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $r['status'], $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select></p>
						<p><label for="bd-notes"><strong><?php esc_html_e( 'Notes', 'business-doctor' ); ?></strong></label><br>
						<textarea id="bd-notes" name="notes" rows="6" class="large-text"><?php echo esc_textarea( (string) $r['notes'] ); ?></textarea></p>
						<p class="description"><?php esc_html_e( 'The visitor acknowledged the privacy notice. That is not consent to marketing.', 'business-doctor' ); ?></p>
						<?php submit_button( __( 'Save follow-up', 'business-doctor' ) ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this result permanently? This cannot be undone.', 'business-doctor' ) ); ?>');">
						<?php wp_nonce_field( 'bd_delete_result_' . $r['id'] ); ?>
						<input type="hidden" name="action" value="bd_delete_result">
						<input type="hidden" name="id" value="<?php echo esc_attr( $r['id'] ); ?>">
						<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete this result', 'business-doctor' ); ?></button>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Saves lead status and notes.
	 */
	public static function update_lead() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! self::can() || ! check_admin_referer( 'bd_update_lead_' . $id ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'business-doctor' ) );
		}
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$notes  = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		$ok     = BD_Results::update_lead( $id, $status, $notes );
		wp_safe_redirect( admin_url( 'admin.php?page=bd-results&action=view&id=' . $id . '&bd_msg=' . ( $ok ? 'saved' : 'error' ) ) );
		exit;
	}

	/**
	 * Deletes one result.
	 */
	public static function delete_result() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! self::can() || ! check_admin_referer( 'bd_delete_result_' . $id ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'business-doctor' ) );
		}
		BD_Results::delete( array( $id ) );
		wp_safe_redirect( admin_url( 'admin.php?page=bd-results&bd_msg=deleted' ) );
		exit;
	}

	/**
	 * Streams results as CSV, honouring the current filters.
	 */
	public static function export_csv() {
		if ( ! self::can() || ! check_admin_referer( 'bd_export' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'business-doctor' ) );
		}
		$filters = array();
		foreach ( array( 'platform', 'classification', 'status', 's' ) as $k ) {
			$filters[ $k ] = isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : '';
		}
		$rows = BD_Results::query( $filters, 'completed_at', 'DESC', 0 );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="business-doctor-results-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Excel needs the BOM to read UTF-8.
		$dims = array();
		foreach ( $rows['items'] as $r ) {
			foreach ( $r['dimension_scores'] as $d ) {
				$dims[ $d['dimension'] ] = true;
			}
		}
		$dims = array_keys( $dims );
		fputcsv( $out, array_merge( array( 'Reference', 'Completed (UTC)', 'Name', 'Email', 'Platform', 'Version', 'Score', 'Band', 'Status', 'Notes' ), $dims ) );
		foreach ( $rows['items'] as $r ) {
			$by = array();
			foreach ( $r['dimension_scores'] as $d ) {
				$by[ $d['dimension'] ] = $d['score'];
			}
			$line = array( BD_Results::reference( $r ), $r['completed_at'], $r['full_name'], $r['email'], $r['platform'], $r['framework_version'], $r['overall_score'], $r['classification'], $r['status'], (string) $r['notes'] );
			foreach ( $dims as $d ) {
				$line[] = isset( $by[ $d ] ) ? $by[ $d ] : '';
			}
			// Neutralise spreadsheet formulas in visitor-supplied text.
			$line = array_map(
				static function ( $v ) {
					$v = (string) $v;
					return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
				},
				$line
			);
			fputcsv( $out, $line );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Registers the settings option.
	 */
	public static function register_settings() {
		register_setting(
			'bd_settings_group',
			BD_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'BD_Settings', 'sanitize' ),
			)
		);
	}

	/**
	 * Settings screen.
	 */
	public static function settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'business-doctor' ) );
		}
		$s = BD_Settings::all();
		$o = BD_Settings::OPTION;
		?>
		<div class="wrap bd-admin">
			<h1><?php esc_html_e( 'Business Doctor Settings', 'business-doctor' ); ?></h1>
			<?php settings_errors( $o ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'bd_settings_group' ); ?>
				<h2><?php esc_html_e( 'Assessments', 'business-doctor' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Offer these assessments', 'business-doctor' ); ?></th><td>
						<?php foreach ( BD_Frameworks::platforms() as $code => $p ) : ?>
							<label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[enabled_platforms][]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, (array) $s['enabled_platforms'], true ) ); ?>> <?php echo esc_html( $p['label'] ); ?></label><br>
						<?php endforeach; ?>
					</td></tr>
					<tr><th><?php esc_html_e( 'Score bands', 'business-doctor' ); ?></th><td>
						<p><?php esc_html_e( 'Critical Attention below', 'business-doctor' ); ?> <input type="number" min="1" max="100" step="0.1" class="small-text" name="<?php echo esc_attr( $o ); ?>[critical_below]" value="<?php echo esc_attr( $s['critical_below'] ); ?>"></p>
						<p><?php esc_html_e( 'Needs Improvement below', 'business-doctor' ); ?> <input type="number" min="1" max="100" step="0.1" class="small-text" name="<?php echo esc_attr( $o ); ?>[needs_below]" value="<?php echo esc_attr( $s['needs_below'] ); ?>"></p>
						<p><?php esc_html_e( 'Developing below', 'business-doctor' ); ?> <input type="number" min="1" max="100" step="0.1" class="small-text" name="<?php echo esc_attr( $o ); ?>[developing_below]" value="<?php echo esc_attr( $s['developing_below'] ); ?>"> <?php esc_html_e( 'otherwise Strong Foundation', 'business-doctor' ); ?></p>
						<p class="description"><?php esc_html_e( 'Defaults 40, 60 and 80, as approved for Samakose AI. Changes apply to new results only.', 'business-doctor' ); ?></p>
					</td></tr>
					<tr><th><label for="bd-full"><?php esc_html_e( 'Full assessment page', 'business-doctor' ); ?></label></th><td><input type="url" id="bd-full" class="regular-text" name="<?php echo esc_attr( $o ); ?>[full_assessment_url]" value="<?php echo esc_attr( $s['full_assessment_url'] ); ?>" placeholder="https://"><p class="description"><?php esc_html_e( 'Where the "Explore the full Business Health Assessment" button goes, for example your pricing or contact page.', 'business-doctor' ); ?></p></td></tr>
					<tr><th><label for="bd-privacy"><?php esc_html_e( 'Privacy notice page', 'business-doctor' ); ?></label></th><td><input type="url" id="bd-privacy" class="regular-text" name="<?php echo esc_attr( $o ); ?>[privacy_url]" value="<?php echo esc_attr( $s['privacy_url'] ); ?>" placeholder="https://"><p class="description"><?php esc_html_e( 'Leave empty to use the site privacy policy page.', 'business-doctor' ); ?></p></td></tr>
				</table>
				<h2><?php esc_html_e( 'Notifications', 'business-doctor' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'New-lead email', 'business-doctor' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[notify_on_complete]" value="1" <?php checked( $s['notify_on_complete'] ); ?>> <?php esc_html_e( 'Email staff when someone completes an assessment', 'business-doctor' ); ?></label></td></tr>
					<tr><th><label for="bd-notify"><?php esc_html_e( 'Send to', 'business-doctor' ); ?></label></th><td><input type="email" id="bd-notify" class="regular-text" name="<?php echo esc_attr( $o ); ?>[notify_email]" value="<?php echo esc_attr( $s['notify_email'] ); ?>"></td></tr>
				</table>
				<h2><?php esc_html_e( 'Branding', 'business-doctor' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="bd-brand"><?php esc_html_e( 'Brand name', 'business-doctor' ); ?></label></th><td><input type="text" id="bd-brand" class="regular-text" name="<?php echo esc_attr( $o ); ?>[brand_name]" value="<?php echo esc_attr( $s['brand_name'] ); ?>"></td></tr>
					<tr><th><label for="bd-byline"><?php esc_html_e( 'Byline', 'business-doctor' ); ?></label></th><td><input type="text" id="bd-byline" class="regular-text" name="<?php echo esc_attr( $o ); ?>[brand_byline]" value="<?php echo esc_attr( $s['brand_byline'] ); ?>"></td></tr>
					<tr><th><label for="bd-site"><?php esc_html_e( 'Footer website', 'business-doctor' ); ?></label></th><td><input type="text" id="bd-site" class="regular-text" name="<?php echo esc_attr( $o ); ?>[site_label]" value="<?php echo esc_attr( $s['site_label'] ); ?>"></td></tr>
				</table>
				<h2><?php esc_html_e( 'Security and data', 'business-doctor' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="bd-rate"><?php esc_html_e( 'Submissions per visitor', 'business-doctor' ); ?></label></th><td><input type="number" id="bd-rate" min="1" max="100" class="small-text" name="<?php echo esc_attr( $o ); ?>[rate_limit]" value="<?php echo esc_attr( $s['rate_limit'] ); ?>"> <?php esc_html_e( 'per 15 minutes', 'business-doctor' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Proxy', 'business-doctor' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[trust_proxy]" value="1" <?php checked( $s['trust_proxy'] ); ?>> <?php esc_html_e( 'This site sits behind Cloudflare or another trusted proxy', 'business-doctor' ); ?></label><p class="description"><?php esc_html_e( 'Only tick this if it is true; otherwise visitors could fake their address to avoid the limit.', 'business-doctor' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Uninstall', 'business-doctor' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[delete_on_uninstall]" value="1" <?php checked( $s['delete_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete all results, question banks and settings when the plugin is deleted', 'business-doctor' ); ?></label></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
