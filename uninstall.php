<?php
/**
 * Uninstall Plugin
 *
 * This file is automatically run by WordPress when the plugin is deleted.
 * It deletes the plugin's settings from the database if the user has opted-in.
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$wtols_options = get_option( 'wtols_settings' );

if ( isset( $wtols_options['delete_data_on_uninstall'] ) && 1 === (int) $wtols_options['delete_data_on_uninstall'] ) {
	// Delete the main settings option
	delete_option( 'wtols_settings' );

	// Delete any other plugin-related data if needed
	// (e.g. transients, custom tables, etc.)
}
