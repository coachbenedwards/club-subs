<?php
/**
 * GoCardless → us. POST /wp-json/club-subs/v1/webhook
 *
 * One webhook can carry up to 250 events. Each is handled on its own, the
 * signature is checked first, and events can arrive out of order, so every
 * handler fetches the current state from GoCardless before acting on it
 * rather than trusting the event body.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'club-subs/v1', '/webhook', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => 'cs_rest_webhook',
	) );
} );

function cs_rest_webhook( WP_REST_Request $r ) {
	$raw    = (string) $r->get_body();
	$sig    = (string) $r->get_header( 'Webhook-Signature' );
	$secret = cs_gc_webhook_secret();
	if ( '' === $secret ) {
		cs_log( 'webhook refused: no secret set' );
		return new WP_REST_Response( array( 'error' => 'no secret' ), 498 );
	}
	$want = hash_hmac( 'sha256', $raw, $secret );
	if ( ! $sig || ! hash_equals( $want, $sig ) ) {
		cs_log( 'webhook refused: bad signature' );
		return new WP_REST_Response( array( 'error' => 'bad signature' ), 498 );
	}
	$body   = json_decode( $raw, true );
	$events = isset( $body['events'] ) && is_array( $body['events'] ) ? $body['events'] : array();
	$seen   = (array) get_option( 'cs_seen_events', array() );
	foreach ( $events as $ev ) {
		$id = isset( $ev['id'] ) ? (string) $ev['id'] : '';
		if ( $id && isset( $seen[ $id ] ) ) {
			continue; // GoCardless retries until it gets a 2xx; the same event may come twice.
		}
		try {
			cs_handle_event( $ev );
		} catch ( Throwable $t ) {
			cs_log( 'event handler threw', array( 'event' => $id, 'error' => $t->getMessage() ) );
		}
		if ( $id ) {
			$seen[ $id ] = time();
		}
	}
	// Keep the de-dupe list to the last day.
	$seen = array_filter( $seen, function ( $t ) { return $t > time() - DAY_IN_SECONDS; } );
	update_option( 'cs_seen_events', $seen, false );
	return new WP_REST_Response( array( 'ok' => true, 'handled' => count( $events ) ), 200 );
}

function cs_handle_event( $ev ) {
	$type   = isset( $ev['resource_type'] ) ? $ev['resource_type'] : '';
	$action = isset( $ev['action'] ) ? $ev['action'] : '';
	$links  = isset( $ev['links'] ) ? $ev['links'] : array();

	switch ( $type ) {
		case 'billing_requests':
			if ( 'fulfilled' === $action && ! empty( $links['billing_request'] ) ) {
				cs_on_billing_request_fulfilled( $links['billing_request'] );
			}
			break;

		case 'mandates':
			if ( empty( $links['mandate'] ) ) {
				break;
			}
			$member = cs_member_by_gc( 'gc_mandate', $links['mandate'] );
			if ( ! $member ) {
				break;
			}
			if ( in_array( $action, array( 'cancelled', 'failed', 'expired', 'consumed' ), true ) ) {
				cs_member_set( $member, array( 'status' => 'cancelled' === $action ? 'cancelled' : 'failed', 'notes' => 'Mandate ' . $action . ' ' . current_time( 'Y-m-d' ) ) );
				cs_notify_club( 'Direct Debit ' . $action, get_the_title( $member ) . ' — mandate ' . $links['mandate'] . ' ' . $action . '.' );
			}
			break;

		case 'subscriptions':
			if ( empty( $links['subscription'] ) ) {
				break;
			}
			$member = cs_member_by_gc( 'gc_subscription', $links['subscription'] );
			if ( $member && in_array( $action, array( 'cancelled', 'finished' ), true ) ) {
				cs_member_set( $member, array( 'status' => 'cancelled', 'notes' => 'Subscription ' . $action . ' ' . current_time( 'Y-m-d' ) ) );
			}
			break;

		case 'payments':
			if ( empty( $links['payment'] ) ) {
				break;
			}
			$res = cs_gc( 'GET', '/payments/' . $links['payment'] );
			if ( is_wp_error( $res ) || empty( $res['payments'] ) ) {
				break;
			}
			$p  = $res['payments'];
			$id = cs_payment_upsert( $p );
			if ( in_array( $p['status'], array( 'failed', 'charged_back', 'customer_approval_denied' ), true ) ) {
				$member = (int) get_post_meta( $id, '_cs_member_id', true );
				cs_notify_club( 'Subs payment ' . $p['status'], ( $member ? get_the_title( $member ) : 'Unknown member' ) . ' — ' . cs_money( $p['amount'] ) . ' due ' . $p['charge_date'] . ' ' . $p['status'] . '.' );
			}
			break;
	}
	cs_log( 'event', array( 'type' => $type, 'action' => $action, 'links' => $links ) );
}
