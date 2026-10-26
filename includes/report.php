<?php
/**
 * Subs → This month. Who's paid, who hasn't, per plan. CSV export.
 *
 * A member counts as paid for a month when a payment with charge_date in that
 * month is confirmed or paid_out. 'Due' means a payment exists but hasn't
 * cleared yet (Bacs takes a few days). 'Failed' is a failed/charged-back
 * collection. 'Nothing' means GoCardless has told us about no payment at all
 * for that month — a subscription that starts next month, or a problem.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cs_month_report( $month ) {
	$plans   = cs_plans();
	$members = get_posts( array( 'post_type' => 'cs_member', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC' ) );
	$pays    = get_posts( array( 'post_type' => 'cs_payment', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_cs_month', 'meta_value' => $month ) );
	$by_member = array();
	foreach ( $pays as $pid ) {
		$mid = (int) get_post_meta( $pid, '_cs_member_id', true );
		$st  = (string) get_post_meta( $pid, '_cs_status', true );
		$amt = (int) get_post_meta( $pid, '_cs_amount', true );
		$b   = cs_payment_bucket( $st );
		$rank = array( 'active' => 3, 'pending' => 2, 'failed' => 1 );
		if ( ! isset( $by_member[ $mid ] ) || $rank[ $b ] > $rank[ $by_member[ $mid ]['bucket'] ] ) {
			$by_member[ $mid ] = array( 'bucket' => $b, 'status' => $st, 'amount' => $amt );
		}
	}
	$rows = array();
	foreach ( $members as $mid ) {
		$m = cs_member( $mid );
		if ( ! in_array( $m['status'], array( 'active', 'failed' ), true ) ) {
			continue;
		}
		$p = isset( $by_member[ $mid ] ) ? $by_member[ $mid ] : array( 'bucket' => 'none', 'status' => '', 'amount' => 0 );
		$rows[] = array_merge( $m, array( 'pay_bucket' => $p['bucket'], 'pay_status' => $p['status'], 'pay_amount' => $p['amount'] ) );
	}
	return array( 'plans' => $plans, 'rows' => $rows );
}

function cs_report_page() {
	$month = isset( $_GET['month'] ) && preg_match( '/^\d{4}-\d{2}$/', $_GET['month'] ) ? $_GET['month'] : current_time( 'Y-m' );
	$rep   = cs_month_report( $month );
	$prev  = date( 'Y-m', strtotime( $month . '-01 -1 month' ) );
	$next  = date( 'Y-m', strtotime( $month . '-01 +1 month' ) );
	$label = array( 'active' => 'Paid', 'pending' => 'Due', 'failed' => 'Failed', 'none' => 'Nothing yet' );
	$tot   = array( 'active' => 0, 'pending' => 0, 'failed' => 0, 'none' => 0 );
	$money = 0;
	foreach ( $rep['rows'] as $r ) {
		$tot[ $r['pay_bucket'] ]++;
		if ( 'active' === $r['pay_bucket'] ) {
			$money += $r['pay_amount'];
		}
	}
	?>
	<div class="wrap">
		<h1>Subs — <?php echo esc_html( date( 'F Y', strtotime( $month . '-01' ) ) ); ?>
			<a class="page-title-action" href="<?php echo esc_url( add_query_arg( 'month', $prev ) ); ?>">‹ <?php echo esc_html( date( 'M', strtotime( $prev . '-01' ) ) ); ?></a>
			<a class="page-title-action" href="<?php echo esc_url( add_query_arg( 'month', $next ) ); ?>"><?php echo esc_html( date( 'M', strtotime( $next . '-01' ) ) ); ?> ›</a>
			<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cs_report_csv&month=' . $month ), 'cs_csv' ) ); ?>">Export CSV</a>
			<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cs_sync_payments&month=' . $month ), 'cs_sync' ) ); ?>">Refresh from GoCardless</a>
		</h1>
		<p><strong><?php echo (int) $tot['active']; ?></strong> paid (<?php echo esc_html( cs_money( $money ) ); ?>) · <strong><?php echo (int) $tot['pending']; ?></strong> due · <strong><?php echo (int) $tot['failed']; ?></strong> failed · <strong><?php echo (int) $tot['none']; ?></strong> nothing yet · <?php echo (int) count( $rep['rows'] ); ?> active members · <?php echo esc_html( cs_gc_env() ); ?> environment</p>
		<?php foreach ( $rep['plans'] as $plan ) :
			$rows = array_filter( $rep['rows'], function ( $r ) use ( $plan ) { return (int) $r['plan_id'] === (int) $plan['id']; } );
			if ( ! $rows ) { continue; } ?>
			<h2><?php echo esc_html( $plan['title'] ); ?> <small>· <?php echo esc_html( cs_money( $plan['amount'] ) ); ?> on the <?php echo (int) $plan['day_of_month']; ?> · <?php echo (int) count( $rows ); ?></small></h2>
			<table class="widefat striped">
				<thead><tr><th>Child</th><th>Parent</th><th>Email</th><th>Mobile</th><th>This month</th><th>Mandate</th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( $r['id'] ) ); ?>"><?php echo esc_html( $r['title'] ); ?></a></td>
						<td><?php echo esc_html( $r['parent_name'] ); ?></td>
						<td><a href="mailto:<?php echo esc_attr( $r['parent_email'] ); ?>"><?php echo esc_html( $r['parent_email'] ); ?></a></td>
						<td><?php echo esc_html( $r['parent_phone'] ); ?></td>
						<td><span class="cs-pill cs-<?php echo esc_attr( $r['pay_bucket'] ); ?>"><?php echo esc_html( $label[ $r['pay_bucket'] ] ); ?><?php echo $r['pay_status'] ? ' · ' . esc_html( $r['pay_status'] ) : ''; ?></span></td>
						<td><code><?php echo esc_html( $r['gc_mandate'] ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>
		<?php if ( ! $rep['rows'] ) : ?><p>No active members yet. Add a plan, put <code>[club_subs_signup]</code> on a page, or import existing mandates from GoCardless.</p><?php endif; ?>
	</div>
	<?php
}

add_action( 'admin_post_cs_report_csv', function () {
	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'cs_csv' ) ) {
		wp_die( 'Not allowed' );
	}
	$month = preg_match( '/^\d{4}-\d{2}$/', $_GET['month'] ?? '' ) ? $_GET['month'] : current_time( 'Y-m' );
	$rep   = cs_month_report( $month );
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=subs-' . $month . '.csv' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'Plan', 'Child', 'DOB', 'Parent', 'Email', 'Mobile', 'Month', 'Payment', 'Amount', 'Mandate', 'Subscription' ) );
	foreach ( $rep['rows'] as $r ) {
		fputcsv( $out, array( get_the_title( (int) $r['plan_id'] ), $r['title'], $r['child_dob'], $r['parent_name'], $r['parent_email'], $r['parent_phone'], $month, $r['pay_status'] ?: 'none', $r['pay_amount'] ? number_format( $r['pay_amount'] / 100, 2, '.', '' ) : '', $r['gc_mandate'], $r['gc_subscription'] ) );
	}
	fclose( $out );
	exit;
} );

/** Pull every payment for a month from GoCardless, in case a webhook was missed. */
add_action( 'admin_post_cs_sync_payments', function () {
	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'cs_sync' ) ) {
		wp_die( 'Not allowed' );
	}
	$month = preg_match( '/^\d{4}-\d{2}$/', $_GET['month'] ?? '' ) ? $_GET['month'] : current_time( 'Y-m' );
	$from  = $month . '-01';
	$to    = date( 'Y-m-t', strtotime( $from ) );
	$rows  = cs_gc_list_all( 'payments', array( 'charge_date[gte]' => $from, 'charge_date[lte]' => $to ) );
	$n = 0;
	if ( ! is_wp_error( $rows ) ) {
		foreach ( $rows as $p ) {
			cs_payment_upsert( $p );
			$n++;
		}
	}
	cs_log( 'payments synced', array( 'month' => $month, 'n' => $n ) );
	wp_safe_redirect( admin_url( 'admin.php?page=club-subs&month=' . $month ) );
	exit;
} );
