<?php
/**
 * Admin screen for staff to review full (evidence-weighted) assessments business owners have
 * taken: list, and a read-only detail view of one submission with its answers and evidence.
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Diagnostics_Admin {

	const CAP = 'manage_business_diagnostics';

	/**
	 * Hooks the admin menu.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	/**
	 * Whether the current user may review diagnostics.
	 *
	 * @return bool
	 */
	public static function can() {
		return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
	}

	/**
	 * Admin menu entry, under Business Doctor.
	 */
	public static function menu() {
		if ( ! self::can() ) {
			return;
		}
		add_submenu_page(
			'business-doctor',
			__( 'Full Assessments', 'business-doctor' ),
			__( 'Full Assessments', 'business-doctor' ),
			self::CAP,
			'bd-diagnostics',
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * List, or one diagnostic's detail when action=view.
	 */
	public static function page() {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'business-doctor' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['action'] ) && 'view' === $_GET['action'] && ! empty( $_GET['id'] ) ) {
			self::detail( absint( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$table = new BD_Diagnostics_Table();
		$table->prepare_items();
		?>
		<div class="wrap bd-admin">
			<h1><?php esc_html_e( 'Full Assessments', 'business-doctor' ); ?></h1>
			<p class="description"><?php esc_html_e( 'The evidence-weighted assessment business owners take after registering and completing their organisation profile. Unlike the free assessment, every answer here carries an evidence class, and the weighting and scoring match Samakose AI\'s full assessment exactly.', 'business-doctor' ); ?></p>
			<form method="get">
				<input type="hidden" name="page" value="bd-diagnostics">
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Read-only detail: organisation, scores, and every answer with its evidence.
	 *
	 * @param int $id Diagnostic id.
	 */
	private static function detail( $id ) {
		$d = BD_Diagnostics::get( $id );
		if ( ! $d ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Assessment not found.', 'business-doctor' ) . '</p></div>';
			return;
		}
		$org      = BD_Organisations::get( $d['org_id'] );
		$fw       = BD_Frameworks::get( $d['framework_id'] );
		$answers  = BD_Diagnostics::answers( $d['id'] );
		$user     = get_userdata( $d['user_id'] );
		?>
		<div class="wrap bd-admin">
			<h1><?php echo esc_html( $org ? $org['name'] : __( '(no organisation name)', 'business-doctor' ) ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=bd-diagnostics' ) ); ?>">&larr; <?php esc_html_e( 'All full assessments', 'business-doctor' ); ?></a></p>
			<div class="bd-columns">
				<div class="bd-panel">
					<table class="form-table" role="presentation">
						<tr><th><?php esc_html_e( 'Owner', 'business-doctor' ); ?></th><td><?php echo $user ? esc_html( $user->display_name . ' (' . $user->user_email . ')' ) : '&ndash;'; ?></td></tr>
						<tr><th><?php esc_html_e( 'Platform', 'business-doctor' ); ?></th><td><?php echo esc_html( BD_Frameworks::short_name( $d['platform'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Status', 'business-doctor' ); ?></th><td><?php echo esc_html( ucfirst( $d['status'] ) ); ?></td></tr>
						<?php if ( 'submitted' === $d['status'] ) : ?>
							<tr><th><?php esc_html_e( 'Overall score', 'business-doctor' ); ?></th><td><strong><?php echo esc_html( $d['overall_score'] ); ?></strong></td></tr>
							<tr><th><?php esc_html_e( 'Maturity band', 'business-doctor' ); ?></th><td><?php echo esc_html( $d['maturity'] ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Confidence', 'business-doctor' ); ?></th><td><?php echo esc_html( $d['confidence'] ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Completion', 'business-doctor' ); ?></th><td><?php echo esc_html( round( $d['completion'] * 100 ) ); ?>%</td></tr>
						<?php endif; ?>
					</table>
				</div>
				<?php if ( 'submitted' === $d['status'] ) : ?>
				<div class="bd-panel">
					<h2><?php esc_html_e( 'Dimension scores', 'business-doctor' ); ?></h2>
					<ul class="bd-bars">
						<?php foreach ( $d['dimension_scores'] as $row ) : ?>
							<li><span><?php echo esc_html( $row['dimension'] ); ?></span><span class="bd-bar"><span style="width:<?php echo esc_attr( max( 0, min( 100, (float) $row['value'] ) ) ); ?>%"></span></span><b><?php echo esc_html( $row['value'] ); ?></b></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>
			</div>
			<div class="bd-panel">
				<h2><?php esc_html_e( 'Answers and evidence', 'business-doctor' ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Question', 'business-doctor' ); ?></th><th><?php esc_html_e( 'Rating', 'business-doctor' ); ?></th><th><?php esc_html_e( 'Evidence', 'business-doctor' ); ?></th><th><?php esc_html_e( 'Note', 'business-doctor' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( ( $fw ? $fw['questions'] : array() ) as $q ) : ?>
						<?php $a = isset( $answers[ $q['code'] ] ) ? $answers[ $q['code'] ] : null; ?>
						<tr>
							<td><?php echo esc_html( $q['text'] ); ?></td>
							<td><?php echo $a && ! $a['notApplicable'] ? esc_html( $a['value'] ) : ( $a && $a['notApplicable'] ? esc_html__( 'N/A', 'business-doctor' ) : '&ndash;' ); ?></td>
							<td><?php echo $a ? esc_html( $a['evidenceClass'] ) : '&ndash;'; ?></td>
							<td><?php echo $a ? esc_html( $a['evidenceNote'] ) : ''; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}
}
