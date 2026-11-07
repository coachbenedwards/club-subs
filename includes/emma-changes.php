<?php
/**
 * GoCardless changes asked for by the club's assistant agent.
 *
 * The agent can only PROPOSE a change. Nothing reaches GoCardless until a
 * logged-in site admin opens the approval page and presses Approve. The
 * approval page sits outside the agent's routes, so its key cannot approve.
 * Each proposal is one-off, expires after 24 hours, and is logged.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CS_EMMA_ACTIONS = array(
	'payment_create'      => 'Take a one-off payment',
	'payment_retry'       => 'Retry a failed payment',
	'payment_cancel'      => 'Cancel a payment not yet collected',
	'refund'              => 'Refund a payment',
	'subscription_amount' => 'Change a monthly amount',
	'subscription_pause'  => 'Pause a subscription',
	'subscription_resume' => 'Resume a subscription',
	'subscription_cancel' => 'Cancel a subscription',
);

add_action( 'rest_api_init', function () {
	register_rest_route( 'club-subs/v1', '/emma/propose', array( 'methods' => 'POST', 'permission_callback' => 'cs_emma_ok', 'callback' => 'cs_emma_propose' ) );
	register_rest_route( 'club-subs/v1', '/emma/pending', array( 'methods' => 'GET', 'permission_callback' => 'cs_emma_ok', 'callback' => 'cs_emma_pending' ) );
} );

function cs_emma_store() {
	$all = (array) get_option( 'cs_emma_changes', array() );
	foreach ( $all as $id => $c ) {
		if ( 'waiting' === $c['status'] && time() > $c['expires'] ) {
			$all[ $id ]['status'] = 'expired';
		}
	}
	return $all;
}

function cs_emma_save( $all ) {
	if ( count( $all ) > 200 ) {
		$all = array_slice( $all, -200, null, true );
	}
	update_option( 'cs_emma_changes', $all, false );
}

function cs_emma_approve_url( $id ) {
	return admin_url( 'admin.php?page=cs-approve&change=' . rawurlencode( $id ) );
}

function cs_emma_customer_for_mandate( $mandate ) {
	$md = cs_gc( 'GET', '/mandates/' . rawurlencode( $mandate ) );
	if ( is_wp_error( $md ) ) {
		return $md;
	}
	$cid = $md['mandates']['links']['customer'] ?? '';
	$c   = $cid ? cs_gc( 'GET', '/customers/' . rawurlencode( $cid ) ) : null;
	if ( ! $c || is_wp_error( $c ) ) {
		return array( 'name' => '', 'email' => '', 'mandate_status' => $md['mandates']['status'] );
	}
	$c = $c['customers'];
	return array(
		'name'           => trim( ( $c['given_name'] ?? '' ) . ' ' . ( $c['family_name'] ?? '' ) ) ?: ( $c['company_name'] ?? '' ),
		'email'          => $c['email'] ?? '',
		'mandate_status' => $md['mandates']['status'],
	);
}

/** Read the real thing from GoCardless and say in one line what will happen. */
function cs_emma_describe( $action, $p ) {
	$pence = (int) ( $p['amount_pence'] ?? 0 );
	switch ( $action ) {
		case 'payment_create':
			$mandate = (string) ( $p['mandate'] ?? '' );
			if ( ! $mandate && ! empty( $p['member'] ) ) {
				$mandate = cs_member( (int) $p['member'] )['gc_mandate'];
			}
			if ( ! $mandate || $pence < 100 || empty( $p['description'] ) ) {
				return new WP_Error( 'cs_bad', 'Needs mandate (or member), amount_pence of at least 100 and a description.' );
			}
			$who = cs_emma_customer_for_mandate( $mandate );
			if ( is_wp_error( $who ) ) {
				return $who;
			}
			return array(
				'params'  => array( 'mandate' => $mandate, 'amount_pence' => $pence, 'description' => (string) $p['description'], 'charge_date' => (string) ( $p['charge_date'] ?? '' ) ),
				'summary' => 'Take ' . cs_money( $pence ) . ' from ' . $who['name'] . ' (' . $who['email'] . ') for "' . $p['description'] . '"' . ( ! empty( $p['charge_date'] ) ? ' on ' . $p['charge_date'] : ' as soon as possible' ) . '. Mandate ' . $mandate . ' is ' . $who['mandate_status'] . '.',
			);
		case 'payment_retry':
		case 'payment_cancel':
		case 'refund':
			$pid = (string) ( $p['payment'] ?? '' );
			$pay = $pid ? cs_gc( 'GET', '/payments/' . rawurlencode( $pid ) ) : new WP_Error( 'cs_bad', 'Needs payment (the PM… id).' );
			if ( is_wp_error( $pay ) ) {
				return $pay;
			}
			$pay = $pay['payments'];
			$who = cs_emma_customer_for_mandate( $pay['links']['mandate'] ?? '' );
			$who = is_wp_error( $who ) ? array( 'name' => '?', 'email' => '' ) : $who;
			$base = cs_money( $pay['amount'] ) . ' payment ' . $pid . ' from ' . $who['name'] . ' (' . $who['email'] . '), charge date ' . ( $pay['charge_date'] ?? '' ) . ', now ' . $pay['status'];
			if ( 'refund' === $action ) {
				$done = (int) ( $pay['amount_refunded'] ?? 0 );
				if ( $pence < 1 || $pence + $done > (int) $pay['amount'] ) {
					return new WP_Error( 'cs_bad', 'amount_pence must be at least 1 and no more than ' . ( (int) $pay['amount'] - $done ) . ' (what is left to refund).' );
				}
				return array( 'params' => array( 'payment' => $pid, 'amount_pence' => $pence, 'total' => $pence + $done ), 'summary' => 'Refund ' . cs_money( $pence ) . ' of the ' . $base . '.' );
			}
			return array( 'params' => array( 'payment' => $pid ), 'summary' => ( 'payment_retry' === $action ? 'Retry the ' : 'Cancel the ' ) . $base . '.' );
		default:
			$sid = (string) ( $p['subscription'] ?? '' );
			if ( ! $sid && ! empty( $p['member'] ) ) {
				$sid = cs_member( (int) $p['member'] )['gc_subscription'];
			}
			$sub = $sid ? cs_gc( 'GET', '/subscriptions/' . rawurlencode( $sid ) ) : new WP_Error( 'cs_bad', 'Needs subscription (the SB… id) or member.' );
			if ( is_wp_error( $sub ) ) {
				return $sub;
			}
			$sub = $sub['subscriptions'];
			$who = cs_emma_customer_for_mandate( $sub['links']['mandate'] ?? '' );
			$who = is_wp_error( $who ) ? array( 'name' => '?', 'email' => '' ) : $who;
			$base = 'the ' . cs_money( $sub['amount'] ) . ' a month subscription ' . $sid . ' "' . ( $sub['name'] ?? '' ) . '" for ' . $who['name'] . ' (' . $who['email'] . '), now ' . $sub['status'];
			if ( 'subscription_amount' === $action ) {
				if ( $pence < 100 ) {
					return new WP_Error( 'cs_bad', 'amount_pence must be at least 100.' );
				}
				return array( 'params' => array( 'subscription' => $sid, 'amount_pence' => $pence ), 'summary' => 'Change ' . $base . ' to ' . cs_money( $pence ) . ' a month.' );
			}
			$verb = array( 'subscription_pause' => 'Pause ', 'subscription_resume' => 'Resume ', 'subscription_cancel' => 'Cancel ' );
			return array( 'params' => array( 'subscription' => $sid ), 'summary' => $verb[ $action ] . $base . '.' );
	}
}

function cs_emma_propose( WP_REST_Request $r ) {
	$action = (string) $r->get_param( 'action' );
	if ( ! isset( CS_EMMA_ACTIONS[ $action ] ) ) {
		return new WP_REST_Response( array( 'error' => 'action must be one of: ' . implode( ', ', array_keys( CS_EMMA_ACTIONS ) ) ), 400 );
	}
	$d = cs_emma_describe( $action, (array) $r->get_json_params() );
	if ( is_wp_error( $d ) ) {
		return new WP_REST_Response( array( 'error' => $d->get_error_message() ), 400 );
	}
	$id  = strtolower( wp_generate_password( 10, false ) );
	$all = cs_emma_store();
	$all[ $id ] = array(
		'id' => $id, 'action' => $action, 'params' => $d['params'], 'summary' => $d['summary'],
		'reason' => sanitize_text_field( (string) $r->get_param( 'reason' ) ),
		'created' => time(), 'expires' => time() + DAY_IN_SECONDS, 'status' => 'waiting',
	);
	cs_emma_save( $all );
	cs_log( 'assistant proposed a change', array( 'id' => $id, 'action' => $action, 'summary' => $d['summary'] ) );
	return array( 'id' => $id, 'status' => 'waiting', 'summary' => $d['summary'], 'approve_url' => cs_emma_approve_url( $id ), 'expires' => wp_date( 'Y-m-d H:i', time() + DAY_IN_SECONDS ) );
}

function cs_emma_pending( WP_REST_Request $r ) {
	$rows = array();
	foreach ( array_reverse( cs_emma_store(), true ) as $c ) {
		$rows[] = array( 'id' => $c['id'], 'status' => $c['status'], 'summary' => $c['summary'], 'result' => $c['result'] ?? '', 'created' => wp_date( 'Y-m-d H:i', $c['created'] ), 'approve_url' => 'waiting' === $c['status'] ? cs_emma_approve_url( $c['id'] ) : '' );
		if ( count( $rows ) >= 30 ) {
			break;
		}
	}
	return array( 'changes' => $rows );
}

/** Do it. Only ever called from the approval page, after an admin presses Approve. */
function cs_emma_execute( $c ) {
	$p = $c['params'];
	switch ( $c['action'] ) {
		case 'payment_create':
			$body = array( 'amount' => $p['amount_pence'], 'currency' => 'GBP', 'description' => $p['description'], 'links' => array( 'mandate' => $p['mandate'] ) );
			if ( $p['charge_date'] ) {
				$body['charge_date'] = $p['charge_date'];
			}
			return cs_gc( 'POST', '/payments', array( 'payments' => $body ) );
		case 'payment_retry':
			return cs_gc( 'POST', '/payments/' . rawurlencode( $p['payment'] ) . '/actions/retry', array( 'data' => array() ) );
		case 'payment_cancel':
			return cs_gc( 'POST', '/payments/' . rawurlencode( $p['payment'] ) . '/actions/cancel', array( 'data' => array() ) );
		case 'refund':
			return cs_gc( 'POST', '/refunds', array( 'refunds' => array( 'amount' => $p['amount_pence'], 'total_amount_confirmation' => $p['total'], 'links' => array( 'payment' => $p['payment'] ) ) ) );
		case 'subscription_amount':
			return cs_gc( 'PUT', '/subscriptions/' . rawurlencode( $p['subscription'] ), array( 'subscriptions' => array( 'amount' => $p['amount_pence'] ) ) );
		case 'subscription_pause':
			return cs_gc( 'POST', '/subscriptions/' . rawurlencode( $p['subscription'] ) . '/actions/pause', array( 'data' => array() ) );
		case 'subscription_resume':
			return cs_gc( 'POST', '/subscriptions/' . rawurlencode( $p['subscription'] ) . '/actions/resume', array( 'data' => array() ) );
		case 'subscription_cancel':
			$m = cs_member_by_gc( 'gc_subscription', $p['subscription'] );
			return $m ? cs_cancel_subscription( $m ) : cs_gc( 'POST', '/subscriptions/' . rawurlencode( $p['subscription'] ) . '/actions/cancel', array( 'data' => array() ) );
	}
	return new WP_Error( 'cs_bad', 'Unknown action.' );
}

add_action( 'admin_menu', function () {
	add_submenu_page( 'club-subs', 'Assistant changes', 'Assistant changes', 'manage_options', 'cs-approve', 'cs_emma_approve_page' );
}, 20 );

function cs_emma_approve_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed' );
	}
	$all = cs_emma_store();
	$msg = '';
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['change'] ) ) {
		$id = sanitize_key( wp_unslash( $_POST['change'] ) );
		check_admin_referer( 'cs_approve_' . $id );
		if ( isset( $all[ $id ] ) && 'waiting' === $all[ $id ]['status'] ) {
			$user = wp_get_current_user()->user_login;
			if ( isset( $_POST['approve'] ) ) {
				$res = cs_emma_execute( $all[ $id ] );
				$all[ $id ]['status'] = is_wp_error( $res ) ? 'failed' : 'done';
				$all[ $id ]['result'] = is_wp_error( $res ) ? $res->get_error_message() : 'Done at GoCardless';
			} else {
				$all[ $id ]['status'] = 'rejected';
				$all[ $id ]['result'] = 'Rejected';
			}
			$all[ $id ]['decided'] = time();
			$all[ $id ]['by']      = $user;
			cs_emma_save( $all );
			cs_log( 'assistant change ' . $all[ $id ]['status'], array( 'id' => $id, 'by' => $user, 'summary' => $all[ $id ]['summary'], 'result' => $all[ $id ]['result'] ) );
			$msg = $all[ $id ]['summary'] . ' — ' . $all[ $id ]['result'] . '.';
		}
	}
	$env = cs_gc_env();
	echo '<div class="wrap"><h1>Assistant changes</h1>';
	echo '<p>GoCardless is in <strong>' . esc_html( $env ) . '</strong> mode. Nothing here happens until you press Approve.</p>';
	if ( $msg ) {
		echo '<div class="notice notice-info"><p>' . esc_html( $msg ) . '</p></div>';
	}
	$focus = isset( $_GET['change'] ) ? sanitize_key( wp_unslash( $_GET['change'] ) ) : '';
	$rows  = array_reverse( $all, true );
	if ( ! $rows ) {
		echo '<p>No changes asked for yet.</p>';
	}
	foreach ( $rows as $c ) {
		$hl = $c['id'] === $focus ? 'border:2px solid #1d2327;' : 'border:1px solid #c3c4c7;';
		echo '<div style="' . $hl . 'background:#fff;padding:16px;margin:12px 0;max-width:760px">';
		echo '<p style="font-size:15px;margin:0 0 8px"><strong>' . esc_html( CS_EMMA_ACTIONS[ $c['action'] ] ) . '</strong></p>';
		echo '<p style="font-size:15px;margin:0 0 8px">' . esc_html( $c['summary'] ) . '</p>';
		if ( $c['reason'] ) {
			echo '<p style="margin:0 0 8px;color:#50575e">Why: ' . esc_html( $c['reason'] ) . '</p>';
		}
		echo '<p style="margin:0 0 8px;color:#50575e">Asked ' . esc_html( wp_date( 'D j M, H:i', $c['created'] ) ) . ' · ' . esc_html( $c['status'] ) . ( ! empty( $c['result'] ) ? ': ' . esc_html( $c['result'] ) : '' ) . '</p>';
		if ( 'waiting' === $c['status'] ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=cs-approve' ) ) . '">';
			wp_nonce_field( 'cs_approve_' . $c['id'] );
			echo '<input type="hidden" name="change" value="' . esc_attr( $c['id'] ) . '">';
			echo '<button class="button button-primary" name="approve" value="1" onclick="return confirm(\'Approve this in GoCardless (' . esc_js( $env ) . ')? This cannot be undone here.\')">Approve</button> ';
			echo '<button class="button" name="reject" value="1">Reject</button></form>';
		}
		echo '</div>';
	}
	echo '</div>';
}
