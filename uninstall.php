<?php
/**
 * Runs when the plugin is deleted from the Plugins screen. Data is removed only if the
 * site owner ticked "Delete all results, question banks and settings" in Settings.
 *
 * @package BusinessDoctor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$bd_settings = get_option( 'bd_settings', array() );
if ( empty( $bd_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Evidence files are real media library attachments (uploaded through the owner-facing REST API),
// so they need deleting through WordPress, not just a DROP TABLE, or their physical files would be
// left behind on disk with no database row pointing to them.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
$evidence_ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bd_diagnostic_id'" );
foreach ( $evidence_ids as $attachment_id ) {
	wp_delete_attachment( (int) $attachment_id, true );
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bd_results" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bd_frameworks" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bd_diagnostic_answers" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bd_diagnostics" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bd_organisations" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bd_payments" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bd_entitlements" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bd_webhook_events" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_bd\_%' OR option_name LIKE '\_transient\_timeout\_bd\_%'" );
// phpcs:enable
delete_option( 'bd_settings' );
delete_option( 'bd_db_version' );

$bd_admin = get_role( 'administrator' );
if ( $bd_admin ) {
	$bd_admin->remove_cap( 'manage_business_doctor' );
	$bd_admin->remove_cap( 'manage_business_diagnostics' );
}

// The business_owner role itself is removed, but the WordPress user accounts that held it are left
// alone: those are real people's accounts on the site, not plugin data, the same way a free
// assessment never creates or deletes a WordPress user.
remove_role( 'business_owner' );
