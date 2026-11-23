<?php
/**
 * Plugin Name:       Business Doctor by Samakose
 * Plugin URI:        https://www.samakose.com
 * Description:       Free Business Health Assessments (SME360, AgriFood360, ESO360) with scoring, results, lead management and question-bank versioning, run entirely inside WordPress.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Samakose Accelerator Lab
 * Author URI:        https://www.samakose.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       business-doctor
 * Domain Path:       /languages
 *
 * @package BusinessDoctor
 */

defined( 'ABSPATH' ) || exit;

define( 'BD_VERSION', '1.1.0' );
define( 'BD_DB_VERSION', '2' );
define( 'BD_FILE', __FILE__ );
define( 'BD_DIR', plugin_dir_path( __FILE__ ) );
define( 'BD_URL', plugin_dir_url( __FILE__ ) );

require_once BD_DIR . 'includes/class-bd-scoring.php';
require_once BD_DIR . 'includes/class-bd-diagnostic-scoring.php';
require_once BD_DIR . 'includes/class-bd-settings.php';
require_once BD_DIR . 'includes/class-bd-pdf.php';
require_once BD_DIR . 'includes/class-bd-install.php';
require_once BD_DIR . 'includes/class-bd-frameworks.php';
require_once BD_DIR . 'includes/class-bd-rate-limit.php';
require_once BD_DIR . 'includes/class-bd-results.php';
require_once BD_DIR . 'includes/class-bd-mailer.php';
require_once BD_DIR . 'includes/class-bd-rest.php';
require_once BD_DIR . 'includes/class-bd-shortcodes.php';
require_once BD_DIR . 'includes/class-bd-report.php';
require_once BD_DIR . 'includes/class-bd-privacy.php';
require_once BD_DIR . 'includes/class-bd-accounts.php';
require_once BD_DIR . 'includes/class-bd-organisations.php';
require_once BD_DIR . 'includes/class-bd-diagnostics.php';
require_once BD_DIR . 'includes/class-bd-rest-owner.php';
require_once BD_DIR . 'includes/class-bd-owner-shortcodes.php';

if ( is_admin() ) {
	require_once BD_DIR . 'includes/admin/class-bd-admin.php';
	require_once BD_DIR . 'includes/admin/class-bd-results-table.php';
	require_once BD_DIR . 'includes/admin/class-bd-frameworks-admin.php';
	require_once BD_DIR . 'includes/admin/class-bd-diagnostics-admin.php';
	require_once BD_DIR . 'includes/admin/class-bd-diagnostics-table.php';
}

register_activation_hook( __FILE__, array( 'BD_Install', 'activate' ) );

add_action( 'plugins_loaded', 'bd_boot' );

/**
 * Wires every component to WordPress.
 */
function bd_boot() {
	load_plugin_textdomain( 'business-doctor', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	BD_Install::maybe_upgrade();
	BD_Rest::init();
	BD_Shortcodes::init();
	BD_Report::init();
	BD_Privacy::init();
	BD_Accounts::init();
	BD_Rest_Owner::init();
	BD_Owner_Shortcodes::init();
	if ( is_admin() ) {
		BD_Admin::init();
		BD_Frameworks_Admin::init();
		BD_Diagnostics_Admin::init();
	}
}
