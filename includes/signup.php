<?php
/**
 * The sign-up block: [club_subs_signup]
 *
 * A parent picks the team, fills in the child and their own details, and is
 * sent to GoCardless' hosted page to set up the Direct Debit. GoCardless
 * sends them back here; the mandate arrives by webhook and the subscription
 * is created then (see subscriptions.php).
 *
 * Every word on the block is an attribute, so the club can change the wording
 * in the Elementor shortcode widget without touching code.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cs_signup_defaults() {
	return array(
		'title'        => 'Sign up for subs',
		'intro'        => 'Pick your child\'s team, add your details, and set up the Direct Debit on the next page. It takes about two minutes.',
		'plan_label'   => 'Team',
		'plan_pick'    => 'Choose a team',
		'per_month'    => 'a month',
		'child_label'  => 'Child\'s name',
		'child_first'  => 'First name',
		'child_last'   => 'Last name',
		'dob_label'    => 'Child\'s date of birth',
		'parent_label' => 'Your details',
		'parent_name'  => 'Your full name',
		'email'        => 'Email',
		'phone'        => 'Mobile',
		'consent'      => 'I\'m the parent or guardian and I\'m happy for the club to contact me about subs and training.',
		'button'       => 'Continue to set up Direct Debit',
		'loading'      => 'One moment…',
		'need'         => 'Please fill in everything marked.',
		'failed'       => 'That didn\'t go through. Please try again or contact the club.',
		'done_title'   => 'All set.',
		'done_text'    => 'Your Direct Debit is set up. GoCardless will email you a confirmation, and the club will take the first collection on the date shown. You can manage it any time from the account page.',
		'exit_text'    => 'The Direct Debit wasn\'t completed. Nothing has been set up. You can start again below.',
		'closed'       => 'Sign-up is closed at the moment. Please contact the club.',
		'first_note'   => 'A one-off {amount} is taken when you set up the Direct Debit.',
		'next_note'    => 'Then {amount} on the {day} of each month, {count} collections this season.',
		'account_link' => 'Manage an existing Direct Debit',
	);
}

add_shortcode( 'club_subs_signup', function ( $atts ) {
	$a = shortcode_atts( cs_signup_defaults(), (array) $atts, 'club_subs_signup' );
	wp_enqueue_style( 'club-subs' );
	wp_enqueue_script( 'club-subs' );
	wp_localize_script( 'club-subs', 'CS_SUBS', array(
		'rest'  => esc_url_raw( rest_url( 'club-subs/v1/' ) ),
		'nonce' => wp_create_nonce( 'wp_rest' ),
		'page'  => get_the_ID(),
		'words' => array( 'loading' => $a['loading'], 'need' => $a['need'], 'failed' => $a['failed'], 'first_note' => $a['first_note'], 'next_note' => $a['next_note'], 'per_month' => $a['per_month'] ),
	) );

	// Back from GoCardless.
	if ( isset( $_GET['cs_return'] ) ) {
		$state = sanitize_text_field( wp_unslash( $_GET['cs_return'] ) );
		if ( 'done' === $state ) {
			$html = '<div class="cs cs-done"><h2 class="cs-h">' . esc_html( $a['done_title'] ) . '</h2><p>' . esc_html( $a['done_text'] ) . '</p>';
			$acct = (int) get_option( 'cs_account_page', 0 );
			if ( $acct ) {
				$html .= '<p><a class="cs-link" href="' . esc_url( get_permalink( $acct ) ) . '">' . esc_html( $a['account_link'] ) . '</a></p>';
			}
			return $html . '</div>';
		}
		$exit = '<p class="cs-exit">' . esc_html( $a['exit_text'] ) . '</p>';
	} else {
		$exit = '';
	}

	$plans = cs_plans( true );
	if ( ! $plans ) {
		return '<div class="cs"><p>' . esc_html( $a['closed'] ) . '</p></div>';
	}
	ob_start();
	?>
	<div class="cs cs-signup" data-cs-signup>
		<h2 class="cs-h"><?php echo esc_html( $a['title'] ); ?></h2>
		<p class="cs-intro"><?php echo esc_html( $a['intro'] ); ?></p>
		<?php echo $exit; // phpcs:ignore -- built above from escaped text ?>
		<form class="cs-form" novalidate>
			<label class="cs-l" for="cs_plan"><?php echo esc_html( $a['plan_label'] ); ?></label>
			<select id="cs_plan" name="plan" required>
				<option value=""><?php echo esc_html( $a['plan_pick'] ); ?></option>
				<?php foreach ( $plans as $p ) : ?>
					<option value="<?php echo (int) $p['id']; ?>" data-amount="<?php echo (int) $p['amount']; ?>" data-day="<?php echo (int) $p['day_of_month']; ?>" data-count="<?php echo (int) cs_plan_collections_left( $p ); ?>" data-first="<?php echo (int) $p['first_payment']; ?>">
						<?php echo esc_html( $p['title'] . ' — ' . cs_money( $p['amount'] ) . ' ' . $a['per_month'] ); ?><?php echo $p['description'] ? esc_html( ' · ' . $p['description'] ) : ''; ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="cs-note" data-cs-note hidden></p>

			<fieldset class="cs-fs">
				<legend class="cs-l"><?php echo esc_html( $a['child_label'] ); ?></legend>
				<div class="cs-row">
					<input type="text" id="cs_child_first" name="child_first" placeholder="<?php echo esc_attr( $a['child_first'] ); ?>" autocomplete="off" required>
					<input type="text" id="cs_child_last" name="child_last" placeholder="<?php echo esc_attr( $a['child_last'] ); ?>" autocomplete="off" required>
				</div>
				<label class="cs-l cs-small" for="cs_dob"><?php echo esc_html( $a['dob_label'] ); ?></label>
				<input type="date" id="cs_dob" name="child_dob">
			</fieldset>

			<fieldset class="cs-fs">
				<legend class="cs-l"><?php echo esc_html( $a['parent_label'] ); ?></legend>
				<input type="text" id="cs_parent_name" name="parent_name" placeholder="<?php echo esc_attr( $a['parent_name'] ); ?>" autocomplete="name" required>
				<div class="cs-row">
					<input type="email" id="cs_email" name="email" placeholder="<?php echo esc_attr( $a['email'] ); ?>" autocomplete="email" required>
					<input type="tel" id="cs_phone" name="phone" placeholder="<?php echo esc_attr( $a['phone'] ); ?>" autocomplete="tel">
				</div>
				<label class="cs-check"><input type="checkbox" id="cs_consent" name="consent" value="1" required> <span><?php echo esc_html( $a['consent'] ); ?></span></label>
			</fieldset>

			<p class="cs-err" data-cs-err hidden></p>
			<button type="submit" class="cs-btn"><?php echo esc_html( $a['button'] ); ?></button>
		</form>
	</div>
	<?php
	return ob_get_clean();
} );

/* ---------------- REST: start a sign-up ---------------- */

add_action( 'rest_api_init', function () {
	register_rest_route( 'club-subs/v1', '/signup', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => 'cs_rest_signup',
	) );
} );

function cs_rest_signup( WP_REST_Request $r ) {
	$plan_id = (int) $r->get_param( 'plan' );
	$plan    = $plan_id ? cs_plan( $plan_id ) : null;
	if ( ! $plan || '1' !== $plan['open'] || $plan['amount'] < 100 ) {
		return new WP_Error( 'cs_plan', 'Pick a team.', array( 'status' => 400 ) );
	}
	$first = sanitize_text_field( (string) $r->get_param( 'child_first' ) );
	$last  = sanitize_text_field( (string) $r->get_param( 'child_last' ) );
	$dob   = sanitize_text_field( (string) $r->get_param( 'child_dob' ) );
	$pname = sanitize_text_field( (string) $r->get_param( 'parent_name' ) );
	$email = sanitize_email( (string) $r->get_param( 'email' ) );
	$phone = sanitize_text_field( (string) $r->get_param( 'phone' ) );
	$page  = (int) $r->get_param( 'page' );
	if ( ! $first || ! $last || ! $pname || ! is_email( $email ) || ! $r->get_param( 'consent' ) ) {
		return new WP_Error( 'cs_need', 'Missing details.', array( 'status' => 400 ) );
	}

	// The member record, pending until the mandate lands.
	$member = wp_insert_post( array( 'post_type' => 'cs_member', 'post_status' => 'publish', 'post_title' => $first . ' ' . $last ) );
	if ( ! $member || is_wp_error( $member ) ) {
		return new WP_Error( 'cs_db', 'Could not save.', array( 'status' => 500 ) );
	}
	$key = wp_generate_password( 24, false );
	cs_member_set( $member, array(
		'child_first' => $first, 'child_last' => $last, 'child_dob' => $dob, 'plan_id' => $plan_id,
		'parent_name' => $pname, 'parent_email' => $email, 'parent_phone' => $phone,
		'status' => 'pending', 'started' => current_time( 'mysql' ),
	) );
	update_post_meta( $member, '_cs_key', $key );
	update_post_meta( $member, '_cs_consent', '1' );

	$names  = preg_split( '/\s+/', trim( $pname ), 2 );
	$given  = $names[0];
	$family = isset( $names[1] ) ? $names[1] : '';

	// 1. Billing request: a Bacs mandate, plus an optional one-off taken with it.
	$br = array(
		'mandate_request' => array( 'scheme' => 'bacs', 'currency' => 'GBP', 'description' => cs_club_name() . ' subs' ),
		'metadata'        => array( 'member_id' => (string) $member, 'plan_id' => (string) $plan_id, 'site' => home_url() ),
	);
	if ( $plan['first_payment'] >= 100 ) {
		$br['payment_request'] = array( 'amount' => $plan['first_payment'], 'currency' => 'GBP', 'description' => $plan['title'] . ' — registration', 'metadata' => array( 'member_id' => (string) $member ) );
	}
	$res = cs_gc( 'POST', '/billing_requests', array( 'billing_requests' => $br ) );
	if ( is_wp_error( $res ) ) {
		cs_member_set( $member, array( 'status' => 'failed', 'notes' => 'Billing request failed: ' . $res->get_error_message() ) );
		return new WP_Error( 'cs_gc', 'Could not start the Direct Debit.', array( 'status' => 502 ) );
	}
	$br_id = $res['billing_requests']['id'];
	cs_member_set( $member, array( 'gc_billing_request' => $br_id ) );

	// 2. The hosted flow GoCardless shows the parent.
	$back = $page ? get_permalink( $page ) : home_url( '/' );
	$flow = cs_gc( 'POST', '/billing_request_flows', array( 'billing_request_flows' => array(
		'redirect_uri'       => add_query_arg( array( 'cs_return' => 'done', 'm' => $member ), $back ),
		'exit_uri'           => add_query_arg( array( 'cs_return' => 'exit' ), $back ),
		'links'              => array( 'billing_request' => $br_id ),
		'prefilled_customer' => array_filter( array( 'given_name' => $given, 'family_name' => $family, 'email' => $email, 'phone_number' => $phone ) ),
		'lock_currency'      => true,
		'show_redirect_buttons' => true,
	) ) );
	if ( is_wp_error( $flow ) ) {
		cs_member_set( $member, array( 'status' => 'failed', 'notes' => 'Flow failed: ' . $flow->get_error_message() ) );
		return new WP_Error( 'cs_gc', 'Could not start the Direct Debit.', array( 'status' => 502 ) );
	}
	cs_log( 'signup started', array( 'member' => $member, 'plan' => $plan['title'], 'br' => $br_id ) );
	return array( 'url' => $flow['billing_request_flows']['authorisation_url'], 'member' => $member );
}
