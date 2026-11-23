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
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bd_results" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bd_frameworks" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_bd\_%' OR option_name LIKE '\_transient\_timeout\_bd\_%'" );
// phpcs:enable
delete_option( 'bd_settings' );
delete_option( 'bd_db_version' );

$bd_admin = get_role( 'administrator' );
if ( $bd_admin ) {
	$bd_admin->remove_cap( 'manage_business_doctor' );
}
