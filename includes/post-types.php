<?php
/**
 * The records.
 *
 *  cs_plan     A thing people pay for: "Under 9s", £30 on the 1st, 10 months a year.
 *  cs_member   The child (or adult member) on a plan. Carries the parent's
 *              details, the GoCardless customer/mandate/subscription ids and
 *              the current status.
 *  cs_payment  One collection GoCardless told us about, good or bad. The
 *              who's-paid report is built from these.
 *
 * Member status: pending (sign-up started, no mandate yet) → active →
 * cancelled | failed. 'active' means a live subscription exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cs_register_post_types() {
	register_post_type( 'cs_plan', array(
		'labels'       => array( 'name' => 'Plans', 'singular_name' => 'Plan', 'add_new_item' => 'Add plan', 'edit_item' => 'Edit plan' ),
		'public'       => false,
		'show_ui'      => true,
		'show_in_menu' => 'club-subs',
		'supports'     => array( 'title' ),
		'menu_position' => 30,
	) );
	register_post_type( 'cs_member', array(
		'labels'       => array( 'name' => 'Members', 'singular_name' => 'Member', 'add_new_item' => 'Add member', 'edit_item' => 'Edit member' ),
		'public'       => false,
		'show_ui'      => true,
		'show_in_menu' => 'club-subs',
		'supports'     => array( 'title' ),
	) );
	register_post_type( 'cs_payment', array(
		'labels'       => array( 'name' => 'Payments', 'singular_name' => 'Payment' ),
		'public'       => false,
		'show_ui'      => true,
		'show_in_menu' => 'club-subs',
		'supports'     => array( 'title' ),
		'capabilities' => array( 'create_posts' => 'do_not_allow' ),
		'map_meta_cap' => true,
	) );
}
add_action( 'init', 'cs_register_post_types' );

/** Top-level menu. The CPTs and the settings/report pages hang off it. */
add_action( 'admin_menu', function () {
	add_menu_page( 'Subs', 'Subs', 'manage_options', 'club-subs', 'cs_report_page', 'dashicons-groups', 26 );
	add_submenu_page( 'club-subs', 'This month', 'This month', 'manage_options', 'club-subs', 'cs_report_page' );
}, 9 );

/* ---------------- Member fields ---------------- */

const CS_MEMBER_FIELDS = array(
	'child_first' => 'Child first name',
	'child_last'  => 'Child last name',
	'child_dob'   => 'Child date of birth',
	'plan_id'     => 'Plan',
	'parent_name' => 'Parent / payer name',
	'parent_email'=> 'Parent email',
	'parent_phone'=> 'Parent mobile',
	'status'      => 'Status',
	'gc_customer' => 'GoCardless customer',
	'gc_mandate'  => 'GoCardless mandate',
	'gc_subscription' => 'GoCardless subscription',
	'gc_billing_request' => 'GoCardless billing request',
	'started'     => 'Started',
	'notes'       => 'Notes',
);

function cs_member( $id ) {
	$id = (int) $id;
	$m  = array( 'id' => $id, 'title' => get_the_title( $id ) );
	foreach ( CS_MEMBER_FIELDS as $k => $label ) {
		$m[ $k ] = (string) get_post_meta( $id, '_cs_' . $k, true );
	}
	return $m;
}

function cs_member_set( $id, $fields ) {
	foreach ( $fields as $k => $v ) {
		if ( array_key_exists( $k, CS_MEMBER_FIELDS ) ) {
			update_post_meta( (int) $id, '_cs_' . $k, is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
		}
	}
	$m = cs_member( $id );
	$title = trim( $m['child_first'] . ' ' . $m['child_last'] );
	if ( $title && $title !== $m['title'] ) {
		wp_update_post( array( 'ID' => (int) $id, 'post_title' => $title ) );
	}
}

/** Find the member a GoCardless id belongs to. */
function cs_member_by_gc( $field, $gc_id ) {
	$q = get_posts( array(
		'post_type'   => 'cs_member',
		'post_status' => 'any',
		'numberposts' => 1,
		'fields'      => 'ids',
		'meta_key'    => '_cs_' . $field,
		'meta_value'  => (string) $gc_id,
	) );
	return $q ? (int) $q[0] : 0;
}

add_action( 'add_meta_boxes_cs_member', function () {
	add_meta_box( 'cs_member_box', 'Member', function ( $post ) {
		$m     = cs_member( $post->ID );
		$plans = cs_plans();
		wp_nonce_field( 'cs_member_save', 'cs_member_nonce' );
		echo '<table class="form-table">';
		foreach ( CS_MEMBER_FIELDS as $k => $label ) {
			echo '<tr><th><label for="cs_' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( 'plan_id' === $k ) {
				echo '<select name="cs[plan_id]" id="cs_plan_id"><option value="">—</option>';
				foreach ( $plans as $p ) {
					echo '<option value="' . (int) $p['id'] . '"' . selected( (int) $m['plan_id'], (int) $p['id'], false ) . '>' . esc_html( $p['title'] . ' — ' . cs_money( $p['amount'] ) ) . '</option>';
				}
				echo '</select>';
			} elseif ( 'status' === $k ) {
				echo '<select name="cs[status]" id="cs_status">';
				foreach ( array( 'pending', 'active', 'cancelled', 'failed', 'squad' ) as $s ) {
					echo '<option value="' . $s . '"' . selected( $m['status'], $s, false ) . '>' . $s . '</option>';
				}
				echo '</select>';
			} elseif ( 'notes' === $k ) {
				echo '<textarea name="cs[notes]" id="cs_notes" rows="3" class="large-text">' . esc_textarea( $m['notes'] ) . '</textarea>';
			} elseif ( 0 === strpos( $k, 'gc_' ) || 'started' === $k ) {
				echo '<input type="text" name="cs[' . esc_attr( $k ) . ']" id="cs_' . esc_attr( $k ) . '" value="' . esc_attr( $m[ $k ] ) . '" class="regular-text code">';
			} else {
				echo '<input type="text" name="cs[' . esc_attr( $k ) . ']" id="cs_' . esc_attr( $k ) . '" value="' . esc_attr( $m[ $k ] ) . '" class="regular-text">';
			}
			echo '</td></tr>';
		}
		echo '</table>';
		if ( $m['gc_subscription'] ) {
			echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cs_cancel_sub&member=' . $post->ID ), 'cs_cancel_' . $post->ID ) ) . '" onclick="return confirm(\'Cancel this Direct Debit subscription at GoCardless?\')">Cancel subscription at GoCardless</a></p>';
		}
	} );
} );

add_action( 'save_post_cs_member', function ( $post_id ) {
	if ( ! isset( $_POST['cs_member_nonce'] ) || ! wp_verify_nonce( $_POST['cs_member_nonce'], 'cs_member_save' ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$in = isset( $_POST['cs'] ) && is_array( $_POST['cs'] ) ? wp_unslash( $_POST['cs'] ) : array();
	$clean = array();
	foreach ( $in as $k => $v ) {
		$clean[ $k ] = 'parent_email' === $k ? sanitize_email( $v ) : sanitize_text_field( $v );
	}
	remove_action( 'save_post_cs_member', __FUNCTION__ );
	cs_member_set( $post_id, $clean );
}, 10, 1 );

add_filter( 'manage_cs_member_posts_columns', function ( $cols ) {
	return array(
		'cb'     => $cols['cb'],
		'title'  => 'Child',
		'plan'   => 'Plan',
		'parent' => 'Parent',
		'status' => 'Status',
		'mandate'=> 'Mandate',
		'date'   => 'Added',
	);
} );
add_action( 'manage_cs_member_posts_custom_column', function ( $col, $id ) {
	$m = cs_member( $id );
	switch ( $col ) {
		case 'plan':   echo esc_html( $m['plan_id'] ? get_the_title( (int) $m['plan_id'] ) : '—' ); break;
		case 'parent': echo esc_html( $m['parent_name'] ) . '<br><small>' . esc_html( $m['parent_email'] ) . '</small>'; break;
		case 'status': echo '<span class="cs-pill cs-' . esc_attr( $m['status'] ) . '">' . esc_html( $m['status'] ?: 'pending' ) . '</span>'; break;
		case 'mandate':echo '<code>' . esc_html( $m['gc_mandate'] ?: '—' ) . '</code>'; break;
	}
}, 10, 2 );

add_action( 'admin_head', function () {
	echo '<style>.cs-pill{display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;background:#e5e5e5}.cs-active{background:#d6f5dd}.cs-failed,.cs-cancelled{background:#f8d7d7}.cs-pending{background:#fff3cd}</style>';
} );

/* ---------------- Payment records ---------------- */

/**
 * Record (or update) a payment GoCardless told us about.
 *
 * @param array $p A GoCardless payment resource.
 */
function cs_payment_upsert( $p ) {
	$gc_id = (string) $p['id'];
	$existing = get_posts( array( 'post_type' => 'cs_payment', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_cs_gc_payment', 'meta_value' => $gc_id ) );
	$sub    = isset( $p['links']['subscription'] ) ? $p['links']['subscription'] : '';
	$mand   = isset( $p['links']['mandate'] ) ? $p['links']['mandate'] : '';
	$member = $sub ? cs_member_by_gc( 'gc_subscription', $sub ) : 0;
	if ( ! $member && $mand ) {
		$member = cs_member_by_gc( 'gc_mandate', $mand );
	}
	$charge = isset( $p['charge_date'] ) ? $p['charge_date'] : current_time( 'Y-m-d' );
	$title  = ( $member ? get_the_title( $member ) : 'Unknown member' ) . ' · ' . cs_money( $p['amount'] ) . ' · ' . $charge;
	$id = $existing ? (int) $existing[0] : wp_insert_post( array( 'post_type' => 'cs_payment', 'post_status' => 'publish', 'post_title' => $title ) );
	if ( ! $id || is_wp_error( $id ) ) {
		return 0;
	}
	update_post_meta( $id, '_cs_gc_payment', $gc_id );
	update_post_meta( $id, '_cs_member_id', (string) $member );
	update_post_meta( $id, '_cs_amount', (string) (int) $p['amount'] );
	update_post_meta( $id, '_cs_status', (string) $p['status'] );
	update_post_meta( $id, '_cs_charge_date', (string) $charge );
	update_post_meta( $id, '_cs_month', substr( (string) $charge, 0, 7 ) );
	update_post_meta( $id, '_cs_gc_subscription', (string) $sub );
	update_post_meta( $id, '_cs_gc_mandate', (string) $mand );
	wp_update_post( array( 'ID' => $id, 'post_title' => $title ) );
	return $id;
}

add_filter( 'manage_cs_payment_posts_columns', function ( $cols ) {
	return array( 'cb' => $cols['cb'], 'title' => 'Payment', 'pstatus' => 'Status', 'charge' => 'Charge date', 'gc' => 'GoCardless id' );
} );
add_action( 'manage_cs_payment_posts_custom_column', function ( $col, $id ) {
	switch ( $col ) {
		case 'pstatus': $s = get_post_meta( $id, '_cs_status', true ); echo '<span class="cs-pill cs-' . esc_attr( cs_payment_bucket( $s ) ) . '">' . esc_html( $s ) . '</span>'; break;
		case 'charge':  echo esc_html( get_post_meta( $id, '_cs_charge_date', true ) ); break;
		case 'gc':      echo '<code>' . esc_html( get_post_meta( $id, '_cs_gc_payment', true ) ) . '</code>'; break;
	}
}, 10, 2 );

/** GoCardless payment statuses → paid | pending | failed. */
function cs_payment_bucket( $status ) {
	if ( in_array( $status, array( 'confirmed', 'paid_out' ), true ) ) {
		return 'active'; // reuse the green pill
	}
	if ( in_array( $status, array( 'failed', 'cancelled', 'charged_back', 'customer_approval_denied' ), true ) ) {
		return 'failed';
	}
	return 'pending';
}
