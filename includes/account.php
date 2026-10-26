<?php
/**
 * Parent self-serve: [club_subs_account]
 *
 * No passwords. The parent types their email, we send a link that works for
 * an hour, and the page then shows every child on that email with the plan,
 * status and the next collection, and lets them cancel.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cs_account_defaults() {
	return array(
		'title'       => 'Your subs',
		'intro'       => 'Enter the email you signed up with and we\'ll send you a link to manage your Direct Debit.',
		'email'       => 'Email',
		'button'      => 'Email me a link',
		'sent'        => 'If that email is on our books, a link is on its way. It works for an hour.',
		'expired'     => 'That link has expired. Ask for a new one below.',
		'none'        => 'There are no subs on this email yet.',
		'plan'        => 'Team',
		'status'      => 'Status',
		'amount'      => 'Each month',
		'next'        => 'Next collection',
		'cancel'      => 'Cancel this Direct Debit',
		'cancel_sure' => 'Cancel {child}\'s subs? The club will be told. You can sign up again any time.',
		'cancelled'   => 'Cancelled. The club has been told.',
		'mail_subject'=> 'Your {club} subs',
		'mail_body'   => 'Hi {name},' . "\n\n" . 'Here\'s your link to manage your {club} Direct Debit. It works for an hour:' . "\n\n" . '{link}' . "\n\n" . 'If you didn\'t ask for this, ignore it.' . "\n\n" . '{club}',
		'status_active'    => 'Active',
		'status_pending'   => 'Setting up',
		'status_cancelled' => 'Cancelled',
		'status_failed'    => 'Needs attention — contact the club',
	);
}

add_shortcode( 'club_subs_account', function ( $atts ) {
	$a = shortcode_atts( cs_account_defaults(), (array) $atts, 'club_subs_account' );
	wp_enqueue_style( 'club-subs' );
	wp_enqueue_script( 'club-subs' );
	wp_localize_script( 'club-subs', 'CS_SUBS', array( 'rest' => esc_url_raw( rest_url( 'club-subs/v1/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'page' => get_the_ID(), 'words' => array( 'cancel_sure' => $a['cancel_sure'], 'cancelled' => $a['cancelled'] ) ) );

	$html = '<div class="cs cs-account" data-cs-account><h2 class="cs-h">' . esc_html( $a['title'] ) . '</h2>';
	$token = isset( $_GET['cs_t'] ) ? sanitize_text_field( wp_unslash( $_GET['cs_t'] ) ) : '';
	if ( $token ) {
		$email = cs_account_token_email( $token );
		if ( ! $email ) {
			$html .= '<p class="cs-exit">' . esc_html( $a['expired'] ) . '</p>';
		} else {
			$members = get_posts( array( 'post_type' => 'cs_member', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_cs_parent_email', 'meta_value' => $email, 'orderby' => 'title', 'order' => 'ASC' ) );
			if ( ! $members ) {
				$html .= '<p>' . esc_html( $a['none'] ) . '</p>';
			}
			foreach ( $members as $mid ) {
				$m    = cs_member( $mid );
				$plan = $m['plan_id'] ? cs_plan( (int) $m['plan_id'] ) : null;
				$next = '—';
				if ( 'active' === $m['status'] && $m['gc_subscription'] ) {
					$sub = cs_gc( 'GET', '/subscriptions/' . $m['gc_subscription'] );
					if ( ! is_wp_error( $sub ) && ! empty( $sub['subscriptions']['upcoming_payments'][0]['charge_date'] ) ) {
						$next = date_i18n( 'j F Y', strtotime( $sub['subscriptions']['upcoming_payments'][0]['charge_date'] ) );
					}
				}
				$html .= '<div class="cs-card" data-member="' . (int) $mid . '" data-token="' . esc_attr( $token ) . '">';
				$html .= '<h3 class="cs-h3">' . esc_html( $m['title'] ) . '</h3><dl class="cs-dl">';
				$html .= '<dt>' . esc_html( $a['plan'] ) . '</dt><dd>' . esc_html( $plan ? $plan['title'] : '—' ) . '</dd>';
				$html .= '<dt>' . esc_html( $a['status'] ) . '</dt><dd>' . esc_html( $a[ 'status_' . ( $m['status'] ?: 'pending' ) ] ) . '</dd>';
				$html .= '<dt>' . esc_html( $a['amount'] ) . '</dt><dd>' . esc_html( $plan ? cs_money( $plan['amount'] ) : '—' ) . '</dd>';
				$html .= '<dt>' . esc_html( $a['next'] ) . '</dt><dd>' . esc_html( $next ) . '</dd></dl>';
				if ( 'active' === $m['status'] ) {
					$html .= '<button type="button" class="cs-btn cs-btn-quiet" data-cs-cancel data-child="' . esc_attr( $m['child_first'] ) . '">' . esc_html( $a['cancel'] ) . '</button>';
				}
				$html .= '<p class="cs-err" data-cs-msg hidden></p></div>';
			}
			return $html . '</div>';
		}
	}
	$html .= '<p class="cs-intro">' . esc_html( $a['intro'] ) . '</p>';
	$html .= '<form class="cs-form" data-cs-link novalidate><input type="email" id="cs_acct_email" name="email" placeholder="' . esc_attr( $a['email'] ) . '" autocomplete="email" required><button type="submit" class="cs-btn">' . esc_html( $a['button'] ) . '</button><p class="cs-note" data-cs-sent hidden>' . esc_html( $a['sent'] ) . '</p><p class="cs-err" data-cs-err hidden></p></form>';
	return $html . '</div>';
} );

/** Token → email, or '' when unknown/expired. Tokens live an hour in a transient. */
function cs_account_token_email( $token ) {
	$t = get_transient( 'cs_acct_' . $token );
	return is_string( $t ) ? $t : '';
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'club-subs/v1', '/account/link', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'cs_rest_account_link' ) );
	register_rest_route( 'club-subs/v1', '/account/cancel', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'cs_rest_account_cancel' ) );
} );

function cs_rest_account_link( WP_REST_Request $r ) {
	$email = sanitize_email( (string) $r->get_param( 'email' ) );
	$page  = (int) $r->get_param( 'page' );
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'cs_email', 'Enter an email.', array( 'status' => 400 ) );
	}
	// Always say "sent" so the page can't be used to test which emails are on the books.
	$has = get_posts( array( 'post_type' => 'cs_member', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_cs_parent_email', 'meta_value' => $email ) );
	if ( $has ) {
		$token = wp_generate_password( 32, false );
		set_transient( 'cs_acct_' . $token, $email, HOUR_IN_SECONDS );
		$a    = cs_account_defaults();
		$atts = (array) $r->get_param( 'words' );
		$link = add_query_arg( 'cs_t', $token, $page ? get_permalink( $page ) : home_url( '/' ) );
		$name = (string) get_post_meta( (int) $has[0], '_cs_parent_name', true );
		$vars = array( '{club}' => cs_club_name(), '{link}' => $link, '{name}' => $name ?: 'there' );
		cs_mail( $email, strtr( $atts['mail_subject'] ?? $a['mail_subject'], $vars ), strtr( $atts['mail_body'] ?? $a['mail_body'], $vars ) );
	}
	return array( 'ok' => true );
}

function cs_rest_account_cancel( WP_REST_Request $r ) {
	$token  = sanitize_text_field( (string) $r->get_param( 'token' ) );
	$member = (int) $r->get_param( 'member' );
	$email  = cs_account_token_email( $token );
	if ( ! $email || ! $member || get_post_meta( $member, '_cs_parent_email', true ) !== $email ) {
		return new WP_Error( 'cs_auth', 'That link has expired.', array( 'status' => 403 ) );
	}
	$res = cs_cancel_subscription( $member );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'cs_gc', $res->get_error_message(), array( 'status' => 502 ) );
	}
	cs_notify_club( 'Subs cancelled by parent', get_the_title( $member ) . ' — cancelled from the account page by ' . $email . '.' );
	return array( 'ok' => true );
}
