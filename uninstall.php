<?php
/**
 * Uninstall script for Valerian Google Reviews Sync.
 *
 * Runs when the plugin is deleted from WP Admin → Plugins.
 * Cleans up all plugin options and tokens.
 *
 * NOTE: Review posts (the CPT data) are NOT deleted by default.
 * Imported reviews are content — removing them on uninstall would be
 * destructive and unexpected. If you need to remove review posts,
 * do so manually from WP Admin → Google Reviews before deleting the plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// ── Delete all plugin options ─────────────────────────────────────────────────

$options = [
    // OAuth credentials
    'vgr_client_id',
    'vgr_client_secret',

    // OAuth tokens (encrypted)
    'vgr_access_token',
    'vgr_refresh_token',
    'vgr_token_expires',

    // Encryption salt
    'vgr_plugin_salt',

    // Location settings
    'vgr_account_id',
    'vgr_location_name',

    // Sync settings
    'vgr_min_rating',
    'vgr_last_sync',

    // Schema settings
    'vgr_business_name',
    'vgr_business_type',
    'vgr_business_url',
    'vgr_schema_scope',
    'vgr_schema_page_ids',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

// ── Clear scheduled cron ──────────────────────────────────────────────────────

wp_clear_scheduled_hook( 'vgr_daily_sync' );

// ── Transients ────────────────────────────────────────────────────────────────

delete_transient( 'vgr_oauth_state' );
delete_transient( 'vgr_oauth_error' );
delete_transient( 'vgr_oauth_success' );
