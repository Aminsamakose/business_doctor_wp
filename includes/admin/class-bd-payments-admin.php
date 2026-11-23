<?php
/**
 * Admin screen for staff to see payments for the full assessment, and to grant or revoke access by
 * hand (a manual/offline payment, a refund, a goodwill grant).
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

class BD_Payments_Admin {

	const CAP = 'manage_business_diagnostics';

	/**
	 * Hooks the admin menu and the grant/revoke form handlers.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_bd_grant_entitlement', array( __CLASS__, 'handle_grant' ) );
		add_action( 'admin_post_bd_revoke_entitlement', array( __CLASS__, 'handle_revoke' ) );
	}

	/**
	 * Whether the current user may manage payments.
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
			__( 'Payments', 'business-doctor' ),
			__( 'Payments', 'business-doctor' ),
			self::CAP,
			'bd-payments',
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * List screen, with a manual-grant form for an organisation that paid offline.
	 */
	public static function page() {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'business-doctor' ) );
		}
		$table = new BD_Payments_Table();
		$table->prepare_items();
		?>
		<div class="wrap bd-admin">
			<h1><?php esc_html_e( 'Payments', 'business-doctor' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Paystack checkouts for the full assessment. A successful payment grants the organisation standing access to that platform\'s full assessment; see Settings to turn payments on and set prices.', 'business-doctor' ); ?></p>
			<?php if ( isset( $_GET['bd_msg'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Done.', 'business-doctor' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['bd_err'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['bd_err'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
			<?php endif; ?>
			<form method="get">
				<input type="hidden" name="page" value="bd-payments">
				<?php $table->display(); ?>
			</form>

			<div class="bd-panel">
				<h2><?php esc_html_e( 'Grant access manually', 'business-doctor' ); ?></h2>
				<p class="description"><?php esc_html_e( 'For an organisation that paid offline (bank transfer, mobile money outside Paystack) or that should have free access.', 'business-doctor' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'bd_grant_entitlement' ); ?>
					<input type="hidden" name="action" value="bd_grant_entitlement">
					<table class="form-table" role="presentation">
						<tr><th><label for="bd-grant-org"><?php esc_html_e( 'Organisation id', 'business-doctor' ); ?></label></th><td><input type="number" id="bd-grant-org" name="org_id" min="1" required> <p class="description"><?php esc_html_e( 'Found on the owner\'s organisation profile, or via Full Assessments.', 'business-doctor' ); ?></p></td></tr>
						<tr><th><label for="bd-grant-platform"><?php esc_html_e( 'Platform', 'business-doctor' ); ?></label></th><td>
							<select id="bd-grant-platform" name="platform">
								<?php foreach ( BD_Frameworks::platforms() as $code => $p ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $p['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td></tr>
					</table>
					<?php submit_button( __( 'Grant access', 'business-doctor' ) ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Handles the manual-grant form.
	 */
	public static function handle_grant() {
		if ( ! self::can() || ! check_admin_referer( 'bd_grant_entitlement' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'business-doctor' ) );
		}
		$org_id   = isset( $_POST['org_id'] ) ? absint( $_POST['org_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$platform = isset( $_POST['platform'] ) ? bd_sanitize_code( wp_unslash( $_POST['platform'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$org      = $org_id ? BD_Organisations::get( $org_id ) : null;
		$redirect = admin_url( 'admin.php?page=bd-payments' );
		if ( ! $org || ! array_key_exists( $platform, BD_Frameworks::platforms() ) ) {
			wp_safe_redirect( add_query_arg( 'bd_err', rawurlencode( __( 'Organisation or platform not recognised.', 'business-doctor' ) ), $redirect ) );
			exit;
		}
		BD_Payments::grant_entitlement(
			array(
				'org_id'      => $org['id'],
				'user_id'     => $org['user_id'],
				'platform'    => $platform,
				'granted_via' => 'admin_grant',
				'granted_by'  => get_current_user_id(),
			)
		);
		wp_safe_redirect( add_query_arg( 'bd_msg', 'granted', $redirect ) );
		exit;
	}

	/**
	 * Handles revoking an entitlement (not currently linked from the UI; available for a future
	 * refund workflow, kept here alongside the grant handler it mirrors).
	 */
	public static function handle_revoke() {
		if ( ! self::can() || ! check_admin_referer( 'bd_revoke_entitlement' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'business-doctor' ) );
		}
		$org_id   = isset( $_POST['org_id'] ) ? absint( $_POST['org_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$platform = isset( $_POST['platform'] ) ? bd_sanitize_code( wp_unslash( $_POST['platform'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$redirect = admin_url( 'admin.php?page=bd-payments' );
		if ( $org_id && array_key_exists( $platform, BD_Frameworks::platforms() ) ) {
			BD_Payments::revoke_entitlement( $org_id, $platform );
		}
		wp_safe_redirect( add_query_arg( 'bd_msg', 'revoked', $redirect ) );
		exit;
	}
}
