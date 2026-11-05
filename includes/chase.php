<?php
/**
 * Subs → Chase list. Who has paid and who hasn't, read straight from
 * GoCardless, so it works for subscriptions this plugin set up AND the ones
 * another system (Pitchero) set up in the same GoCardless account, imported
 * or not.
 *
 * On screen whenever someone opens it, and emailed on a schedule (weekly or
 * monthly) or on request to whoever chases payments.
 *
 * States, one per subscription:
 *   stopped  — the Direct Debit was cancelled or failed at the bank (the
 *              mandate or subscription is no longer live) while they were
 *              still paying in the last three months
 *   failed   — a collection failed and nothing has been collected since;
 *              "owed" is the total of those failed collections
 *   missing  — a live monthly subscription whose collection day has passed
 *              this month with no payment at all
 *   due      — a payment this month is on its way (Bacs takes a few days)
 *   paid     — confirmed or paid out this month
 *   paused   — paused at GoCardless
 *   upcoming — collection day not reached yet this month
 * The first three are the ones to chase.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CS_CHASE_STATES = array(
	'stopped'  => 'Direct Debit stopped',
	'failed'   => 'Payment failed',
	'missing'  => 'Nothing collected',
	'due'      => 'On its way',
	'paid'     => 'Paid',
	'paused'   => 'Paused',
	'upcoming' => 'Not due yet',
);

function cs_chase_is_chase( $state ) {
	return in_array( $state, array( 'stopped', 'failed', 'missing' ), true );
}

/**
 * Build the list for a month (Y-m). Cached for 15 minutes; $fresh skips the cache.
 *
 * @return array|WP_Error { month, rows[], counts[], built }
 */
function cs_chase_build( $month, $fresh = false ) {
	$key = 'cs_chase_' . cs_gc_env() . '_' . str_replace( '-', '', $month );
	if ( ! $fresh ) {
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
	}
	$from = date( 'Y-m-01', strtotime( $month . '-01 -2 months' ) );
	$to   = date( 'Y-m-t', strtotime( $month . '-01' ) );

	$subs = cs_gc_list_all( 'subscriptions', array(), 5000 );
	if ( is_wp_error( $subs ) ) {
		return $subs;
	}
	$mandates = cs_gc_list_all( 'mandates', array(), 5000 );
	if ( is_wp_error( $mandates ) ) {
		return $mandates;
	}
	$customers = cs_gc_list_all( 'customers', array(), 5000 );
	if ( is_wp_error( $customers ) ) {
		return $customers;
	}
	$payments = cs_gc_list_all( 'payments', array( 'charge_date[gte]' => $from, 'charge_date[lte]' => $to ), 10000 );
	if ( is_wp_error( $payments ) ) {
		return $payments;
	}

	$cust_by = array();
	foreach ( $customers as $c ) {
		$cust_by[ $c['id'] ] = $c;
	}
	$mand_by = array();
	foreach ( $mandates as $m ) {
		$mand_by[ $m['id'] ] = $m;
	}
	$pays_by_sub = array();
	foreach ( $payments as $p ) {
		$sid = $p['links']['subscription'] ?? '';
		if ( '' === $sid ) {
			continue;
		}
		$pays_by_sub[ $sid ][] = $p;
	}

	$today      = current_time( 'Y-m-d' );
	$this_month = current_time( 'Y-m' );
	$rows       = array();
	foreach ( $subs as $s ) {
		$sid   = $s['id'];
		$pays  = $pays_by_sub[ $sid ] ?? array();
		$live  = in_array( $s['status'], array( 'active', 'paused', 'pending_customer_approval' ), true );
		if ( ! $live && ! $pays ) {
			continue; // ended before this window — not a current member
		}
		usort( $pays, function ( $a, $b ) { return strcmp( $a['charge_date'], $b['charge_date'] ); } );

		$mid   = $s['links']['mandate'] ?? '';
		$mand  = $mand_by[ $mid ] ?? array();
		$cust  = $cust_by[ $mand['links']['customer'] ?? '' ] ?? array();
		$mstat = $mand['status'] ?? '';

		$last_paid = '';
		$owed      = 0;
		$month_bucket = '';
		$rank = array( 'active' => 3, 'pending' => 2, 'failed' => 1 );
		foreach ( $pays as $p ) {
			$b = cs_payment_bucket( $p['status'] );
			if ( 'active' === $b ) {
				$last_paid = $p['charge_date'];
				$owed      = 0; // collected since — earlier failures were made good or superseded
			} elseif ( 'failed' === $b ) {
				$owed += (int) $p['amount'];
			}
			if ( substr( $p['charge_date'], 0, 7 ) === $month && ( '' === $month_bucket || $rank[ $b ] > $rank[ $month_bucket ] ) ) {
				$month_bucket = $b;
			}
		}

		$stopped = in_array( $s['status'], array( 'cancelled' ), true )
			|| in_array( $mstat, array( 'cancelled', 'failed', 'expired', 'blocked', 'suspended_by_payer' ), true );
		if ( 'finished' === $s['status'] && ! $stopped ) {
			continue; // ran its course (end of season) — nothing to chase
		}

		if ( $stopped ) {
			$state = 'stopped';
		} elseif ( $owed > 0 ) {
			$state = 'failed';
		} elseif ( 'active' === $month_bucket ) {
			$state = 'paid';
		} elseif ( 'pending' === $month_bucket ) {
			$state = 'due';
		} elseif ( 'paused' === $s['status'] ) {
			$state = 'paused';
		} else {
			// No payment this month. Missing only if collection day has passed.
			$day = isset( $s['day_of_month'] ) ? (int) $s['day_of_month'] : 0;
			$monthly = ( $s['interval_unit'] ?? 'monthly' ) === 'monthly';
			$started = empty( $s['start_date'] ) || $s['start_date'] <= $to;
			if ( $month < $this_month ) {
				$passed = true;
			} elseif ( $month > $this_month ) {
				$passed = false;
			} else {
				$dd     = ( $day < 1 ) ? (int) date( 't', strtotime( $today ) ) : $day;
				$passed = (int) current_time( 'j' ) > $dd + 3; // a few days' grace for Bacs
			}
			$state = ( $monthly && $started && $passed && 'active' === $s['status'] ) ? 'missing' : 'upcoming';
		}

		$member = cs_member_by_gc( 'gc_subscription', $sid );
		$m      = $member ? cs_member( $member ) : array();
		$team   = ( $m && $m['plan_id'] ) ? get_the_title( (int) $m['plan_id'] ) : '';
		$child  = $m ? trim( $m['child_first'] . ' ' . ( '(name the child)' === $m['child_last'] ? '' : $m['child_last'] ) ) : '';
		$payer  = trim( ( $cust['given_name'] ?? '' ) . ' ' . ( $cust['family_name'] ?? '' ) ) ?: ( $cust['company_name'] ?? '' );

		$rows[] = array(
			'state'     => $state,
			'payer'     => $payer,
			'email'     => $cust['email'] ?? '',
			'phone'     => $cust['phone_number'] ?? '',
			'for'       => $child ?: (string) ( $s['name'] ?? '' ),
			'team'      => $team,
			'amount'    => (int) $s['amount'],
			'day'       => isset( $s['day_of_month'] ) ? (int) $s['day_of_month'] : 0,
			'owed'      => $owed,
			'last_paid' => $last_paid,
			'gc_status' => $s['status'] . ( $mstat ? ' / mandate ' . $mstat : '' ),
			'sub'       => $sid,
			'member'    => $member,
		);
	}

	$order = array_flip( array_keys( CS_CHASE_STATES ) );
	usort( $rows, function ( $a, $b ) use ( $order ) {
		return array( $order[ $a['state'] ], $a['team'], $a['payer'] ) <=> array( $order[ $b['state'] ], $b['team'], $b['payer'] );
	} );
	$counts = array_fill_keys( array_keys( CS_CHASE_STATES ), 0 );
	$money  = array( 'paid' => 0, 'owed' => 0 );
	foreach ( $rows as $r ) {
		$counts[ $r['state'] ]++;
		if ( 'paid' === $r['state'] ) {
			$money['paid'] += $r['amount'];
		}
		$money['owed'] += $r['owed'];
	}
	$out = array( 'month' => $month, 'rows' => $rows, 'counts' => $counts, 'money' => $money, 'built' => current_time( 'mysql' ) );
	set_transient( $key, $out, 15 * MINUTE_IN_SECONDS );
	cs_log( 'chase list built', array( 'month' => $month, 'subs' => count( $rows ), 'chase' => $counts['stopped'] + $counts['failed'] + $counts['missing'] ) );
	return $out;
}

/** One row as CSV columns. */
function cs_chase_csv_row( $r ) {
	return array(
		CS_CHASE_STATES[ $r['state'] ], $r['team'], $r['for'], $r['payer'], $r['email'], $r['phone'],
		number_format( $r['amount'] / 100, 2, '.', '' ), $r['day'] ?: '',
		$r['owed'] ? number_format( $r['owed'] / 100, 2, '.', '' ) : '', $r['last_paid'], $r['gc_status'], $r['sub'],
	);
}
const CS_CHASE_CSV_HEAD = array( 'State', 'Team', 'For', 'Payer', 'Email', 'Mobile', 'Monthly', 'Day', 'Owed', 'Last paid', 'GoCardless status', 'Subscription' );

/** Email the list. Returns true on send, WP_Error otherwise. */
function cs_chase_send( $month = null, $to = null ) {
	$month = $month ?: current_time( 'Y-m' );
	$to    = $to ?: (string) get_option( 'cs_chase_to', '' );
	$to    = array_filter( array_map( 'trim', explode( ',', $to ) ), 'is_email' );
	if ( ! $to ) {
		return new WP_Error( 'cs_chase_to', 'No address to send the chase list to. Add one under Subs → Chase list.' );
	}
	$rep = cs_chase_build( $month, true );
	if ( is_wp_error( $rep ) ) {
		cs_notify_club( 'Chase list could not be built', $rep->get_error_message() );
		return $rep;
	}
	$c       = $rep['counts'];
	$n_chase = $c['stopped'] + $c['failed'] + $c['missing'];
	$label   = date( 'F Y', strtotime( $month . '-01' ) );
	$subject = 'Subs chase list, ' . $label . ': ' . $n_chase . ' to chase';

	$td  = 'style="padding:6px 10px;border-bottom:1px solid #ddd;text-align:left;vertical-align:top"';
	$h   = '<div style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#111">';
	$h  .= '<p><strong>' . (int) $n_chase . ' to chase</strong> for ' . esc_html( $label ) . '. '
		. (int) $c['paid'] . ' paid (' . esc_html( cs_money( $rep['money']['paid'] ) ) . '), '
		. (int) $c['due'] . ' on their way, ' . (int) $c['upcoming'] . ' not due yet.'
		. ( $rep['money']['owed'] ? ' ' . esc_html( cs_money( $rep['money']['owed'] ) ) . ' in failed collections.' : '' ) . '</p>';
	if ( $n_chase ) {
		$h .= '<table style="border-collapse:collapse;font-size:14px"><tr>';
		foreach ( array( 'Why', 'Team', 'For', 'Payer', 'Mobile', 'Email', 'Owed', 'Last paid' ) as $th ) {
			$h .= '<th ' . $td . '>' . $th . '</th>';
		}
		$h .= '</tr>';
		foreach ( $rep['rows'] as $r ) {
			if ( ! cs_chase_is_chase( $r['state'] ) ) {
				continue;
			}
			$h .= '<tr><td ' . $td . '><strong>' . esc_html( CS_CHASE_STATES[ $r['state'] ] ) . '</strong></td>'
				. '<td ' . $td . '>' . esc_html( $r['team'] ) . '</td>'
				. '<td ' . $td . '>' . esc_html( $r['for'] ) . '</td>'
				. '<td ' . $td . '>' . esc_html( $r['payer'] ) . '</td>'
				. '<td ' . $td . '>' . esc_html( $r['phone'] ) . '</td>'
				. '<td ' . $td . '><a href="mailto:' . esc_attr( $r['email'] ) . '">' . esc_html( $r['email'] ) . '</a></td>'
				. '<td ' . $td . '>' . ( $r['owed'] ? esc_html( cs_money( $r['owed'] ) ) : '' ) . '</td>'
				. '<td ' . $td . '>' . esc_html( $r['last_paid'] ? date( 'j M', strtotime( $r['last_paid'] ) ) : 'never' ) . '</td></tr>';
		}
		$h .= '</table>';
	} else {
		$h .= '<p>Nobody to chase.</p>';
	}
	$h .= '<p style="color:#555;font-size:13px">Direct Debit stopped: the parent or their bank cancelled it, so nothing more will be collected until they set it up again. '
		. 'Payment failed: a collection bounced and nothing has come in since. Nothing collected: their collection day has passed with no payment this month.</p>';
	$h .= '<p style="font-size:13px">Everyone, paid and unpaid, is in the attached spreadsheet. Live list: <a href="' . esc_url( admin_url( 'admin.php?page=club-subs-chase&month=' . $month ) ) . '">Subs → Chase list</a></p></div>';

	$dir  = trailingslashit( get_temp_dir() );
	$file = $dir . 'subs-' . $month . '-' . wp_generate_password( 6, false ) . '.csv';
	$fh   = fopen( $file, 'w' );
	if ( $fh ) {
		fputcsv( $fh, CS_CHASE_CSV_HEAD );
		foreach ( $rep['rows'] as $r ) {
			fputcsv( $fh, cs_chase_csv_row( $r ) );
		}
		fclose( $fh );
	}
	$from_name  = (string) get_option( 'cs_from_name', '' ) ?: cs_club_name();
	$from_email = (string) get_option( 'cs_from_email', '' ) ?: get_option( 'admin_email' );
	$ok = wp_mail( $to, $subject, $h, array( 'From: ' . $from_name . ' <' . $from_email . '>', 'Content-Type: text/html; charset=UTF-8' ), $fh ? array( $file ) : array() );
	if ( $fh ) {
		@unlink( $file );
	}
	cs_log( 'chase list emailed', array( 'month' => $month, 'to' => $to, 'chase' => $n_chase, 'sent' => (bool) $ok ) );
	if ( ! $ok ) {
		return new WP_Error( 'cs_chase_mail', 'The site could not send the email.' );
	}
	update_option( 'cs_chase_last', current_time( 'mysql' ), false );
	return true;
}

/* ---------------- Schedule: one daily tick at 8am, decides whether today is the day ---------------- */

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'cs_chase_tick' ) ) {
		$next = strtotime( current_time( 'Y-m-d' ) . ' 08:00:00' ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		if ( $next < time() ) {
			$next += DAY_IN_SECONDS;
		}
		wp_schedule_event( $next, 'daily', 'cs_chase_tick' );
	}
} );

add_action( 'cs_chase_tick', function () {
	$freq = (string) get_option( 'cs_chase_freq', 'off' );
	if ( 'weekly' === $freq ) {
		$go = (int) current_time( 'N' ) === (int) get_option( 'cs_chase_dow', 1 );
	} elseif ( 'monthly' === $freq ) {
		$go = (int) current_time( 'j' ) === (int) get_option( 'cs_chase_dom', 10 );
	} else {
		$go = false;
	}
	if ( ! $go || substr( (string) get_option( 'cs_chase_last', '' ), 0, 10 ) === current_time( 'Y-m-d' ) ) {
		return;
	}
	cs_chase_send();
} );

register_deactivation_hook( CS_FILE, function () {
	wp_clear_scheduled_hook( 'cs_chase_tick' );
} );

/* ---------------- Admin screen ---------------- */

add_action( 'admin_menu', function () {
	add_submenu_page( 'club-subs', 'Chase list', 'Chase list', 'manage_options', 'club-subs-chase', 'cs_chase_page' );
}, 15 );

function cs_chase_page() {
	$month = isset( $_GET['month'] ) && preg_match( '/^\d{4}-\d{2}$/', $_GET['month'] ) ? $_GET['month'] : current_time( 'Y-m' );
	$base  = admin_url( 'admin.php?page=club-subs-chase' );
	$msg   = '';

	if ( isset( $_POST['cs_chase_nonce'] ) && wp_verify_nonce( $_POST['cs_chase_nonce'], 'cs_chase' ) && current_user_can( 'manage_options' ) ) {
		$in    = wp_unslash( $_POST );
		$addrs = array_filter( array_map( 'trim', explode( ',', (string) ( $in['cs_chase_to'] ?? '' ) ) ), 'is_email' );
		update_option( 'cs_chase_to', implode( ', ', $addrs ) );
		update_option( 'cs_chase_freq', in_array( $in['cs_chase_freq'] ?? '', array( 'off', 'weekly', 'monthly' ), true ) ? $in['cs_chase_freq'] : 'off' );
		update_option( 'cs_chase_dow', max( 1, min( 7, (int) ( $in['cs_chase_dow'] ?? 1 ) ) ) );
		update_option( 'cs_chase_dom', max( 1, min( 28, (int) ( $in['cs_chase_dom'] ?? 10 ) ) ) );
		if ( ! empty( $in['cs_chase_send_now'] ) ) {
			$sent = cs_chase_send( $month );
			$msg  = is_wp_error( $sent ) ? '<div class="notice notice-error"><p>' . esc_html( $sent->get_error_message() ) . '</p></div>' : '<div class="notice notice-success"><p>Sent to ' . esc_html( get_option( 'cs_chase_to' ) ) . '.</p></div>';
		} else {
			$msg = '<div class="notice notice-success"><p>Saved.</p></div>';
		}
	}

	$rep  = cs_chase_build( $month, ! empty( $_GET['fresh'] ) );
	$prev = date( 'Y-m', strtotime( $month . '-01 -1 month' ) );
	$next = date( 'Y-m', strtotime( $month . '-01 +1 month' ) );
	$days = array( 1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
	?>
	<div class="wrap">
		<h1>Chase list — <?php echo esc_html( date( 'F Y', strtotime( $month . '-01' ) ) ); ?>
			<a class="page-title-action" href="<?php echo esc_url( add_query_arg( 'month', $prev, $base ) ); ?>">‹ <?php echo esc_html( date( 'M', strtotime( $prev . '-01' ) ) ); ?></a>
			<a class="page-title-action" href="<?php echo esc_url( add_query_arg( 'month', $next, $base ) ); ?>"><?php echo esc_html( date( 'M', strtotime( $next . '-01' ) ) ); ?> ›</a>
			<a class="page-title-action" href="<?php echo esc_url( add_query_arg( array( 'month' => $month, 'fresh' => 1 ), $base ) ); ?>">Refresh from GoCardless</a>
			<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cs_chase_csv&month=' . $month ), 'cs_chase_csv' ) ); ?>">Download spreadsheet</a>
		</h1>
		<?php echo $msg; // built above from escaped parts ?>

		<form method="post" style="background:#fff;border:1px solid #dcdcde;padding:12px 16px;margin:12px 0;max-width:900px">
			<?php wp_nonce_field( 'cs_chase', 'cs_chase_nonce' ); ?>
			<p style="margin-top:0"><label><strong>Email the chase list to</strong><br>
				<input type="text" name="cs_chase_to" class="regular-text" value="<?php echo esc_attr( get_option( 'cs_chase_to', '' ) ); ?>" placeholder="name@club.com, another@club.com"></label></p>
			<p><strong>How often</strong><br>
				<label><input type="radio" name="cs_chase_freq" value="off" <?php checked( get_option( 'cs_chase_freq', 'off' ), 'off' ); ?>> Only when asked</label> &nbsp;
				<label><input type="radio" name="cs_chase_freq" value="weekly" <?php checked( get_option( 'cs_chase_freq' ), 'weekly' ); ?>> Every week on</label>
				<select name="cs_chase_dow"><?php foreach ( $days as $n => $d ) { echo '<option value="' . $n . '"' . selected( (int) get_option( 'cs_chase_dow', 1 ), $n, false ) . '>' . $d . '</option>'; } ?></select> &nbsp;
				<label><input type="radio" name="cs_chase_freq" value="monthly" <?php checked( get_option( 'cs_chase_freq' ), 'monthly' ); ?>> Every month on the</label>
				<input type="number" name="cs_chase_dom" min="1" max="28" style="width:60px" value="<?php echo (int) get_option( 'cs_chase_dom', 10 ); ?>">
				<span class="description">— at 8am.</span></p>
			<p style="margin-bottom:0"><?php submit_button( 'Save', 'primary', 'cs_chase_save', false ); ?> &nbsp; <?php submit_button( 'Email this list now', 'secondary', 'cs_chase_send_now', false ); ?>
				<?php if ( get_option( 'cs_chase_last' ) ) : ?><span class="description">&nbsp; Last sent <?php echo esc_html( get_option( 'cs_chase_last' ) ); ?></span><?php endif; ?></p>
		</form>

		<?php if ( is_wp_error( $rep ) ) : ?>
			<div class="notice notice-error"><p>Could not read GoCardless: <?php echo esc_html( $rep->get_error_message() ); ?>. Check the token at Subs → Settings.</p></div>
		<?php else :
			$c = $rep['counts']; ?>
			<p style="font-size:15px"><strong><?php echo (int) ( $c['stopped'] + $c['failed'] + $c['missing'] ); ?> to chase</strong> ·
				<?php echo (int) $c['paid']; ?> paid (<?php echo esc_html( cs_money( $rep['money']['paid'] ) ); ?>) ·
				<?php echo (int) $c['due']; ?> on their way · <?php echo (int) $c['upcoming']; ?> not due yet<?php echo $c['paused'] ? ' · ' . (int) $c['paused'] . ' paused' : ''; ?>
				<?php if ( $rep['money']['owed'] ) : ?> · <?php echo esc_html( cs_money( $rep['money']['owed'] ) ); ?> in failed collections<?php endif; ?>
				<span class="description"> — read from GoCardless (<?php echo esc_html( cs_gc_env() ); ?>) at <?php echo esc_html( $rep['built'] ); ?></span></p>
			<table class="widefat striped">
				<thead><tr><th>State</th><th>Team</th><th>For</th><th>Payer</th><th>Mobile</th><th>Email</th><th>Monthly</th><th>Owed</th><th>Last paid</th><th>GoCardless</th></tr></thead>
				<tbody>
				<?php foreach ( $rep['rows'] as $r ) :
					$pill = cs_chase_is_chase( $r['state'] ) ? 'failed' : ( 'paid' === $r['state'] ? 'active' : 'pending' ); ?>
					<tr>
						<td><span class="cs-pill cs-<?php echo esc_attr( $pill ); ?>"><?php echo esc_html( CS_CHASE_STATES[ $r['state'] ] ); ?></span></td>
						<td><?php echo esc_html( $r['team'] ); ?></td>
						<td><?php echo $r['member'] ? '<a href="' . esc_url( get_edit_post_link( $r['member'] ) ) . '">' . esc_html( $r['for'] ) . '</a>' : esc_html( $r['for'] ); ?></td>
						<td><?php echo esc_html( $r['payer'] ); ?></td>
						<td><?php echo esc_html( $r['phone'] ); ?></td>
						<td><a href="mailto:<?php echo esc_attr( $r['email'] ); ?>"><?php echo esc_html( $r['email'] ); ?></a></td>
						<td><?php echo esc_html( cs_money( $r['amount'] ) ); ?><?php echo $r['day'] ? ' · ' . (int) $r['day'] . 'th' : ''; ?></td>
						<td><?php echo $r['owed'] ? esc_html( cs_money( $r['owed'] ) ) : ''; ?></td>
						<td><?php echo esc_html( $r['last_paid'] ? date( 'j M Y', strtotime( $r['last_paid'] ) ) : 'never' ); ?></td>
						<td><code><?php echo esc_html( $r['gc_status'] ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $rep['rows'] ) : ?><tr><td colspan="10">No subscriptions found in this GoCardless account.</td></tr><?php endif; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

add_action( 'admin_post_cs_chase_csv', function () {
	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'cs_chase_csv' ) ) {
		wp_die( 'Not allowed' );
	}
	$month = preg_match( '/^\d{4}-\d{2}$/', $_GET['month'] ?? '' ) ? $_GET['month'] : current_time( 'Y-m' );
	$rep   = cs_chase_build( $month );
	if ( is_wp_error( $rep ) ) {
		wp_die( esc_html( $rep->get_error_message() ) );
	}
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=subs-chase-' . $month . '.csv' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, CS_CHASE_CSV_HEAD );
	foreach ( $rep['rows'] as $r ) {
		fputcsv( $out, cs_chase_csv_row( $r ) );
	}
	fclose( $out );
	exit;
} );
