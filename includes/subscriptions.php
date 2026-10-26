<?php
/**
 * Turning a mandate into monthly collections.
 *
 * When GoCardless says the billing request is fulfilled, the parent has
 * authorised the mandate. We read the mandate id off the billing request and
 * create the subscription on it: the plan's amount, on the plan's day, for
 * however many collections are left this season.
 *
 * The return page can't do this — the webhook can arrive before or after
 * the parent gets back — so the return page only thanks them, and this file
 * does the work whichever arrives first. Creating twice is guarded by the
 * member already holding a subscription id.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cs_on_billing_request_fulfilled( $br_id ) {
	$res = cs_gc( 'GET', '/billing_requests/' . $br_id );
	if ( is_wp_error( $res ) || empty( $res['billing_requests'] ) ) {
		return;
	}
	$br      = $res['billing_requests'];
	$member  = isset( $br['metadata']['member_id'] ) ? (int) $br['metadata']['member_id'] : cs_member_by_gc( 'gc_billing_request', $br_id );
	$mandate = isset( $br['links']['mandate_request_mandate'] ) ? $br['links']['mandate_request_mandate'] : '';
	$customer= isset( $br['links']['customer'] ) ? $br['links']['customer'] : '';
	if ( ! $member || ! $mandate ) {
		cs_log( 'fulfilled but no member/mandate', array( 'br' => $br_id ) );
		return;
	}
	$m = cs_member( $member );
	cs_member_set( $member, array( 'gc_mandate' => $mandate, 'gc_customer' => $customer ) );
	if ( $m['gc_subscription'] ) {
		return; // already done
	}
	$plan = cs_plan( (int) $m['plan_id'] );
	if ( ! $plan['amount'] ) {
		cs_member_set( $member, array( 'status' => 'failed', 'notes' => 'Plan missing when mandate arrived' ) );
		return;
	}
	$sub = cs_create_subscription( $member, $plan, $mandate );
	if ( is_wp_error( $sub ) ) {
		cs_member_set( $member, array( 'status' => 'failed', 'notes' => 'Subscription failed: ' . $sub->get_error_message() ) );
		cs_notify_club( 'Subs: subscription could not be created', get_the_title( $member ) . ' — mandate is live but the monthly collection was not set up: ' . $sub->get_error_message() );
		return;
	}
	cs_member_set( $member, array( 'gc_subscription' => $sub, 'status' => 'active', 'notes' => '' ) );
	cs_notify_club( 'New subs sign-up', get_the_title( $member ) . ' joined ' . $plan['title'] . ' (' . cs_money( $plan['amount'] ) . ' a month). Parent: ' . $m['parent_name'] . ' <' . $m['parent_email'] . '>.' );
	cs_notify_parent_welcome( $member );
}

/**
 * Create the monthly subscription on a mandate. Returns the subscription id or WP_Error.
 */
function cs_create_subscription( $member, $plan, $mandate ) {
	$count = cs_plan_collections_left( $plan );
	$body  = array( 'subscriptions' => array(
		'amount'        => (int) $plan['amount'],
		'currency'      => 'GBP',
		'name'          => cs_club_name() . ' — ' . $plan['title'],
		'interval_unit' => 'monthly',
		'interval'      => 1,
		'day_of_month'  => (int) $plan['day_of_month'],
		'count'         => (int) $count,
		'links'         => array( 'mandate' => $mandate ),
		'metadata'      => array( 'member_id' => (string) $member, 'plan_id' => (string) $plan['id'] ),
	) );
	$res = cs_gc( 'POST', '/subscriptions', $body );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	cs_log( 'subscription created', array( 'member' => $member, 'sub' => $res['subscriptions']['id'], 'count' => $count ) );
	return $res['subscriptions']['id'];
}

/** Cancel a member's subscription at GoCardless (the mandate stays, so they can re-join without a new form). */
function cs_cancel_subscription( $member ) {
	$m = cs_member( $member );
	if ( ! $m['gc_subscription'] ) {
		return new WP_Error( 'cs_none', 'No subscription on this member.' );
	}
	$res = cs_gc( 'POST', '/subscriptions/' . $m['gc_subscription'] . '/actions/cancel', array( 'data' => array() ) );
	if ( is_wp_error( $res ) && 'cs_gc_422' !== $res->get_error_code() ) {
		return $res;
	}
	cs_member_set( $member, array( 'status' => 'cancelled', 'notes' => 'Cancelled ' . current_time( 'Y-m-d' ) ) );
	cs_log( 'subscription cancelled', array( 'member' => $member ) );
	return true;
}

add_action( 'admin_post_cs_cancel_sub', function () {
	$member = (int) ( $_GET['member'] ?? 0 );
	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'cs_cancel_' . $member ) ) {
		wp_die( 'Not allowed' );
	}
	$r = cs_cancel_subscription( $member );
	wp_safe_redirect( add_query_arg( array( 'post' => $member, 'action' => 'edit', 'cs_msg' => is_wp_error( $r ) ? rawurlencode( $r->get_error_message() ) : 'cancelled' ), admin_url( 'post.php' ) ) );
	exit;
} );

add_action( 'admin_notices', function () {
	if ( isset( $_GET['cs_msg'] ) ) {
		$msg = sanitize_text_field( wp_unslash( $_GET['cs_msg'] ) );
		echo '<div class="notice notice-' . ( 'cancelled' === $msg ? 'success' : 'error' ) . '"><p>' . esc_html( 'cancelled' === $msg ? 'Subscription cancelled at GoCardless.' : $msg ) . '</p></div>';
	}
} );
