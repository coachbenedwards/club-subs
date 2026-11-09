<?php
/**
 * Plugin Name: Club Subs
 * Description: Kids' and members' subs collected by Direct Debit through the club's own GoCardless account, from the club's own website. Sign-up block, monthly collection, who's-paid report, parent self-serve, import of existing GoCardless mandates.
 * Version: 0.5.3
 * Author: Enabled
 * Requires PHP: 7.4
 * Text Domain: club-subs
 *
 * INSTALL: a normal plugin at wp-content/plugins/club-subs/. TO REMOVE:
 * deactivate and delete; members, plans and payment records stay in the
 * database as posts until you delete those too.
 *
 * No key lives in this file. The GoCardless access token is pasted once at
 * Subs → Settings and stored write-only in the options table. Sandbox and
 * live tokens are both held; a radio picks which one is in use.
 *
 * Money never touches this plugin or this server. The parent sets the
 * Direct Debit up on GoCardless' own hosted page; GoCardless collects and
 * pays the club out. This plugin only tells GoCardless what to collect and
 * listens to what happened.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CS_VERSION', '0.5.3' );
define( 'CS_FILE', __FILE__ );
define( 'CS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CS_URL', plugin_dir_url( __FILE__ ) );

/** A plugin asset's URL, stamped with the file's mtime so a changed file reaches browsers that cached the old one. */
function cs_asset_url( $rel ) {
	$rel  = ltrim( (string) $rel, '/' );
	$file = CS_DIR . $rel;
	$url  = CS_URL . $rel;
	return file_exists( $file ) ? add_query_arg( 'v', (string) filemtime( $file ), $url ) : $url;
}

require_once CS_DIR . 'includes/gocardless-api.php';
require_once CS_DIR . 'includes/post-types.php';
require_once CS_DIR . 'includes/settings.php';
require_once CS_DIR . 'includes/plans.php';
require_once CS_DIR . 'includes/signup.php';
require_once CS_DIR . 'includes/webhook.php';
require_once CS_DIR . 'includes/subscriptions.php';
require_once CS_DIR . 'includes/report.php';
require_once CS_DIR . 'includes/account.php';
require_once CS_DIR . 'includes/import.php';
require_once CS_DIR . 'includes/notify.php';
require_once CS_DIR . 'includes/chase.php';
require_once CS_DIR . 'includes/updates.php';
require_once CS_DIR . 'includes/pitchero.php';
require_once CS_DIR . 'includes/squads.php';
require_once CS_DIR . 'includes/emma.php';
require_once CS_DIR . 'includes/emma-changes.php';

register_activation_hook( __FILE__, function () {
	cs_register_post_types();
	flush_rewrite_rules();
	if ( ! get_option( 'cs_gc_env' ) ) {
		update_option( 'cs_gc_env', 'sandbox' );
	}
} );

/** Let the Eastwood site's page-layout channel write our blocks as editable widgets. */
add_filter( 'ew_blocks_allowed', function ( $list ) {
	return array_merge( (array) $list, array( 'club_subs_signup', 'club_subs_account' ) );
} );

add_action( 'wp_enqueue_scripts', function () {
	wp_register_style( 'club-subs', cs_asset_url( 'assets/subs.css' ), array(), null );
	wp_register_script( 'club-subs', cs_asset_url( 'assets/subs.js' ), array(), null, true );
} );
