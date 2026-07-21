<?php
/**
<?php
/**
 * Plugin Name:       Google Reviews Sync
 * Plugin URI:        https://github.com/getvalerian/google-reviews-sync
 * Description:       Pulls Google Business Profile reviews directly into WordPress as a Custom Post Type with ACF fields for full page builder control—no third-party widget styling constraints, no SaaS subscription for display-only use cases. Includes OAuth 2.0, full review pagination, AggregateRating rich snippet schema, and bundled shortcodes for common layouts.
 * Version:           2.6.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Valerian
 * Author URI:        https://getvalerian.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       google-reviews-sync
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────────────────────────────────────
// PLUGIN UPDATE CHECKER (GitHub releases)
// Updates are delivered via GitHub releases. When a new release is published
// at github.com/getvalerian/google-reviews-sync, WordPress will show the
// standard "Update Available" notification in WP Admin → Plugins.
// ─────────────────────────────────────────────────────────────────────────────

require_once plugin_dir_path( __FILE__ ) . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$vgr_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/getvalerian/google-reviews-sync/',
    __FILE__,
    'google-reviews-sync'
);
// Deliver updates as release asset .zip files (attached to the GitHub release)
$vgr_update_checker->getVcsApi()->enableReleaseAssets();

// ─────────────────────────────────────────────────────────────────────────────
// CONSTANTS
// ─────────────────────────────────────────────────────────────────────────────

define( 'VGR_VERSION',           '2.6.0' );
define( 'VGR_PLUGIN_FILE',       __FILE__ );

// Google OAuth endpoints
define( 'VGR_OAUTH_AUTH_URL',    'https://accounts.google.com/o/oauth2/v2/auth' );
define( 'VGR_OAUTH_TOKEN_URL',   'https://oauth2.googleapis.com/token' );

// GBP API base URLs — using correct per-service domains
define( 'VGR_GBP_ACCOUNTS_URL',  'https://mybusinessaccountmanagement.googleapis.com/v1/accounts' );
define( 'VGR_GBP_LOCATIONS_BASE','https://mybusinessbusinessinformation.googleapis.com/v1/' );

// Reviews API: v4 on mybusiness.googleapis.com is the correct current endpoint.
// Full URL is constructed as: VGR_GBP_REVIEWS_BASE + {location_name} + /reviews
// e.g. https://mybusiness.googleapis.com/v4/accounts/123/locations/456/reviews
define( 'VGR_GBP_REVIEWS_BASE',  'https://mybusiness.googleapis.com/v4/' );

define( 'VGR_OAUTH_SCOPE',       'https://www.googleapis.com/auth/business.manage' );


// ─────────────────────────────────────────────────────────────────────────────
// TOKEN ENCRYPTION
// ─────────────────────────────────────────────────────────────────────────────

function vgr_get_encryption_key() {
    $plugin_salt = get_option( 'vgr_plugin_salt' );
    if ( ! $plugin_salt ) {
        $plugin_salt = bin2hex( random_bytes( 32 ) );
        update_option( 'vgr_plugin_salt', $plugin_salt, false );
    }
    return hash( 'sha256', wp_salt( 'auth' ) . $plugin_salt, true );
}

function vgr_encrypt_token( string $plaintext ): string {
    if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
        return base64_encode( 'plain:' . $plaintext );
    }
    $key   = vgr_get_encryption_key();
    $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
    $cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
    sodium_memzero( $plaintext );
    return base64_encode( $nonce . $cipher );
}

function vgr_decrypt_token( string $encoded ): string {
    if ( ! $encoded ) return '';
    $decoded = base64_decode( $encoded, true );
    if ( $decoded === false ) return '';
    if ( str_starts_with( $decoded, 'plain:' ) ) return substr( $decoded, 6 );
    if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) return $decoded;
    $nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    if ( strlen( $decoded ) <= $nonce_len ) return '';
    $key    = vgr_get_encryption_key();
    $nonce  = substr( $decoded, 0, $nonce_len );
    $cipher = substr( $decoded, $nonce_len );
    $plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
    return $plain !== false ? $plain : '';
}


// ─────────────────────────────────────────────────────────────────────────────
// ACTIVATION / DEACTIVATION
// ─────────────────────────────────────────────────────────────────────────────

register_activation_hook( VGR_PLUGIN_FILE, 'vgr_activate' );
function vgr_activate() {
    vgr_register_cpt();
    flush_rewrite_rules();
    if ( ! wp_next_scheduled( 'vgr_daily_sync' ) ) {
        wp_schedule_event( time(), 'daily', 'vgr_daily_sync' );
    }
}

register_deactivation_hook( VGR_PLUGIN_FILE, 'vgr_deactivate' );
function vgr_deactivate() {
    wp_clear_scheduled_hook( 'vgr_daily_sync' );
    flush_rewrite_rules();
}


// ─────────────────────────────────────────────────────────────────────────────
// CPT
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'init', 'vgr_register_cpt' );
function vgr_register_cpt() {
    register_post_type( 'google_review', [
        'labels'        => [
            'name'               => 'Google Reviews',
            'singular_name'      => 'Google Review',
            'not_found'          => 'No reviews found — run a sync.',
            'not_found_in_trash' => 'No reviews in trash.',
        ],
        'public'        => false,
        'show_ui'       => true,
        'show_in_menu'  => true,
        'menu_icon'     => 'dashicons-star-filled',
        'menu_position' => 25,
        'supports'      => [ 'title' ],
        'show_in_rest'  => false,
        'capabilities'  => [ 'create_posts' => 'do_not_allow' ],
        'map_meta_cap'  => true,
    ] );
}


// ─────────────────────────────────────────────────────────────────────────────
// ACF FIELDS
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'acf/init', 'vgr_register_acf_fields' );
function vgr_register_acf_fields() {
    if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

    acf_add_local_field_group( [
        'key'    => 'group_vgr_review',
        'title'  => 'Review Details',
        'fields' => [
            [ 'key' => 'field_vgr_reviewer_name',   'label' => 'Reviewer Name',      'name' => 'reviewer_name',    'type' => 'text',      'readonly' => 1 ],
            [ 'key' => 'field_vgr_reviewer_photo',  'label' => 'Reviewer Photo URL', 'name' => 'reviewer_photo',   'type' => 'url',       'readonly' => 1 ],
            [ 'key' => 'field_vgr_rating',          'label' => 'Rating',             'name' => 'rating',           'type' => 'number',    'readonly' => 1, 'min' => 1, 'max' => 5 ],
            [ 'key' => 'field_vgr_review_text',     'label' => 'Review Text',        'name' => 'review_text',      'type' => 'textarea',  'readonly' => 1, 'rows' => 5 ],
            [
                'key'            => 'field_vgr_review_date',
                'label'          => 'Review Date',
                'name'           => 'review_date',
                'type'           => 'date_picker',
                'display_format' => 'F j, Y',
                'return_format'  => 'F j, Y',
                'readonly'       => 1,
            ],
            [ 'key' => 'field_vgr_featured',        'label' => 'Featured',           'name' => 'featured_review',  'type' => 'true_false', 'ui' => 1, 'instructions' => 'Pin this review to appear first.' ],
            [ 'key' => 'field_vgr_google_review_id','label' => 'Google Review ID',   'name' => 'google_review_id', 'type' => 'text',      'readonly' => 1, 'wrapper' => [ 'class' => 'vgr-meta-field' ] ],
        ],
        'location'       => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'google_review' ] ] ],
        'hide_on_screen' => [ 'the_content','excerpt','discussion','comments','revisions','slug','author','format','page_attributes','featured_image','tags','send-trackbacks' ],
    ] );
}

add_action( 'admin_head', function() {
    echo '<style>.vgr-meta-field{opacity:.4;pointer-events:none}</style>';
} );


// ─────────────────────────────────────────────────────────────────────────────
// ADMIN COLUMNS
// ─────────────────────────────────────────────────────────────────────────────

add_filter( 'manage_google_review_posts_columns', function( $cols ) {
    return [ 'cb' => $cols['cb'], 'title' => 'Reviewer', 'vgr_rating' => 'Rating', 'vgr_date' => 'Date', 'vgr_featured' => '⭐', 'vgr_excerpt' => 'Review' ];
} );

add_action( 'manage_google_review_posts_custom_column', function( $col, $post_id ) {
    switch ( $col ) {
        case 'vgr_rating':
            $r = (int) get_field( 'rating', $post_id );
            echo '<span style="color:#f5a623">' . str_repeat( '★', $r ) . '</span>' . str_repeat( '☆', 5 - $r );
            break;
        case 'vgr_date':    echo esc_html( get_field( 'review_date', $post_id ) ?: '—' ); break;
        case 'vgr_featured': echo get_field( 'featured_review', $post_id ) ? '⭐' : ''; break;
        case 'vgr_excerpt': echo esc_html( wp_trim_words( get_field( 'review_text', $post_id ), 14, '…' ) ); break;
    }
}, 10, 2 );


// ─────────────────────────────────────────────────────────────────────────────
// OAUTH
// ─────────────────────────────────────────────────────────────────────────────

function vgr_redirect_uri() {
    return admin_url( 'edit.php?post_type=google_review&page=vgr-settings' );
}

function vgr_get_oauth_url() {
    $state = wp_generate_password( 32, false );
    set_transient( 'vgr_oauth_state', $state, 10 * MINUTE_IN_SECONDS );
    return VGR_OAUTH_AUTH_URL . '?' . http_build_query( [
        'client_id'     => get_option( 'vgr_client_id' ),
        'redirect_uri'  => vgr_redirect_uri(),
        'response_type' => 'code',
        'scope'         => VGR_OAUTH_SCOPE,
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'state'         => $state,
    ] );
}

add_action( 'admin_init', 'vgr_handle_oauth_callback' );
function vgr_handle_oauth_callback() {
    if ( ! isset( $_GET['code'], $_GET['page'] ) || $_GET['page'] !== 'vgr-settings' ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;

    $state = sanitize_text_field( $_GET['state'] ?? '' );
    if ( $state !== get_transient( 'vgr_oauth_state' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>VGR:</strong> OAuth state mismatch. Try connecting again.</p></div>';
        } );
        return;
    }
    delete_transient( 'vgr_oauth_state' );

    $response = wp_remote_post( VGR_OAUTH_TOKEN_URL, [
        'body' => [
            'code'          => sanitize_text_field( $_GET['code'] ),
            'client_id'     => get_option( 'vgr_client_id' ),
            'client_secret' => get_option( 'vgr_client_secret' ),
            'redirect_uri'  => vgr_redirect_uri(),
            'grant_type'    => 'authorization_code',
        ],
    ] );

    if ( is_wp_error( $response ) ) {
        set_transient( 'vgr_oauth_error', $response->get_error_message(), MINUTE_IN_SECONDS );
        wp_redirect( admin_url( 'edit.php?post_type=google_review&page=vgr-settings' ) ); exit;
    }

    $tokens = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! empty( $tokens['error'] ) ) {
        set_transient( 'vgr_oauth_error', $tokens['error_description'] ?? $tokens['error'], MINUTE_IN_SECONDS );
        wp_redirect( admin_url( 'edit.php?post_type=google_review&page=vgr-settings' ) ); exit;
    }

    update_option( 'vgr_access_token',  vgr_encrypt_token( $tokens['access_token'] ), false );
    update_option( 'vgr_token_expires', time() + (int) $tokens['expires_in'], false );
    if ( ! empty( $tokens['refresh_token'] ) ) {
        update_option( 'vgr_refresh_token', vgr_encrypt_token( $tokens['refresh_token'] ), false );
    }
    delete_option( 'vgr_account_id' );
    delete_option( 'vgr_location_name' );
    set_transient( 'vgr_oauth_success', 1, MINUTE_IN_SECONDS );
    wp_redirect( admin_url( 'edit.php?post_type=google_review&page=vgr-settings' ) ); exit;
}

function vgr_get_valid_token() {
    $expires = (int) get_option( 'vgr_token_expires', 0 );
    if ( time() < ( $expires - 60 ) ) {
        return vgr_decrypt_token( get_option( 'vgr_access_token' ) );
    }
    return vgr_refresh_access_token();
}

function vgr_refresh_access_token() {
    $enc = get_option( 'vgr_refresh_token' );
    if ( ! $enc ) return new WP_Error( 'no_refresh_token', 'No refresh token stored. Reconnect Google account.' );
    $refresh_token = vgr_decrypt_token( $enc );
    if ( ! $refresh_token ) return new WP_Error( 'decrypt_failed', 'Could not decrypt stored token. Reconnect Google account.' );

    $response = wp_remote_post( VGR_OAUTH_TOKEN_URL, [
        'body' => [
            'client_id'     => get_option( 'vgr_client_id' ),
            'client_secret' => get_option( 'vgr_client_secret' ),
            'refresh_token' => $refresh_token,
            'grant_type'    => 'refresh_token',
        ],
    ] );

    if ( is_wp_error( $response ) ) return $response;
    $tokens = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! empty( $tokens['error'] ) ) return new WP_Error( 'token_refresh_failed', $tokens['error_description'] ?? $tokens['error'] );

    update_option( 'vgr_access_token',  vgr_encrypt_token( $tokens['access_token'] ), false );
    update_option( 'vgr_token_expires', time() + (int) $tokens['expires_in'], false );
    return $tokens['access_token'];
}

function vgr_is_connected() {
    $stored = get_option( 'vgr_refresh_token' );
    if ( ! $stored ) return false;
    return ! empty( vgr_decrypt_token( $stored ) );
}


// ─────────────────────────────────────────────────────────────────────────────
// GBP API HELPERS
// ─────────────────────────────────────────────────────────────────────────────

function vgr_gbp_request( $url ) {
    $token = vgr_get_valid_token();
    if ( is_wp_error( $token ) ) return $token;

    $response = wp_remote_get( $url, [
        'timeout' => 20,
        'headers' => [ 'Authorization' => 'Bearer ' . $token ],
    ] );

    if ( is_wp_error( $response ) ) return $response;

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        $msg = $body['error']['message'] ?? "API returned HTTP {$code}";
        // Include the URL in the error for easier debugging
        return new WP_Error( 'gbp_api_error', $msg . " [URL: {$url}]" );
    }

    return $body;
}

function vgr_gbp_get_accounts() {
    $data = vgr_gbp_request( VGR_GBP_ACCOUNTS_URL );
    if ( is_wp_error( $data ) ) return $data;
    return $data['accounts'] ?? [];
}

function vgr_gbp_get_locations( $account_name ) {
    // Trim any stray slashes to prevent double-slash in URL
    $account_name = trim( $account_name, '/' );
    $url  = VGR_GBP_LOCATIONS_BASE . $account_name . '/locations?' . http_build_query( [
        'readMask' => 'name,title,storefrontAddress',
    ] );
    $data = vgr_gbp_request( $url );
    if ( is_wp_error( $data ) ) return $data;
    return $data['locations'] ?? [];
}

/**
 * Build the reviews URL from a stored location name.
 *
 * The location_name stored in settings is the full GBP resource path,
 * e.g. "accounts/123456789/locations/987654321"
 *
 * The reviews endpoint expects:
 * GET https://mybusiness.googleapis.com/v4/{location_name}/reviews
 *
 * We trim slashes and ensure no double-slash between base and path.
 */
function vgr_build_reviews_url( string $location_name, array $params = [] ): string {
    $location_name = trim( $location_name, '/' );
    $base          = rtrim( VGR_GBP_REVIEWS_BASE, '/' );
    $url           = $base . '/' . $location_name . '/reviews';
    if ( ! empty( $params ) ) {
        $url .= '?' . http_build_query( $params );
    }
    return $url;
}

function vgr_gbp_get_all_reviews( $location_name ) {
    $all_reviews = [];
    $page_token  = null;
    $page        = 0;
    $max_pages   = 20; // ~1000 reviews at 50/page

    do {
        $params = [ 'pageSize' => 50 ];
        if ( $page_token ) $params['pageToken'] = $page_token;

        $url  = vgr_build_reviews_url( $location_name, $params );
        $data = vgr_gbp_request( $url );

        if ( is_wp_error( $data ) ) return $data;

        $reviews     = $data['reviews'] ?? [];
        $all_reviews = array_merge( $all_reviews, $reviews );
        $page_token  = $data['nextPageToken'] ?? null;
        $page++;

    } while ( $page_token && $page < $max_pages );

    return $all_reviews;
}


// ─────────────────────────────────────────────────────────────────────────────
// SYNC
// ─────────────────────────────────────────────────────────────────────────────

function vgr_sync_reviews() {
    $location_name = get_option( 'vgr_location_name' );
    $min_stars     = (int) get_option( 'vgr_min_rating', 4 );

    if ( ! $location_name ) return new WP_Error( 'no_location', 'No location configured. Complete setup in Settings.' );
    if ( ! vgr_is_connected() ) return new WP_Error( 'not_connected', 'Google account not connected.' );

    $reviews = vgr_gbp_get_all_reviews( $location_name );
    if ( is_wp_error( $reviews ) ) return $reviews;
    if ( empty( $reviews ) ) return new WP_Error( 'no_reviews', 'No reviews returned. Check the selected location.' );

    $star_map = [ 'ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5 ];
    $new = 0; $skipped = 0;

    foreach ( $reviews as $review ) {
        $rating    = $star_map[ $review['starRating'] ?? '' ] ?? 0;
        if ( $rating < $min_stars ) { $skipped++; continue; }

        $review_id = sanitize_text_field( $review['reviewId'] ?? '' );
        if ( ! $review_id ) continue;

        $existing = get_posts( [
            'post_type' => 'google_review', 'post_status' => 'publish', 'posts_per_page' => 1,
            'meta_query' => [ [ 'key' => 'google_review_id', 'value' => $review_id ] ],
        ] );
        if ( ! empty( $existing ) ) { $skipped++; continue; }

        $author_name = sanitize_text_field( $review['reviewer']['displayName'] ?? 'Anonymous' );
        $timestamp   = strtotime( $review['createTime'] ?? '' );
        $review_date = $timestamp ? date( 'Ymd', $timestamp ) : '';

        $post_id = wp_insert_post( [
            'post_type'   => 'google_review',
            'post_title'  => $author_name,
            'post_status' => 'publish',
            'post_date'   => $timestamp ? date( 'Y-m-d H:i:s', $timestamp ) : current_time( 'mysql' ),
        ] );
        if ( is_wp_error( $post_id ) ) continue;

        update_field( 'reviewer_name',    $author_name,                                                                 $post_id );
        update_field( 'reviewer_photo',   esc_url_raw( $review['reviewer']['profilePhotoUrl'] ?? '' ),                  $post_id );
        update_field( 'rating',           $rating,                                                                       $post_id );
        update_field( 'review_text',      sanitize_textarea_field( $review['comment'] ?? '' ),                          $post_id );
        update_field( 'review_date',      $review_date,                                                                  $post_id );
        update_field( 'google_review_id', $review_id,                                                                   $post_id );
        update_field( 'featured_review',  0,                                                                             $post_id );

        update_post_meta( $post_id, 'google_review_id', $review_id );
        update_post_meta( $post_id, 'vgr_rating',       $rating );

        $new++;
    }

    update_option( 'vgr_last_sync', current_time( 'mysql' ) );
    return [ 'new' => $new, 'skipped' => $skipped ];
}

add_action( 'vgr_daily_sync', 'vgr_sync_reviews' );


// ─────────────────────────────────────────────────────────────────────────────
// SETTINGS
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'admin_menu', function() {
    add_submenu_page( 'edit.php?post_type=google_review', 'Sync Settings', 'Settings', 'manage_options', 'vgr-settings', 'vgr_settings_page' );
} );

add_action( 'admin_init', 'vgr_register_settings' );
function vgr_register_settings() {
    $t = [ 'sanitize_callback' => 'sanitize_text_field' ];
    register_setting( 'vgr_oauth_group',  'vgr_client_id',      $t );
    register_setting( 'vgr_oauth_group',  'vgr_client_secret',  $t );
    register_setting( 'vgr_loc_group',    'vgr_account_id',     $t );
    register_setting( 'vgr_loc_group',    'vgr_location_name',  $t );
    register_setting( 'vgr_sync_group',   'vgr_min_rating',     [ 'sanitize_callback' => 'absint', 'default' => 4 ] );
    register_setting( 'vgr_schema_group', 'vgr_business_name',  $t );
    register_setting( 'vgr_schema_group', 'vgr_business_type',  $t );
    register_setting( 'vgr_schema_group', 'vgr_business_url',   [ 'sanitize_callback' => 'esc_url_raw' ] );
    register_setting( 'vgr_schema_group', 'vgr_schema_scope',   $t );
    register_setting( 'vgr_schema_group', 'vgr_schema_page_ids', [
        'sanitize_callback' => function( $val ) {
            $ids = array_filter( array_map( 'absint', explode( ',', $val ) ) );
            return implode( ',', $ids );
        },
    ] );
}

function vgr_settings_page() {
    $sync_message  = '';
    $oauth_error   = get_transient( 'vgr_oauth_error' );
    $oauth_success = get_transient( 'vgr_oauth_success' );
    delete_transient( 'vgr_oauth_error' );
    delete_transient( 'vgr_oauth_success' );

    if ( isset( $_POST['vgr_save_location'] ) && check_admin_referer( 'vgr_location_nonce' ) ) {
        $account_id    = sanitize_text_field( $_POST['vgr_account_id'] ?? '' );
        $location_name = trim( sanitize_text_field( $_POST['vgr_location_name'] ?? '' ), '/' );

        // The GBP Business Information API returns location names as relative paths
        // (e.g. "locations/1234") but the Reviews API requires the full resource path
        // (e.g. "accounts/5678/locations/1234"). Prepend the account if missing.
        if ( $location_name && ! str_starts_with( $location_name, 'accounts/' ) ) {
            $location_name = trim( $account_id, '/' ) . '/' . $location_name;
        }

        update_option( 'vgr_account_id',    $account_id );
        update_option( 'vgr_location_name', $location_name );
        $sync_message = '<div class="notice notice-success inline"><p>Location saved. Path: <code>' . esc_html( $location_name ) . '</code></p></div>';
    }

    if ( isset( $_POST['vgr_manual_sync'] ) && check_admin_referer( 'vgr_sync_nonce' ) ) {
        $result = vgr_sync_reviews();
        if ( is_wp_error( $result ) ) {
            $sync_message = '<div class="notice notice-error inline"><p><strong>Sync failed:</strong> ' . esc_html( $result->get_error_message() ) . '</p></div>';
        } else {
            $sync_message = '<div class="notice notice-success inline"><p>✅ Sync complete — <strong>' . $result['new'] . ' new</strong> review(s) imported, ' . $result['skipped'] . ' skipped.</p></div>';
        }
    }

    if ( isset( $_POST['vgr_disconnect'] ) && check_admin_referer( 'vgr_disconnect_nonce' ) ) {
        foreach ( [ 'vgr_access_token','vgr_refresh_token','vgr_token_expires','vgr_account_id','vgr_location_name' ] as $opt ) delete_option( $opt );
        $sync_message = '<div class="notice notice-warning inline"><p>Google account disconnected.</p></div>';
    }

    $is_connected  = vgr_is_connected();
    $location_name = get_option( 'vgr_location_name' );

    // Self-heal: if a location was saved before this fix (missing the accounts/ prefix),
    // correct it now using the stored account ID.
    if ( $location_name && ! str_starts_with( $location_name, 'accounts/' ) ) {
        $account_id    = get_option( 'vgr_account_id' );
        if ( $account_id ) {
            $location_name = trim( $account_id, '/' ) . '/' . trim( $location_name, '/' );
            update_option( 'vgr_location_name', $location_name );
        }
    }
    $total_reviews = wp_count_posts( 'google_review' )->publish ?? 0;
    $last_sync     = get_option( 'vgr_last_sync' );
    $next_sync     = wp_next_scheduled( 'vgr_daily_sync' );
    $agg           = vgr_get_aggregate_data();

    $accounts  = [];
    $locations = [];
    if ( $is_connected && ! $location_name ) {
        $accounts = vgr_gbp_get_accounts();
        if ( is_wp_error( $accounts ) ) { $sync_message = '<div class="notice notice-error inline"><p><strong>Could not fetch accounts:</strong> ' . esc_html( $accounts->get_error_message() ) . '</p></div>'; $accounts = []; }
        $selected_account = get_option( 'vgr_account_id' );
        if ( $selected_account ) {
            $locations = vgr_gbp_get_locations( $selected_account );
            if ( is_wp_error( $locations ) ) $locations = [];
        }
    }

    ?>
    <div class="wrap">
        <h1>Google Reviews Sync</h1>

        <?php if ( $oauth_error )   echo '<div class="notice notice-error"><p><strong>OAuth error:</strong> ' . esc_html( $oauth_error ) . '</p></div>'; ?>
        <?php if ( $oauth_success ) echo '<div class="notice notice-success"><p>✅ Connected. Select your location below.</p></div>'; ?>

        <div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0 24px;">
        <?php
        $stats = [
            [ 'value' => $is_connected ? '✅ Connected' : '❌ Not connected', 'label' => 'Google account' ],
            [ 'value' => $location_name ? basename( $location_name ) : '—', 'label' => 'Location ID' ],
            [ 'value' => number_format( $total_reviews ), 'label' => 'Reviews stored' ],
            [ 'value' => $agg ? $agg['average'] . ' ★' : '—', 'label' => 'Avg rating' ],
            [ 'value' => $last_sync ? human_time_diff( strtotime( $last_sync ) ) . ' ago' : 'Never', 'label' => 'Last sync' ],
            [ 'value' => $next_sync ? 'in ' . human_time_diff( $next_sync ) : '—', 'label' => 'Next auto-sync' ],
        ];
        foreach ( $stats as $s ) echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 18px;min-width:120px;"><div style="font-size:18px;font-weight:700;line-height:1.2;">' . esc_html( $s['value'] ) . '</div><div style="color:#777;font-size:12px;margin-top:2px;">' . esc_html( $s['label'] ) . '</div></div>';
        ?>
        </div>

        <h2>Step 1 — OAuth Credentials</h2>
        <p>Create credentials in <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console ↗</a> (Web application type). Add this as an authorized redirect URI:</p>
        <code style="display:block;background:#f6f7f7;padding:8px 12px;margin:8px 0;border-radius:4px;"><?php echo esc_html( vgr_redirect_uri() ); ?></code>
        <form method="post" action="options.php">
            <?php settings_fields( 'vgr_oauth_group' ); ?>
            <table class="form-table">
                <tr><th><label for="vgr_client_id">Client ID</label></th><td><input type="text" id="vgr_client_id" name="vgr_client_id" value="<?php echo esc_attr( get_option( 'vgr_client_id' ) ); ?>" class="regular-text" /></td></tr>
                <tr><th><label for="vgr_client_secret">Client Secret</label></th><td><input type="password" id="vgr_client_secret" name="vgr_client_secret" value="<?php echo esc_attr( get_option( 'vgr_client_secret' ) ); ?>" class="regular-text" autocomplete="off" /></td></tr>
            </table>
            <?php submit_button( 'Save Credentials', 'secondary' ); ?>
        </form>

        <hr>
        <h2>Step 2 — Connect Google Account</h2>
        <?php if ( $is_connected ) : ?>
            <p>✅ Google account connected.</p>
            <form method="post" style="display:inline;">
                <?php wp_nonce_field( 'vgr_disconnect_nonce' ); ?>
                <input type="hidden" name="vgr_disconnect" value="1" />
                <button type="submit" class="button" onclick="return confirm('Disconnect?')">Disconnect</button>
            </form>
        <?php elseif ( get_option( 'vgr_client_id' ) && get_option( 'vgr_client_secret' ) ) : ?>
            <a href="<?php echo esc_url( vgr_get_oauth_url() ); ?>" class="button button-primary">Connect Google Account →</a>
        <?php else : ?>
            <p style="color:#888;">Save your credentials above first.</p>
        <?php endif; ?>

        <hr>
        <h2>Step 3 — Select Business Location</h2>

        <?php if ( $location_name ) : ?>
            <p>✅ Location: <code><?php echo esc_html( $location_name ); ?></code></p>
            <p><a href="#" onclick="document.getElementById('vgr-change-loc').style.display='block';this.style.display='none';return false;">Change location</a></p>
            <div id="vgr-change-loc" style="display:none;">
        <?php endif; ?>

        <?php if ( $is_connected && ! $location_name ) : ?>
            <?php if ( empty( $accounts ) ) : ?>
                <p style="color:#c00;">No accounts found. Ensure your Google account has access to a Business Profile and the required APIs are approved and enabled.</p>
            <?php else : ?>
                <form method="post">
                    <?php wp_nonce_field( 'vgr_location_nonce' ); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="vgr_account_id">Account</label></th>
                            <td>
                                <select name="vgr_account_id" id="vgr_account_id" onchange="this.form.submit()">
                                    <option value="">— Select account —</option>
                                    <?php foreach ( $accounts as $acct ) : ?>
                                        <option value="<?php echo esc_attr( $acct['name'] ); ?>" <?php selected( get_option('vgr_account_id'), $acct['name'] ); ?>><?php echo esc_html( $acct['accountName'] ?? $acct['name'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php if ( ! empty( $locations ) ) : ?>
                        <tr>
                            <th><label for="vgr_location_name">Location</label></th>
                            <td>
                                <select name="vgr_location_name" id="vgr_location_name">
                                    <option value="">— Select location —</option>
                                    <?php foreach ( $locations as $loc ) : ?>
                                        <option value="<?php echo esc_attr( trim( $loc['name'], '/' ) ); ?>"><?php echo esc_html( $loc['title'] ?? $loc['name'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                    <?php if ( ! empty( $locations ) ) : ?>
                        <input type="hidden" name="vgr_save_location" value="1" />
                        <?php submit_button( 'Save Location', 'primary' ); ?>
                    <?php else : ?>
                        <input type="submit" name="vgr_save_location" value="Load Locations →" class="button button-secondary" />
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        <?php elseif ( ! $is_connected ) : ?>
            <p style="color:#888;">Connect your Google account first.</p>
        <?php endif; ?>

        <?php if ( $location_name ) : ?>
            </div>
        <?php endif; ?>

        <hr>
        <h2>Step 4 — Sync Reviews</h2>
        <?php echo $sync_message; ?>

        <?php if ( $location_name ) : ?>
            <!-- Show the exact URL that will be called, for debugging -->
            <p style="font-size:12px;color:#888;margin-bottom:8px;">
                Reviews endpoint: <code><?php echo esc_html( vgr_build_reviews_url( $location_name ) ); ?></code>
            </p>
        <?php endif; ?>

        <form method="post" action="options.php" style="margin-bottom:12px;">
            <?php settings_fields( 'vgr_sync_group' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="vgr_min_rating">Min rating to import</label></th>
                    <td>
                        <select name="vgr_min_rating" id="vgr_min_rating">
                            <?php for ( $i = 1; $i <= 5; $i++ ) echo '<option value="' . $i . '"' . selected( get_option( 'vgr_min_rating', 4 ), $i, false ) . '>' . $i . '+ stars</option>'; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Save', 'secondary small' ); ?>
        </form>

        <?php if ( $location_name ) : ?>
            <form method="post">
                <?php wp_nonce_field( 'vgr_sync_nonce' ); ?>
                <input type="hidden" name="vgr_manual_sync" value="1" />
                <?php submit_button( 'Sync Reviews Now', 'primary' ); ?>
            </form>
        <?php else : ?>
            <p style="color:#888;">Configure a location above to enable sync.</p>
        <?php endif; ?>

        <hr>
        <h2>Step 5 — Rich Snippet Schema</h2>
        <p>Outputs <code>LocalBusiness</code> + <code>AggregateRating</code> JSON-LD. Set scope to Sitewide for local business sites.</p>
        <form method="post" action="options.php">
            <?php settings_fields( 'vgr_schema_group' ); ?>
            <table class="form-table">
                <tr><th><label for="vgr_business_name">Business Name</label></th><td><input type="text" id="vgr_business_name" name="vgr_business_name" value="<?php echo esc_attr( get_option( 'vgr_business_name', get_bloginfo('name') ) ); ?>" class="regular-text" /></td></tr>
                <tr>
                    <th><label for="vgr_business_type">Business Type</label></th>
                    <td>
                        <select id="vgr_business_type" name="vgr_business_type">
                            <?php foreach ( [ 'LocalBusiness','Restaurant','CafeOrCoffeeShop','Bakery','Store','HealthAndBeautyBusiness','MedicalBusiness','ProfessionalService','HomeAndConstructionBusiness' ] as $t ) printf( '<option value="%s"%s>%s</option>', $t, selected( get_option('vgr_business_type','LocalBusiness'), $t, false ), $t ); ?>
                        </select>
                    </td>
                </tr>
                <tr><th><label for="vgr_business_url">Business URL</label></th><td><input type="url" id="vgr_business_url" name="vgr_business_url" value="<?php echo esc_attr( get_option( 'vgr_business_url', get_home_url() ) ); ?>" class="regular-text" /></td></tr>
                <tr>
                    <th>Output Schema On</th>
                    <td>
                        <?php $scope = get_option( 'vgr_schema_scope', '' ); ?>
                        <label style="display:block;margin-bottom:6px;"><input type="radio" name="vgr_schema_scope" value="sitewide" <?php checked( $scope, 'sitewide' ); ?> /> <strong>Sitewide</strong> <em style="color:#666;">(recommended for local business)</em></label>
                        <label style="display:block;margin-bottom:6px;"><input type="radio" name="vgr_schema_scope" value="page_ids" <?php checked( $scope, 'page_ids' ); ?> /> <strong>Specific pages</strong> — enter IDs below</label>
                        <label style="display:block;"><input type="radio" name="vgr_schema_scope" value="" <?php checked( $scope, '' ); ?> /> <strong>Disabled</strong> — use <code>[vgr_schema]</code> shortcode</label>
                    </td>
                </tr>
                <tr>
                    <th><label for="vgr_schema_page_ids">Page IDs</label></th>
                    <td><input type="text" id="vgr_schema_page_ids" name="vgr_schema_page_ids" value="<?php echo esc_attr( get_option( 'vgr_schema_page_ids', '' ) ); ?>" class="regular-text" placeholder="e.g. 42, 108" /><p class="description">Comma-separated. Only used when "Specific pages" is selected.</p></td>
                </tr>
            </table>
            <?php submit_button( 'Save Schema Settings' ); ?>
        </form>

        <?php if ( $agg ) : ?>
            <h3>Schema Preview</h3>
            <p>Based on <strong><?php echo $agg['count']; ?> reviews</strong> — average: <strong><?php echo $agg['average']; ?> ★</strong></p>
            <details><summary style="cursor:pointer;color:#2271b1;">View JSON-LD</summary><pre style="background:#f6f7f7;padding:12px;border-radius:4px;overflow:auto;max-height:300px;font-size:12px;"><?php echo esc_html( vgr_build_schema_json() ); ?></pre></details>
        <?php endif; ?>
    </div>
    <?php
}


// ─────────────────────────────────────────────────────────────────────────────
// SCHEMA
// ─────────────────────────────────────────────────────────────────────────────

function vgr_get_aggregate_data() {
    global $wpdb;
    $results = $wpdb->get_results( "SELECT pm.meta_value as rating FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'vgr_rating' WHERE p.post_type = 'google_review' AND p.post_status = 'publish'" );
    if ( empty( $results ) ) return null;
    $count = count( $results );
    $avg   = round( array_sum( array_column( $results, 'rating' ) ) / $count, 1 );
    return [ 'count' => $count, 'average' => $avg ];
}

function vgr_build_schema_json() {
    $agg = vgr_get_aggregate_data();
    if ( ! $agg || $agg['count'] < 1 ) return '';

    $review_posts = get_posts( [ 'post_type' => 'google_review', 'posts_per_page' => 10, 'orderby' => 'meta_value_num', 'meta_key' => 'vgr_rating', 'order' => 'DESC' ] );
    $reviews_schema = [];
    foreach ( $review_posts as $rp ) {
        $rdate = get_field( 'review_date', $rp->ID );
        $reviews_schema[] = [
            '@type'         => 'Review',
            'author'        => [ '@type' => 'Person', 'name' => get_field( 'reviewer_name', $rp->ID ) ],
            'datePublished' => $rdate ? date( 'Y-m-d', strtotime( $rdate ) ) : '',
            'reviewBody'    => get_field( 'review_text', $rp->ID ),
            'reviewRating'  => [ '@type' => 'Rating', 'ratingValue' => (string) get_field( 'rating', $rp->ID ), 'bestRating' => '5', 'worstRating' => '1' ],
        ];
    }

    $schema = [
        '@context'        => 'https://schema.org',
        '@type'           => get_option( 'vgr_business_type', 'LocalBusiness' ),
        'name'            => get_option( 'vgr_business_name', get_bloginfo('name') ),
        'url'             => get_option( 'vgr_business_url',  get_home_url() ),
        'aggregateRating' => [ '@type' => 'AggregateRating', 'ratingValue' => (string) $agg['average'], 'reviewCount' => (string) $agg['count'], 'bestRating' => '5', 'worstRating' => '1' ],
        'review'          => $reviews_schema,
    ];

    return '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>';
}

add_action( 'wp_head', 'vgr_maybe_output_schema' );
function vgr_maybe_output_schema() {
    if ( is_admin() ) return;
    $scope = get_option( 'vgr_schema_scope', '' );
    if ( $scope === 'sitewide' ) { echo vgr_build_schema_json(); return; }
    if ( $scope === 'page_ids' ) {
        $raw_ids  = get_option( 'vgr_schema_page_ids', '' );
        if ( ! $raw_ids ) return;
        $page_ids = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
        if ( empty( $page_ids ) ) return;
        $current_id = get_queried_object_id();
        if ( in_array( $current_id, $page_ids, true ) ) echo vgr_build_schema_json();
    }
}

add_shortcode( 'vgr_schema', function() { return vgr_build_schema_json(); } );


// ─────────────────────────────────────────────────────────────────────────────
// ELEMENTOR QUERY PRESETS
// ─────────────────────────────────────────────────────────────────────────────

// NOTE: posts_per_page is intentionally NOT set in any preset.
// Elementor's Loop Grid / Posts widget controls the per-page count and
// pagination. Setting posts_per_page here would override the widget setting
// and break pagination entirely.

add_action( 'elementor/query/google_reviews_all', function( $q ) {
    $q->set( 'post_type', 'google_review' );
    $q->set( 'orderby', [ 'meta_value_num' => 'DESC', 'date' => 'DESC' ] );
    $q->set( 'meta_key', 'vgr_rating' );
} );
add_action( 'elementor/query/google_reviews_featured', function( $q ) {
    $q->set( 'post_type', 'google_review' );
    $q->set( 'meta_query', [ [ 'key' => 'featured_review', 'value' => '1' ] ] );
} );
add_action( 'elementor/query/google_reviews_recent', function( $q ) {
    $q->set( 'post_type', 'google_review' );
    $q->set( 'orderby', 'date' );
    $q->set( 'order', 'DESC' );
} );
add_action( 'elementor/query/google_reviews_5star', function( $q ) {
    $q->set( 'post_type', 'google_review' );
    $q->set( 'orderby', 'date' );
    $q->set( 'order', 'DESC' );
    $q->set( 'meta_query', [ [ 'key' => 'vgr_rating', 'value' => '5', 'compare' => '=', 'type' => 'NUMERIC' ] ] );
} );
add_action( 'elementor/query/google_reviews_4plus', function( $q ) {
    $q->set( 'post_type', 'google_review' );
    $q->set( 'orderby', [ 'meta_value_num' => 'DESC', 'date' => 'DESC' ] );
    $q->set( 'meta_key', 'vgr_rating' );
    $q->set( 'meta_query', [ [ 'key' => 'vgr_rating', 'value' => '4', 'compare' => '>=', 'type' => 'NUMERIC' ] ] );
} );
add_action( 'elementor/query/google_reviews_3plus', function( $q ) {
    $q->set( 'post_type', 'google_review' );
    $q->set( 'orderby', [ 'meta_value_num' => 'DESC', 'date' => 'DESC' ] );
    $q->set( 'meta_key', 'vgr_rating' );
    $q->set( 'meta_query', [ [ 'key' => 'vgr_rating', 'value' => '3', 'compare' => '>=', 'type' => 'NUMERIC' ] ] );
} );
add_action( 'elementor/query/google_reviews_featured_5star', function( $q ) {
    $q->set( 'post_type', 'google_review' );
    $q->set( 'meta_query', [ 'relation' => 'AND', [ 'key' => 'featured_review', 'value' => '1' ], [ 'key' => 'vgr_rating', 'value' => '5', 'compare' => '=', 'type' => 'NUMERIC' ] ] );
} );

// Only reviews that have written text (excludes star-only ratings with no comment)
add_action( 'elementor/query/google_reviews_with_text', function( $q ) {
    $q->set( 'post_type', 'google_review' );
    $q->set( 'orderby', [ 'meta_value_num' => 'DESC', 'date' => 'DESC' ] );
    $q->set( 'meta_key', 'vgr_rating' );
    $q->set( 'meta_query', [
        'relation' => 'AND',
        [ 'key' => 'review_text', 'value' => '',  'compare' => '!=' ],
        [ 'key' => 'review_text', 'compare' => 'EXISTS' ],
    ] );
} );

// 4+ stars AND has written text — most common use case for a wall
add_action( 'elementor/query/google_reviews_4plus_with_text', function( $q ) {
    $q->set( 'post_type', 'google_review' );
    $q->set( 'orderby', [ 'meta_value_num' => 'DESC', 'date' => 'DESC' ] );
    $q->set( 'meta_key', 'vgr_rating' );
    $q->set( 'meta_query', [
        'relation' => 'AND',
        [ 'key' => 'vgr_rating',   'value' => '4', 'compare' => '>=', 'type' => 'NUMERIC' ],
        [ 'key' => 'review_text',  'value' => '',  'compare' => '!=' ],
        [ 'key' => 'review_text',  'compare' => 'EXISTS' ],
    ] );
} );

// Featured AND has written text
add_action( 'elementor/query/google_reviews_featured_with_text', function( $q ) {
    $q->set( 'post_type', 'google_review' );
    $q->set( 'meta_query', [
        'relation' => 'AND',
        [ 'key' => 'featured_review', 'value' => '1' ],
        [ 'key' => 'review_text',     'value' => '', 'compare' => '!=' ],
        [ 'key' => 'review_text',     'compare' => 'EXISTS' ],
    ] );
} );


// ─────────────────────────────────────────────────────────────────────────────
// FRONTEND CSS (bundled — no manual stylesheet needed)
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'wp_head', function() {
    ?>
    <style id="vgr-styles">
    .vgr-avatar-stack{display:inline-flex;align-items:center}
    .vgr-avatar-stack .vgr-avatar{width:36px;height:36px;border-radius:50%;border:2px solid #fff;object-fit:cover;margin-left:-10px;background:#e5e5e5}
    .vgr-avatar-stack .vgr-avatar:first-child{margin-left:0}
    .vgr-star-row{display:inline-block;color:#f5a623;font-size:20px;letter-spacing:2px;line-height:1}
    .vgr-stars-wrap{display:inline-block;position:relative;font-size:18px;letter-spacing:2px;line-height:1}
    .vgr-stars-wrap .vgr-stars-bg{color:#ddd}
    .vgr-stars-wrap .vgr-stars-fg{position:absolute;top:0;left:0;overflow:hidden;color:#f5a623;white-space:nowrap;width:calc(var(--vgr-rating,5)/5*100%)}
    .vgr-social-proof-bar{display:inline-flex;align-items:center;gap:10px;flex-wrap:wrap}
    .vgr-social-proof-bar .vgr-proof-meta{display:inline-flex;flex-direction:column;gap:2px}
    .vgr-social-proof-bar .vgr-proof-count{font-size:13px;opacity:.7;line-height:1}
    </style>
    <?php
}, 5 );


// ─────────────────────────────────────────────────────────────────────────────
// SHORTCODES
// ─────────────────────────────────────────────────────────────────────────────

// [vgr_avatar_stack count="5" query="featured"]
add_shortcode( 'vgr_avatar_stack', function( $atts ) {
    $atts = shortcode_atts( [ 'count' => 5, 'query' => 'featured' ], $atts );
    $mq   = [];
    if ( $atts['query'] === 'featured' )   $mq[] = [ 'key' => 'featured_review', 'value' => '1' ];
    elseif ( $atts['query'] === '5star' )  $mq[] = [ 'key' => 'vgr_rating', 'value' => '5', 'compare' => '=', 'type' => 'NUMERIC' ];
    $mq[] = [ 'key' => 'reviewer_photo', 'value' => '', 'compare' => '!=' ];
    $posts = get_posts( [ 'post_type' => 'google_review', 'posts_per_page' => (int) $atts['count'], 'meta_key' => 'vgr_rating', 'orderby' => 'meta_value_num', 'order' => 'DESC', 'meta_query' => $mq ] );
    if ( empty( $posts ) ) return '';
    $out = '<div class="vgr-avatar-stack" aria-hidden="true">';
    foreach ( $posts as $p ) $out .= '<img src="' . esc_url( get_field( 'reviewer_photo', $p->ID ) ) . '" alt="' . esc_attr( get_field( 'reviewer_name', $p->ID ) ) . '" class="vgr-avatar" loading="lazy" width="36" height="36">';
    return $out . '</div>';
} );

// [vgr_review_count label="from %d Google reviews"]
add_shortcode( 'vgr_review_count', function( $atts ) {
    $atts  = shortcode_atts( [ 'label' => 'from %d Google reviews', 'wrapper' => 'span' ], $atts );
    $count = (int) ( wp_count_posts( 'google_review' )->publish ?? 0 );
    $tag   = sanitize_key( $atts['wrapper'] );
    return '<' . $tag . ' class="vgr-proof-count">' . sprintf( esc_html( $atts['label'] ), $count ) . '</' . $tag . '>';
} );

// [vgr_aggregate_rating]
add_shortcode( 'vgr_aggregate_rating', function( $atts ) {
    $atts = shortcode_atts( [ 'wrapper' => 'span' ], $atts );
    $agg  = vgr_get_aggregate_data();
    if ( ! $agg ) return '';
    $tag = sanitize_key( $atts['wrapper'] );
    return '<' . $tag . ' class="vgr-aggregate-rating">' . esc_html( $agg['average'] ) . '</' . $tag . '>';
} );

// [vgr_star_rating stars="5"]
add_shortcode( 'vgr_star_rating', function( $atts ) {
    $atts  = shortcode_atts( [ 'stars' => 5 ], $atts );
    $stars = max( 1, min( 5, (int) $atts['stars'] ) );
    return '<span class="vgr-star-row" aria-label="' . $stars . ' out of 5 stars">' . str_repeat( '★', $stars ) . '</span>';
} );

// [vgr_social_proof_bar count="5" stars="5" label="from %d Google reviews" show_avg="yes" query="featured"]
add_shortcode( 'vgr_social_proof_bar', function( $atts ) {
    $atts    = shortcode_atts( [ 'count' => 5, 'stars' => 5, 'label' => 'from %d Google reviews', 'show_avg' => 'yes', 'query' => 'featured' ], $atts );
    $avatars = do_shortcode( '[vgr_avatar_stack count="' . (int) $atts['count'] . '" query="' . esc_attr( $atts['query'] ) . '"]' );
    $star_html = do_shortcode( '[vgr_star_rating stars="' . (int) $atts['stars'] . '"]' );
    $avg_html  = '';
    if ( $atts['show_avg'] === 'yes' ) { $agg = vgr_get_aggregate_data(); if ( $agg ) $avg_html = ' <span class="vgr-avg-num">' . esc_html( $agg['average'] ) . '</span>'; }
    $count_html = do_shortcode( '[vgr_review_count label="' . esc_attr( $atts['label'] ) . '"]' );
    return '<div class="vgr-social-proof-bar">' . $avatars . '<div class="vgr-proof-meta"><span class="vgr-proof-stars">' . $star_html . $avg_html . '</span>' . $count_html . '</div></div>';
} );

// [vgr_loop_stars rating="5"] — for use inside Loop Builder
add_shortcode( 'vgr_loop_stars', function( $atts ) {
    $atts   = shortcode_atts( [ 'rating' => 5 ], $atts );
    $rating = max( 1, min( 5, (int) $atts['rating'] ) );
    return '<span class="vgr-stars-wrap" aria-label="' . esc_attr( $rating . ' out of 5 stars' ) . '"><span class="vgr-stars-bg">★★★★★</span><span class="vgr-stars-fg" style="--vgr-rating:' . $rating . '">★★★★★</span></span>';
} );
