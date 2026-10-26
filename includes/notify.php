<?php
/**
 * Emails. From the club, never from a person.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cs_mail( $to, $subject, $body ) {
	$from_name  = (string) get_option( 'cs_from_name', '' ) ?: cs_club_name();
	$from_email = (string) get_option( 'cs_from_email', '' ) ?: get_option( 'admin_email' );
	$headers    = array( 'From: ' . $from_name . ' <' . $from_email . '>', 'Content-Type: text/plain; charset=UTF-8' );
	return wp_mail( $to, $subject, $body, $headers );
}

/** Tell the club something happened. Goes to the address at Subs → Settings; nothing if blank. */
function cs_notify_club( $subject, $body ) {
	$to = (string) get_option( 'cs_notify_email', '' );
	if ( ! is_email( $to ) ) {
		return false;
	}
	return cs_mail( $to, '[Subs] ' . $subject, $body . "\n\n" . admin_url( 'admin.php?page=club-subs' ) );
}

/** Welcome the parent once the subscription is live. */
function cs_notify_parent_welcome( $member ) {
	$m    = cs_member( $member );
	$plan = cs_plan( (int) $m['plan_id'] );
	if ( ! is_email( $m['parent_email'] ) ) {
		return false;
	}
	$acct = (int) get_option( 'cs_account_page', 0 );
	$body = 'Hi ' . ( $m['parent_name'] ?: 'there' ) . ",\n\n"
		. $m['child_first'] . ' is signed up for ' . $plan['title'] . ' subs: ' . cs_money( $plan['amount'] ) . ' on the ' . $plan['day_of_month'] . ' of each month by Direct Debit.' . "\n\n"
		. 'GoCardless will email you before each collection. It appears on your bank statement as ' . cs_club_name() . ".\n\n"
		. ( $acct ? 'Manage or cancel it any time: ' . get_permalink( $acct ) . "\n\n" : '' )
		. cs_club_name();
	return cs_mail( $m['parent_email'], 'Subs set up for ' . $m['child_first'], $body );
}
