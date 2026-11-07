<?php
/**
 * Read-only routes for the club's assistant agent (Emma on Eastwood).
 * Auth reuses the site's assistant key (ew_emma_ok, X-Emma-Key header) when
 * the Eastwood plugin is present; without it every route answers 401.
 * Nothing here writes to GoCardless or to the members and payments records.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cs_emma_ok( $req ) {
	return function_exists( 'ew_emma_ok' ) && ew_emma_ok( $req );
}

add_action( 'rest_api_init', function () {
	$routes = array(
		'/emma/status'   => 'cs_emma_status',
		'/emma/chase'    => 'cs_emma_chase',
		'/emma/members'  => 'cs_emma_members',
		'/emma/month'    => 'cs_emma_month',
		'/emma/plans'    => 'cs_emma_plans',
		'/emma/payments' => 'cs_emma_payments',
	);
	foreach ( $routes as $path => $cb ) {
		register_rest_route( 'club-subs/v1', $path, array( 'methods' => 'GET', 'permission_callback' => 'cs_emma_ok', 'callback' => $cb ) );
	}
} );

function cs_emma_month_param( WP_REST_Request $r ) {
	$m = (string) $r->get_param( 'month' );
	return preg_match( '/^\d{4}-\d{2}$/', $m ) ? $m : current_time( 'Y-m' );
}

function cs_emma_status( WP_REST_Request $r ) {
	$count = function ( $type ) {
		$c = wp_count_posts( $type );
		return isset( $c->publish ) ? (int) $c->publish : 0;
	};
	return array(
		'environment' => cs_gc_env(),
		'token_set'   => '' !== cs_gc_token(),
		'members'     => $count( 'cs_member' ),
		'plans'       => $count( 'cs_plan' ),
		'payments'    => $count( 'cs_payment' ),
		'chase_to'    => (string) get_option( 'cs_chase_to', '' ),
		'chase_freq'  => (string) get_option( 'cs_chase_freq', 'off' ),
		'chase_last'  => (string) get_option( 'cs_chase_last', '' ),
	);
}

/** Who has paid and who hasn't this month, live from GoCardless. ?all=1 for every row, not just the ones to chase. */
function cs_emma_chase( WP_REST_Request $r ) {
	$out = cs_chase_build( cs_emma_month_param( $r ), (bool) $r->get_param( 'fresh' ) );
	if ( is_wp_error( $out ) ) {
		return new WP_REST_Response( array( 'error' => $out->get_error_message() ), 502 );
	}
	$all  = (bool) $r->get_param( 'all' );
	$team = strtolower( (string) $r->get_param( 'team' ) );
	$rows = array();
	foreach ( $out['rows'] as $row ) {
		if ( ! $all && ! cs_chase_is_chase( $row['state'] ) ) {
			continue;
		}
		if ( $team && false === strpos( strtolower( (string) $row['team'] ), $team ) ) {
			continue;
		}
		$row['state_label'] = CS_CHASE_STATES[ $row['state'] ];
		$row['amount_gbp']  = cs_money( $row['amount'] );
		$row['owed_gbp']    = cs_money( $row['owed'] );
		$rows[]             = $row;
	}
	return array(
		'month'  => $out['month'],
		'counts' => $out['counts'],
		'paid_gbp' => cs_money( $out['money']['paid'] ),
		'owed_gbp' => cs_money( $out['money']['owed'] ),
		'built'  => $out['built'],
		'rows'   => $rows,
	);
}

function cs_emma_members( WP_REST_Request $r ) {
	$q      = strtolower( trim( (string) $r->get_param( 'q' ) ) );
	$status = (string) $r->get_param( 'status' );
	$limit  = max( 1, min( 500, (int) ( $r->get_param( 'limit' ) ?: 100 ) ) );
	$ids    = get_posts( array( 'post_type' => 'cs_member', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC' ) );
	$rows   = array();
	foreach ( $ids as $id ) {
		$m = cs_member( $id );
		if ( $status && $m['status'] !== $status ) {
			continue;
		}
		if ( $q && false === strpos( strtolower( implode( ' ', array( $m['title'], $m['parent_name'], $m['parent_email'] ) ) ), $q ) ) {
			continue;
		}
		$plan          = $m['plan_id'] ? cs_plan( $m['plan_id'] ) : null;
		$m['plan']     = $plan ? $plan['title'] : '';
		$rows[]        = $m;
		if ( count( $rows ) >= $limit ) {
			break;
		}
	}
	return array( 'count' => count( $rows ), 'members' => $rows );
}

/** The plugin's own month report: members on its plans, with that month's payment state. */
function cs_emma_month( WP_REST_Request $r ) {
	$rep = cs_month_report( cs_emma_month_param( $r ) );
	$counts = array();
	foreach ( $rep['rows'] as $row ) {
		$counts[ $row['pay_bucket'] ] = ( $counts[ $row['pay_bucket'] ] ?? 0 ) + 1;
	}
	return array( 'month' => cs_emma_month_param( $r ), 'counts' => $counts, 'rows' => $rep['rows'] );
}

function cs_emma_plans( WP_REST_Request $r ) {
	$plans = cs_plans();
	foreach ( $plans as &$p ) {
		$p['amount_gbp'] = cs_money( $p['amount'] );
	}
	return array( 'plans' => $plans );
}

/** Raw GoCardless payments, newest first. ?status= &from=Y-m-d &to=Y-m-d &limit= */
function cs_emma_payments( WP_REST_Request $r ) {
	$q = array( 'limit' => max( 1, min( 500, (int) ( $r->get_param( 'limit' ) ?: 100 ) ) ) );
	if ( $r->get_param( 'status' ) ) {
		$q['status'] = sanitize_key( $r->get_param( 'status' ) );
	}
	foreach ( array( 'from' => 'charge_date[gte]', 'to' => 'charge_date[lte]' ) as $p => $k ) {
		$v = (string) $r->get_param( $p );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
			$q[ $k ] = $v;
		}
	}
	$res = cs_gc( 'GET', '/payments', null, $q );
	if ( is_wp_error( $res ) ) {
		return new WP_REST_Response( array( 'error' => $res->get_error_message() ), 502 );
	}
	$rows = array();
	foreach ( (array) ( $res['payments'] ?? array() ) as $p ) {
		$rows[] = array(
			'id' => $p['id'], 'status' => $p['status'], 'charge_date' => $p['charge_date'] ?? '',
			'amount_gbp' => cs_money( $p['amount'] ), 'description' => $p['description'] ?? '',
			'mandate' => $p['links']['mandate'] ?? '', 'subscription' => $p['links']['subscription'] ?? '',
		);
	}
	return array( 'count' => count( $rows ), 'payments' => $rows );
}
