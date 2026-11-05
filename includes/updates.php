<?php
/**
 * Updates from GitHub, the same way the Eastwood plugin updates: push a new
 * version to the repository, and WordPress offers it on the Updates screen.
 *
 * Reads plugin.json from raw.githubusercontent.com (reachable from managed
 * hosts behind Cloudflare; api.github.com often is not) and installs the
 * branch zip. Nothing secret lives in this repository, so it can be public
 * and no token is needed on the club's server.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CS_UPDATER', true );
const CS_REPO   = 'coachbenedwards/club-subs';
const CS_BRANCH = 'main';

function cs_remote_info() {
	$forced  = isset( $_GET['force-check'] );
	$cached  = get_transient( 'cs_remote' );
	// A cached version no newer than what is installed cannot be told apart
	// from "nothing released since", so it is re-read rather than believed.
	$suspect = is_array( $cached ) && ! empty( $cached['version'] ) && version_compare( $cached['version'], CS_VERSION, '<=' );
	if ( ! $forced && ! $suspect && false !== $cached ) {
		return $cached;
	}
	$res  = wp_remote_get( 'https://raw.githubusercontent.com/' . CS_REPO . '/' . CS_BRANCH . '/plugin.json', array( 'timeout' => 15 ) );
	$info = array();
	if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
		$d = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( is_array( $d ) && ! empty( $d['version'] ) ) {
			$info = $d;
		}
	}
	set_transient( 'cs_remote', $info, empty( $info ) ? 5 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS );
	return $info;
}

add_action( 'delete_site_transient_update_plugins', function () {
	delete_transient( 'cs_remote' );
} );

add_filter( 'pre_set_site_transient_update_plugins', function ( $t ) {
	if ( ! is_object( $t ) ) {
		$t = new stdClass();
	}
	$info = cs_remote_info();
	if ( empty( $info['version'] ) || ! version_compare( $info['version'], CS_VERSION, '>' ) ) {
		return $t;
	}
	if ( ! isset( $t->response ) || ! is_array( $t->response ) ) {
		$t->response = array();
	}
	$t->response[ plugin_basename( CS_FILE ) ] = (object) array(
		'slug'        => 'club-subs',
		'plugin'      => plugin_basename( CS_FILE ),
		'new_version' => $info['version'],
		'url'         => 'https://github.com/' . CS_REPO,
		'package'     => 'https://github.com/' . CS_REPO . '/archive/refs/heads/' . CS_BRANCH . '.zip',
	);
	return $t;
} );

/** The branch zip unpacks to club-subs-main/; install it as club-subs/. */
add_filter( 'upgrader_source_selection', function ( $source, $remote_source ) {
	if ( 0 !== strpos( basename( untrailingslashit( $source ) ), 'club-subs-' ) ) {
		return $source;
	}
	$fixed = trailingslashit( $remote_source ) . 'club-subs/';
	global $wp_filesystem;
	if ( $wp_filesystem && $wp_filesystem->move( $source, $fixed, true ) ) {
		return $fixed;
	}
	return $source;
}, 10, 2 );
