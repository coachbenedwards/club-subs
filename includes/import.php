<?php
/**
 * Subs → Import. Pull the mandates and subscriptions already in the club's
 * GoCardless account (the ones Pitchero or anything else set up) so the
 * club can drop the other system without parents signing up again.
 *
 * What comes across: every active subscription with its mandate and customer.
 * The payer's name, email and phone are the customer's. The child's name is
 * not in GoCardless, so each imported member is titled after the payer and
 * marked "imported — name the child" for the club to fix in the Members list.
 * The plan is matched by amount where exactly one plan has that amount.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cs_import_page() {
	$result = null;
	if ( isset( $_POST['cs_import_nonce'] ) && wp_verify_nonce( $_POST['cs_import_nonce'], 'cs_import' ) && current_user_can( 'manage_options' ) ) {
		$result = cs_import_from_gocardless( ! empty( $_POST['cs_import_do'] ) );
	}
	?>
	<div class="wrap">
		<h1>Import from GoCardless</h1>
		<p>Reads every <strong>active subscription</strong> in the <strong><?php echo esc_html( cs_gc_env() ); ?></strong> GoCardless account, with its mandate and payer. Preview first; nothing is written until you tick the box.</p>
		<form method="post">
			<?php wp_nonce_field( 'cs_import', 'cs_import_nonce' ); ?>
			<p><label><input type="checkbox" name="cs_import_do" value="1"> Write the members (otherwise just preview)</label></p>
			<?php submit_button( 'Run' ); ?>
		</form>
		<?php if ( is_wp_error( $result ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $result->get_error_message() ); ?></p></div>
		<?php elseif ( is_array( $result ) ) : ?>
			<p><strong><?php echo (int) count( $result['rows'] ); ?></strong> active subscriptions found · <?php echo (int) $result['created']; ?> members written · <?php echo (int) $result['skipped']; ?> already here.</p>
			<table class="widefat striped"><thead><tr><th>Payer</th><th>Email</th><th>Amount</th><th>Day</th><th>Subscription</th><th>Mandate</th><th>Plan matched</th><th></th></tr></thead><tbody>
			<?php foreach ( $result['rows'] as $r ) : ?>
				<tr><td><?php echo esc_html( $r['payer'] ); ?></td><td><?php echo esc_html( $r['email'] ); ?></td><td><?php echo esc_html( cs_money( $r['amount'] ) ); ?></td><td><?php echo (int) $r['day']; ?></td><td><code><?php echo esc_html( $r['sub'] ); ?></code></td><td><code><?php echo esc_html( $r['mandate'] ); ?></code></td><td><?php echo esc_html( $r['plan'] ?: '—' ); ?></td><td><?php echo esc_html( $r['note'] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>
	</div>
	<?php
}

function cs_import_from_gocardless( $write ) {
	$subs = cs_gc_list_all( 'subscriptions', array( 'status' => 'active' ) );
	if ( is_wp_error( $subs ) ) {
		return $subs;
	}
	$plans    = cs_plans();
	$by_amt   = array();
	foreach ( $plans as $p ) {
		$by_amt[ $p['amount'] ][] = $p;
	}
	$customers = array();
	$rows = array(); $created = 0; $skipped = 0;
	foreach ( $subs as $s ) {
		$mandate = $s['links']['mandate'] ?? '';
		$sub_id  = $s['id'];
		$existing = cs_member_by_gc( 'gc_subscription', $sub_id );
		// Mandate → customer.
		$cust = array();
		if ( $mandate ) {
			$m = cs_gc( 'GET', '/mandates/' . $mandate );
			$cid = is_wp_error( $m ) ? '' : ( $m['mandates']['links']['customer'] ?? '' );
			if ( $cid ) {
				if ( ! isset( $customers[ $cid ] ) ) {
					$c = cs_gc( 'GET', '/customers/' . $cid );
					$customers[ $cid ] = is_wp_error( $c ) ? array( 'id' => $cid ) : $c['customers'];
				}
				$cust = $customers[ $cid ];
			}
		}
		$payer = trim( ( $cust['given_name'] ?? '' ) . ' ' . ( $cust['family_name'] ?? '' ) ) ?: ( $cust['company_name'] ?? '' );
		$plan  = null;
		if ( isset( $by_amt[ (int) $s['amount'] ] ) && 1 === count( $by_amt[ (int) $s['amount'] ] ) ) {
			$plan = $by_amt[ (int) $s['amount'] ][0];
		}
		$row = array( 'payer' => $payer, 'email' => $cust['email'] ?? '', 'amount' => (int) $s['amount'], 'day' => (int) ( $s['day_of_month'] ?? 0 ), 'sub' => $sub_id, 'mandate' => $mandate, 'plan' => $plan ? $plan['title'] : '', 'note' => '' );
		if ( $existing ) {
			$row['note'] = 'already here'; $skipped++;
		} elseif ( $write ) {
			$id = wp_insert_post( array( 'post_type' => 'cs_member', 'post_status' => 'publish', 'post_title' => $payer ? $payer . ' (child)' : 'Imported ' . $sub_id ) );
			if ( $id && ! is_wp_error( $id ) ) {
				cs_member_set( $id, array(
					'child_first' => $payer ? $payer : 'Imported', 'child_last' => '(name the child)',
					'plan_id' => $plan ? $plan['id'] : '', 'parent_name' => $payer, 'parent_email' => $cust['email'] ?? '', 'parent_phone' => $cust['phone_number'] ?? '',
					'status' => 'active', 'gc_customer' => $cust['id'] ?? '', 'gc_mandate' => $mandate, 'gc_subscription' => $sub_id,
					'started' => current_time( 'mysql' ), 'notes' => 'Imported from GoCardless ' . current_time( 'Y-m-d' ) . ( $s['name'] ? ' · "' . $s['name'] . '"' : '' ),
				) );
				update_post_meta( $id, '_cs_imported', '1' );
				$row['note'] = 'written'; $created++;
			}
		} else {
			$row['note'] = 'would write';
		}
		$rows[] = $row;
	}
	cs_log( 'import', array( 'write' => $write, 'found' => count( $rows ), 'created' => $created ) );
	return array( 'rows' => $rows, 'created' => $created, 'skipped' => $skipped );
}
