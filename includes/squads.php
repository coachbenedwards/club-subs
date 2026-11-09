<?php
/**
 * Grassroots squads: [club_subs_squads]
 *
 * Coaches sign in with an emailed link (no password), name their team, list
 * their coaches and add their players one at a time. Each player is saved the
 * moment the coach presses Save, as a member record with the team on it.
 *
 * The parent email on each player is what joins a child to a Direct Debit:
 * the chase list looks players up by the GoCardless payer email, and a member
 * imported from GoCardless with no child name yet is filled in by the first
 * player a coach adds under that parent email.
 *
 * Coaches see only their own team. They never see payment status.
 *
 * Every word the block shows is an attribute on the shortcode (defaults in
 * cs_squads_defaults), so the wording is edited on the page, not in code.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function () {
	register_post_type( 'cs_team', array(
		'labels'       => array( 'name' => 'Squads', 'singular_name' => 'Squad', 'menu_name' => 'Squads', 'all_items' => 'Squads' ),
		'public'       => false,
		'show_ui'      => true,
		'show_in_menu' => 'club-subs',
		'supports'     => array( 'title' ),
		'map_meta_cap' => true,
	) );
} );

function cs_age_groups() {
	$out = array();
	for ( $i = 5; $i <= 18; $i++ ) {
		$out[] = 'Under ' . $i;
	}
	return $out;
}

function cs_squads_defaults() {
	return array(
		'title'          => 'SQUAD LISTS',
		'lede'           => 'For grassroots coaches. Add your team, your coaches and every player, so the club has your whole squad in one place.',
		'signin_title'   => 'COACH SIGN IN',
		'email'          => 'Your email',
		'signin_button'  => 'EMAIL ME A SIGN-IN LINK',
		'signin_note'    => 'We email you a link that signs you in. No password.',
		'fan_note'       => 'Sign in with your Eastwood FC account, the same one as the app and My tickets.',
		'password'       => 'Password',
		'signin_fan'     => 'SIGN IN',
		'create'         => 'Create an account',
		'forgot'         => 'Forgotten your password?',
		'verify_title'   => 'CHECK IT IS YOU',
		'verify_line'    => 'The first time you open squad lists we email {email} a link, to make sure it is really you. After that you go straight in.',
		'verify_button'  => 'EMAIL ME THE LINK',
		'done'           => 'Done',
		'sent'           => 'Check your email. The link works for an hour.',
		'expired'        => 'That link has expired or was already used. Ask for a new one.',
		'mail_subject'   => 'Your {club} squad list link',
		'mail_body'      => "Hi,\n\nHere is your link for the {club} squad lists. It works for an hour:\n\n{link}\n\n{club}",
		'signed_in'      => 'Signed in as {email}',
		'sign_out'       => 'Sign out',
		'team_title'     => 'YOUR TEAM',
		'team_box'       => 'THE TEAM',
		'team_name'      => 'Team name',
		'age_group'      => 'Age group',
		'choose'         => 'Choose',
		'coaches_box'    => 'THE COACHES',
		'coach_name'     => 'Coach name',
		'coach_email'    => 'Email',
		'coach_mobile'   => 'Mobile',
		'add_coach'      => 'Add another coach',
		'team_next'      => 'NEXT: YOUR PLAYERS',
		'team_save'      => 'SAVE TEAM',
		'edit_team'      => 'Edit team and coaches',
		'other_teams'    => 'YOUR OTHER TEAMS',
		'add_team'       => 'Add another team',
		'new_team_title' => 'ANOTHER TEAM',
		'players_title'  => 'YOUR PLAYERS',
		'players_lede'   => 'Every player is saved as soon as you add them',
		'squad_box'      => 'THE SQUAD',
		'count'          => '{n} players · all saved',
		'count_one'      => '1 player · all saved',
		'empty'          => 'No players yet. Add your first one.',
		'tap_hint'       => 'Click a player to change or remove them.',
		'add_title'      => 'ADD A PLAYER',
		'edit_title'     => 'CHANGE PLAYER',
		'add_button'     => 'ADD A PLAYER',
		'first'          => 'First name',
		'last'           => 'Last name',
		'dob'            => 'Date of birth',
		'dob_hint'       => 'DD/MM/YYYY',
		'parent'         => 'Parent or carer',
		'parent_email'   => 'Parent email',
		'parent_email_hint' => 'The parent or carer who pays the subs.',
		'parent_mobile'  => 'Parent mobile',
		'save'           => 'SAVE PLAYER',
		'remove'         => 'Remove from the squad',
		'remove_sure'    => 'Press again to remove {name}',
		'back'           => 'Back to the squad',
		'saved'          => '{name} saved to the squad',
		'removed'        => '{name} removed',
		'just_added'     => 'just added',
		'born'           => 'Born',
		'need'           => 'Fill in the first name, last name and date of birth.',
		'bad_date'       => 'Write the date of birth as DD/MM/YYYY.',
		'error'          => 'That did not save. Check your signal and try again.',
	);
}

/* ---------------- Who is signed in ---------------- */

function cs_coach_sig( $email, $exp ) {
	return hash_hmac( 'sha256', strtolower( $email ) . '|' . $exp, wp_salt( 'auth' ) . 'cs_coach' );
}

/** The signed-in coach email, or ''. */
function cs_coach_email() {
	$raw = isset( $_COOKIE['cs_coach'] ) ? (string) wp_unslash( $_COOKIE['cs_coach'] ) : '';
	$p   = explode( '.', $raw );
	if ( 3 !== count( $p ) ) {
		return '';
	}
	$email = base64_decode( strtr( $p[0], '-_', '+/' ), true );
	$exp   = (int) $p[1];
	if ( ! $email || $exp < time() || ! hash_equals( cs_coach_sig( $email, $exp ), $p[2] ) ) {
		return '';
	}
	return strtolower( $email );
}

function cs_coach_set_cookie( $email ) {
	$exp = time() + 30 * DAY_IN_SECONDS;
	$val = rtrim( strtr( base64_encode( strtolower( $email ) ), '+/', '-_' ), '=' ) . '.' . $exp . '.' . cs_coach_sig( $email, $exp );
	setcookie( 'cs_coach', $val, array( 'expires' => $exp, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
}

/* ---------------- Eastwood FC account (the same sign-in as the app and My tickets) ---------------- */

/** True when the site runs the Eastwood FC supporter account; otherwise coaches sign in by emailed link. */
function cs_fan_mode() {
	return defined( 'EW_FAN_SUPABASE' ) && defined( 'EW_FAN_ANON' );
}

/** The account a bearer token belongs to: { sub, email } or null. Asked of the account service itself. */
function cs_fan_user( WP_REST_Request $r ) {
	$a = (string) $r->get_header( 'authorization' );
	$t = 0 === stripos( $a, 'Bearer ' ) ? trim( substr( $a, 7 ) ) : '';
	if ( '' === $t || strlen( $t ) > 8192 || ! cs_fan_mode() ) {
		return null;
	}
	$ck  = 'cs_fan_' . md5( $t );
	$hit = get_transient( $ck );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	$res = wp_remote_get( EW_FAN_SUPABASE . '/auth/v1/user', array( 'timeout' => 8, 'headers' => array( 'apikey' => EW_FAN_ANON, 'Authorization' => 'Bearer ' . $t ) ) );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return null;
	}
	$u = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( empty( $u['id'] ) || empty( $u['email'] ) ) {
		return null;
	}
	$fan = array( 'sub' => sanitize_key( $u['id'] ), 'email' => strtolower( trim( $u['email'] ) ) );
	set_transient( $ck, $fan, 5 * MINUTE_IN_SECONDS );
	return $fan;
}

/**
 * An account's email is only trusted for squads once the coach has opened a
 * link sent to it from here: a password account may never have proved its
 * email, and a team's players are children's details.
 */
function cs_fan_verified( $fan ) {
	$v = (array) get_option( 'cs_coach_verified', array() );
	return $fan && isset( $v[ $fan['email'] ] ) && $v[ $fan['email'] ] === $fan['sub'];
}

/* ---------------- Teams and players ---------------- */

function cs_team_emails( $team_id ) {
	return array_values( array_filter( explode( ',', (string) get_post_meta( $team_id, '_cs_coach_emails', true ) ) ) );
}

function cs_coach_can( $email, $team_id ) {
	return $email && 'cs_team' === get_post_type( $team_id ) && in_array( $email, cs_team_emails( $team_id ), true );
}

function cs_coach_teams( $email ) {
	if ( ! $email ) {
		return array();
	}
	return get_posts( array(
		'post_type' => 'cs_team', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC',
		'meta_query' => array( array( 'key' => '_cs_coach_emails', 'value' => ',' . $email . ',', 'compare' => 'LIKE' ) ),
	) );
}

function cs_team_players( $team_id ) {
	return get_posts( array(
		'post_type' => 'cs_member', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC',
		'meta_key' => '_cs_team_id', 'meta_value' => (string) (int) $team_id,
	) );
}

function cs_player_out( $mid ) {
	$m = cs_member( $mid );
	return array(
		'id'     => (int) $mid,
		'first'  => $m['child_first'],
		'last'   => $m['child_last'],
		'dob'    => $m['child_dob'] ? date( 'd/m/Y', strtotime( $m['child_dob'] ) ) : '',
		'parent' => $m['parent_name'],
		'email'  => $m['parent_email'],
		'mobile' => $m['parent_phone'],
	);
}

function cs_team_out( $tid ) {
	return array(
		'id'      => (int) $tid,
		'name'    => get_the_title( $tid ),
		'age'     => (string) get_post_meta( $tid, '_cs_age', true ),
		'coaches' => (array) json_decode( (string) get_post_meta( $tid, '_cs_coaches', true ), true ),
		'players' => array_map( 'cs_player_out', cs_team_players( $tid ) ),
	);
}

/** Children coaches have listed under a payer email, as "Name (Team)". */
function cs_players_for_payer( $email ) {
	$email = strtolower( trim( (string) $email ) );
	if ( '' === $email ) {
		return array( 'children' => '', 'team' => '', 'phone' => '' );
	}
	$ids = get_posts( array(
		'post_type' => 'cs_member', 'post_status' => 'publish', 'numberposts' => 20, 'fields' => 'ids',
		'meta_query' => array( array( 'key' => '_cs_parent_email', 'value' => $email ), array( 'key' => '_cs_team_id', 'compare' => 'EXISTS' ) ),
	) );
	$names = array(); $teams = array(); $phone = '';
	foreach ( $ids as $id ) {
		$m = cs_member( $id );
		$t = get_the_title( (int) get_post_meta( $id, '_cs_team_id', true ) );
		$names[] = trim( $m['child_first'] . ' ' . $m['child_last'] );
		$teams[ $t ] = $t;
		$phone = $phone ?: $m['parent_phone'];
	}
	return array( 'children' => implode( ', ', $names ), 'team' => implode( ', ', $teams ), 'phone' => $phone );
}

/* ---------------- The block ---------------- */

add_shortcode( 'club_subs_squads', function ( $atts ) {
	$a = shortcode_atts( cs_squads_defaults(), (array) $atts, 'club_subs_squads' );
	wp_enqueue_style( 'club-subs-squads', cs_asset_url( 'assets/squads.css' ), array(), null );
	wp_enqueue_script( 'club-subs-squads', cs_asset_url( 'assets/squads.js' ), cs_fan_mode() ? array( 'ew-supabase' ) : array(), null, true );
	$fan = cs_fan_mode();
	if ( $fan ) {
		wp_enqueue_script( 'ew-supabase', 'https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2.45.4/dist/umd/supabase.min.js', array(), null, true ); // phpcs:ignore
	}
	$cfg = array(
		'fan'   => $fan ? array( 'supabase' => EW_FAN_SUPABASE, 'anon' => EW_FAN_ANON, 'account' => home_url( '/my-tickets/' ) ) : null,
		'rest'  => esc_url_raw( rest_url( 'club-subs/v1/coach/' ) ),
		'page'  => get_the_ID(),
		'ages'  => cs_age_groups(),
		'words' => $a,
	);
	return '<div class="cs-sq" data-cs-squads="' . esc_attr( wp_json_encode( $cfg ) ) . '"><noscript>' . esc_html( $a['title'] ) . '</noscript></div>';
} );

add_filter( 'ew_blocks_allowed', function ( $list ) {
	return array_merge( (array) $list, array( 'club_subs_squads' ) );
}, 20 );

/* ---------------- REST ---------------- */

add_action( 'rest_api_init', function () {
	$open = array( 'permission_callback' => '__return_true' );
	register_rest_route( 'club-subs/v1', '/coach/link', $open + array( 'methods' => 'POST', 'callback' => 'cs_rest_coach_link' ) );
	register_rest_route( 'club-subs/v1', '/coach/enter', $open + array( 'methods' => 'GET', 'callback' => 'cs_rest_coach_enter' ) );
	register_rest_route( 'club-subs/v1', '/coach/me', $open + array( 'methods' => 'GET', 'callback' => 'cs_rest_coach_me' ) );
	register_rest_route( 'club-subs/v1', '/coach/out', $open + array( 'methods' => 'POST', 'callback' => 'cs_rest_coach_out' ) );
	register_rest_route( 'club-subs/v1', '/coach/team', $open + array( 'methods' => 'POST', 'callback' => 'cs_rest_coach_team' ) );
	register_rest_route( 'club-subs/v1', '/coach/player', $open + array( 'methods' => 'POST', 'callback' => 'cs_rest_coach_player' ) );
	register_rest_route( 'club-subs/v1', '/coach/player/remove', $open + array( 'methods' => 'POST', 'callback' => 'cs_rest_coach_remove' ) );
} );

/* Squad answers are personal to the signed-in coach: never cached anywhere. */
add_filter( 'rest_post_dispatch', function ( $res, $server, $req ) {
	if ( 0 === strpos( $req->get_route(), '/club-subs/v1/coach/' ) && $res instanceof WP_REST_Response ) {
		$res->header( 'Cache-Control', 'no-store, private' );
	}
	return $res;
}, 10, 3 );

/**
 * Calls that read or change a squad must carry the X-CS-Coach header. A form
 * on another site cannot set it, so the cookie alone cannot be used against a
 * signed-in coach.
 */
function cs_coach_request( WP_REST_Request $r ) {
	if ( '1' !== (string) $r->get_header( 'x_cs_coach' ) ) {
		return new WP_Error( 'cs_coach_header', 'Missing header.', array( 'status' => 400 ) );
	}
	if ( cs_fan_mode() ) {
		$fan = cs_fan_user( $r );
		if ( ! $fan ) {
			return new WP_Error( 'cs_coach_signin', 'Sign in first.', array( 'status' => 401 ) );
		}
		if ( ! cs_fan_verified( $fan ) ) {
			return new WP_Error( 'cs_coach_verify', $fan['email'], array( 'status' => 403 ) );
		}
		return $fan['email'];
	}
	$email = cs_coach_email();
	if ( ! $email ) {
		return new WP_Error( 'cs_coach_signin', 'Sign in first.', array( 'status' => 401 ) );
	}
	return $email;
}

function cs_rest_coach_link( WP_REST_Request $r ) {
	$email = strtolower( sanitize_email( (string) $r->get_param( 'email' ) ) );
	$page  = (int) $r->get_param( 'page' );
	$sub   = '';
	if ( cs_fan_mode() ) {
		$fan = cs_fan_user( $r );
		if ( ! $fan ) {
			return new WP_Error( 'cs_coach_signin', 'Sign in first.', array( 'status' => 401 ) );
		}
		$email = $fan['email'];
		$sub   = $fan['sub'];
	}
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'cs_email', 'Enter an email.', array( 'status' => 400 ) );
	}
	if ( get_transient( 'cs_coach_wait_' . md5( $email ) ) ) {
		return array( 'ok' => true ); // one email a minute per address
	}
	set_transient( 'cs_coach_wait_' . md5( $email ), 1, MINUTE_IN_SECONDS );
	$token = wp_generate_password( 40, false );
	set_transient( 'cs_coach_t_' . $token, array( 'email' => $email, 'page' => $page, 'sub' => $sub ), HOUR_IN_SECONDS );
	$w    = array_merge( cs_squads_defaults(), array_intersect_key( (array) $r->get_param( 'words' ), array( 'mail_subject' => 1, 'mail_body' => 1 ) ) );
	$link = add_query_arg( 't', $token, rest_url( 'club-subs/v1/coach/enter' ) );
	$vars = array( '{club}' => cs_club_name(), '{link}' => $link );
	cs_mail( $email, strtr( $w['mail_subject'], $vars ), strtr( $w['mail_body'], $vars ) );
	cs_log( 'coach link sent', array( 'email' => $email ) );
	return array( 'ok' => true );
}

function cs_rest_coach_enter( WP_REST_Request $r ) {
	$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $r->get_param( 't' ) );
	$t     = $token ? get_transient( 'cs_coach_t_' . $token ) : false;
	$page  = is_array( $t ) && ! empty( $t['page'] ) ? get_permalink( (int) $t['page'] ) : '';
	$dest  = $page ?: home_url( '/squads/' );
	if ( is_array( $t ) && ! empty( $t['email'] ) ) {
		delete_transient( 'cs_coach_t_' . $token );
		cs_coach_set_cookie( $t['email'] );
		if ( ! empty( $t['sub'] ) ) {
			$v = (array) get_option( 'cs_coach_verified', array() );
			$v[ $t['email'] ] = $t['sub'];
			update_option( 'cs_coach_verified', $v, false );
		}
		$dest = add_query_arg( 'signed', '1', $dest );
	} else {
		$dest = add_query_arg( 'expired', '1', $dest );
	}
	nocache_headers();
	wp_safe_redirect( $dest );
	exit;
}

function cs_rest_coach_out( WP_REST_Request $r ) {
	setcookie( 'cs_coach', '', array( 'expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
	return array( 'ok' => true );
}

function cs_rest_coach_me( WP_REST_Request $r ) {
	$email = cs_coach_request( $r );
	if ( is_wp_error( $email ) ) {
		return $email;
	}
	return array( 'email' => $email, 'teams' => array_map( 'cs_team_out', cs_coach_teams( $email ) ) );
}

function cs_rest_coach_team( WP_REST_Request $r ) {
	$email = cs_coach_request( $r );
	if ( is_wp_error( $email ) ) {
		return $email;
	}
	$id   = (int) $r->get_param( 'id' );
	$name = sanitize_text_field( (string) $r->get_param( 'name' ) );
	$age  = sanitize_text_field( (string) $r->get_param( 'age' ) );
	if ( '' === $name ) {
		return new WP_Error( 'cs_team_name', 'Give the team a name.', array( 'status' => 400 ) );
	}
	if ( $id && ! cs_coach_can( $email, $id ) ) {
		return new WP_Error( 'cs_team_access', 'Not your team.', array( 'status' => 403 ) );
	}
	$coaches = array();
	$emails  = array( $email );
	foreach ( (array) $r->get_param( 'coaches' ) as $c ) {
		$c = array(
			'name'   => sanitize_text_field( (string) ( $c['name'] ?? '' ) ),
			'email'  => strtolower( sanitize_email( (string) ( $c['email'] ?? '' ) ) ),
			'mobile' => sanitize_text_field( (string) ( $c['mobile'] ?? '' ) ),
		);
		if ( '' === $c['name'] && '' === $c['email'] && '' === $c['mobile'] ) {
			continue;
		}
		$coaches[] = $c;
		if ( is_email( $c['email'] ) ) {
			$emails[] = $c['email'];
		}
	}
	if ( ! $id ) {
		$id = wp_insert_post( array( 'post_type' => 'cs_team', 'post_status' => 'publish', 'post_title' => $name ) );
		if ( ! $id || is_wp_error( $id ) ) {
			return new WP_Error( 'cs_team_save', 'Could not save.', array( 'status' => 500 ) );
		}
		cs_notify_club( 'New squad: ' . $name, $name . ( $age ? ' (' . $age . ')' : '' ) . ' was added by ' . $email . '.' );
	} else {
		wp_update_post( array( 'ID' => $id, 'post_title' => $name ) );
	}
	update_post_meta( $id, '_cs_age', $age );
	update_post_meta( $id, '_cs_coaches', wp_json_encode( $coaches ) );
	update_post_meta( $id, '_cs_coach_emails', ',' . implode( ',', array_unique( $emails ) ) . ',' );
	cs_log( 'squad saved', array( 'team' => $id, 'by' => $email ) );
	return cs_team_out( $id );
}

/** DD/MM/YYYY (or YYYY-MM-DD) → Y-m-d, or ''. */
function cs_parse_dob( $s ) {
	$s = trim( (string) $s );
	if ( preg_match( '#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $s, $m ) && checkdate( (int) $m[2], (int) $m[1], (int) $m[3] ) ) {
		return sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] );
	}
	if ( preg_match( '#^(\d{4})-(\d{2})-(\d{2})$#', $s, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
		return $s;
	}
	return '';
}

function cs_rest_coach_player( WP_REST_Request $r ) {
	$email = cs_coach_request( $r );
	if ( is_wp_error( $email ) ) {
		return $email;
	}
	$team = (int) $r->get_param( 'team' );
	$id   = (int) $r->get_param( 'id' );
	if ( ! cs_coach_can( $email, $team ) ) {
		return new WP_Error( 'cs_team_access', 'Not your team.', array( 'status' => 403 ) );
	}
	if ( $id && (int) get_post_meta( $id, '_cs_team_id', true ) !== $team ) {
		return new WP_Error( 'cs_player_access', 'Not in your team.', array( 'status' => 403 ) );
	}
	$f = array(
		'child_first'  => sanitize_text_field( (string) $r->get_param( 'first' ) ),
		'child_last'   => sanitize_text_field( (string) $r->get_param( 'last' ) ),
		'child_dob'    => cs_parse_dob( $r->get_param( 'dob' ) ),
		'parent_name'  => sanitize_text_field( (string) $r->get_param( 'parent' ) ),
		'parent_email' => strtolower( sanitize_email( (string) $r->get_param( 'email' ) ) ),
		'parent_phone' => sanitize_text_field( (string) $r->get_param( 'mobile' ) ),
	);
	if ( '' === $f['child_first'] || '' === $f['child_last'] || '' === $f['child_dob'] ) {
		return new WP_Error( 'cs_player_need', 'need', array( 'status' => 400 ) );
	}
	if ( ! $id && is_email( $f['parent_email'] ) ) {
		// A member brought in from GoCardless with no child name yet: fill it rather than add a second record.
		$imp = get_posts( array(
			'post_type' => 'cs_member', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids',
			'meta_query' => array(
				array( 'key' => '_cs_parent_email', 'value' => $f['parent_email'] ),
				array( 'key' => '_cs_child_last', 'value' => '(name the child)' ),
				array( 'key' => '_cs_team_id', 'compare' => 'NOT EXISTS' ),
			),
		) );
		$id = $imp ? (int) $imp[0] : 0;
	}
	if ( ! $id ) {
		$id = wp_insert_post( array( 'post_type' => 'cs_member', 'post_status' => 'publish', 'post_title' => $f['child_first'] . ' ' . $f['child_last'] ) );
		if ( ! $id || is_wp_error( $id ) ) {
			return new WP_Error( 'cs_player_save', 'Could not save.', array( 'status' => 500 ) );
		}
		$f['status'] = 'squad';
		$f['notes']  = 'Added to ' . get_the_title( $team ) . ' by ' . $email . ' on ' . current_time( 'Y-m-d' );
	}
	cs_member_set( $id, $f );
	update_post_meta( $id, '_cs_team_id', (string) $team );
	delete_transient( 'cs_chase_' . cs_gc_env() . '_' . current_time( 'Ym' ) );
	return cs_player_out( $id );
}

function cs_rest_coach_remove( WP_REST_Request $r ) {
	$email = cs_coach_request( $r );
	if ( is_wp_error( $email ) ) {
		return $email;
	}
	$id   = (int) $r->get_param( 'id' );
	$team = (int) get_post_meta( $id, '_cs_team_id', true );
	if ( ! $id || ! cs_coach_can( $email, $team ) ) {
		return new WP_Error( 'cs_player_access', 'Not in your team.', array( 'status' => 403 ) );
	}
	delete_post_meta( $id, '_cs_team_id' );
	if ( '' === (string) get_post_meta( $id, '_cs_gc_mandate', true ) ) {
		wp_trash_post( $id ); // never paid through us: nothing to keep
	}
	cs_log( 'player removed from squad', array( 'member' => $id, 'team' => $team, 'by' => $email ) );
	return array( 'ok' => true );
}

/* ---------------- Admin: Squads list ---------------- */

add_filter( 'manage_cs_team_posts_columns', function () {
	return array( 'cb' => '<input type="checkbox">', 'title' => 'Team', 'age' => 'Age group', 'coaches' => 'Coaches', 'players' => 'Players' );
} );
add_action( 'manage_cs_team_posts_custom_column', function ( $col, $id ) {
	switch ( $col ) {
		case 'age': echo esc_html( get_post_meta( $id, '_cs_age', true ) ); break;
		case 'coaches':
			foreach ( (array) json_decode( (string) get_post_meta( $id, '_cs_coaches', true ), true ) as $c ) {
				echo esc_html( trim( ( $c['name'] ?? '' ) . ' ' . ( $c['mobile'] ?? '' ) ) ) . '<br>';
			}
			break;
		case 'players': echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=cs_member&cs_team=' . (int) $id ) ) . '">' . count( cs_team_players( $id ) ) . '</a>'; break;
	}
}, 10, 2 );

/* Members list filtered by team from the Squads screen. */
add_action( 'pre_get_posts', function ( $q ) {
	if ( is_admin() && $q->is_main_query() && 'cs_member' === $q->get( 'post_type' ) && ! empty( $_GET['cs_team'] ) ) {
		$q->set( 'meta_key', '_cs_team_id' );
		$q->set( 'meta_value', (string) (int) $_GET['cs_team'] );
	}
} );
