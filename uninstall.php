<?php
/**
 * Media Library Fixer — Uninstaller
 *
 * Runs when the plugin is deleted from the Plugins screen, not on deactivation.
 * Deletes nothing unless the "Delete all plugin data when uninstalled" setting is on.
 * Imported attachments are never deleted.
 *
 * @package Media_Library_Fixer
 */

namespace Media_Library_Fixer;

// Bail out if not invoked by WordPress's uninstall handler.
defined( 'WP_UNINSTALL_PLUGIN' ) || die();

require_once __DIR__ . '/constants.php';

$mlfixer_delete_data = (bool) filter_var(
	get_option( OPTION_DELETE_DATA_ON_UNINSTALL, DEFAULT_DELETE_DATA_ON_UNINSTALL ),
	FILTER_VALIDATE_BOOLEAN
);

if ( $mlfixer_delete_data ) {
	global $wpdb;

	// Options are found by prefix so job state for third-party modules goes too.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off prefix lookup at uninstall; there is no API for it.
	$mlfixer_option_names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( OPTION_PREFIX ) . '%' ) );

	foreach ( $mlfixer_option_names as $mlfixer_option_name ) {
		delete_option( $mlfixer_option_name );
	}

	delete_metadata( 'post', 0, META_IMPORTED, '', true );
}
