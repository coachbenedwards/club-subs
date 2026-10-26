<?php
/**
 * The thinnest possible GoCardless client.
 *
 * Everything the plugin says to GoCardless goes through cs_gc(). One
 * function, one place to read the token, one place to log. The API is
 * plain JSON over HTTPS; a library would be more code than this file.
 *
 * Docs: https://developer.gocardless.com/api-reference
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CS_GC_VERSION = '2015-07-06';

/** Which GoCardless environment is in use: 'sandbox' or 'live'. */
function cs_gc_env() {
	$env = (string) get_option( 'cs_gc_env', 'sandbox' );
	return 'live' === $env ? 'live' : 'sandbox';
}

function cs_gc_base( $env = null ) {
	$env = $env ? $env : cs_gc_env();
	return 'live' === $env ? 'https://api.gocardless.com' : 'https://api-sandbox.gocardless.com';
}

/** The access token for the environment in use. Pasted by the club at Subs → Settings. */
function cs_gc_token( $env = null ) {
	$env = $env ? $env : cs_gc_env();
	return trim( (string) get_option( 'cs_gc_token_' . $env, '' ) );
}

/** The webhook endpoint secret for the environment in use. */
function cs_gc_webhook_secret( $env = null ) {
	$env = $env ? $env : cs_gc_env();
	return trim( (string) get_option( 'cs_gc_webhook_secret_' . $env, '' ) );
}

/**
 * Call GoCardless.
 *
 * @param string     $method GET|POST|PUT|DELETE
 * @param string     $path   e.g. '/billing_requests' or '/subscriptions/SB123/actions/cancel'
 * @param array|null $body   Decoded to JSON. For POST the resource is wrapped by the caller, e.g. ['billing_requests' => [...]].
 * @param array      $query  Query string for GET.
 * @return array|WP_Error Decoded JSON on 2xx; WP_Error with the GoCardless error body otherwise.
 */
function cs_gc( $method, $path, $body = null, $query = array() ) {
	$token = cs_gc_token();
	if ( '' === $token ) {
		return new WP_Error( 'cs_no_token', 'No GoCardless access token has been set for the ' . cs_gc_env() . ' environment.' );
	}
	$url = cs_gc_base() . $path;
	if ( $query ) {
		$url = add_query_arg( $query, $url );
	}
	$headers = array(
		'Authorization'      => 'Bearer ' . $token,
		'GoCardless-Version' => CS_GC_VERSION,
		'Accept'             => 'application/json',
		'Content-Type'       => 'application/json',
	);
	if ( 'POST' === $method ) {
		// Idempotency keeps a retried request from making a second mandate or subscription.
		$headers['Idempotency-Key'] = wp_generate_uuid4();
	}
	$args = array(
		'method'  => $method,
		'headers' => $headers,
		'timeout' => 30,
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}
	$res = wp_remote_request( $url, $args );
	if ( is_wp_error( $res ) ) {
		cs_log( 'gc transport error', array( 'path' => $path, 'error' => $res->get_error_message() ) );
		return $res;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( $code < 200 || $code >= 300 ) {
		$msg = isset( $json['error']['message'] ) ? $json['error']['message'] : ( 'HTTP ' . $code );
		cs_log( 'gc error', array( 'path' => $path, 'code' => $code, 'body' => $json ) );
		return new WP_Error( 'cs_gc_' . $code, $msg, $json );
	}
	return is_array( $json ) ? $json : array();
}

/** Walk a paginated GoCardless list and return every record. */
function cs_gc_list_all( $resource, $query = array(), $max = 2000 ) {
	$out   = array();
	$after = null;
	do {
		$q = array_merge( array( 'limit' => 500 ), $query );
		if ( $after ) {
			$q['after'] = $after;
		}
		$res = cs_gc( 'GET', '/' . $resource, null, $q );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$rows  = isset( $res[ $resource ] ) ? $res[ $resource ] : array();
		$out   = array_merge( $out, $rows );
		$after = isset( $res['meta']['cursors']['after'] ) ? $res['meta']['cursors']['after'] : null;
	} while ( $after && count( $out ) < $max );
	return $out;
}

/** Pence → "£12.50". */
function cs_money( $pence ) {
	return '£' . number_format( ( (int) $pence ) / 100, 2 );
}

/** Append a line to the plugin's own log (last 300 lines kept in an option). Never logs tokens. */
function cs_log( $what, $data = array() ) {
	$log   = (array) get_option( 'cs_log', array() );
	$log[] = array( 't' => current_time( 'mysql' ), 'what' => (string) $what, 'data' => $data );
	if ( count( $log ) > 300 ) {
		$log = array_slice( $log, -300 );
	}
	update_option( 'cs_log', $log, false );
}
