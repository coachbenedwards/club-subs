<?php
/**
 * Plans: what a team's subs are.
 *
 * amount        pence, e.g. 3000 = £30
 * day_of_month  1–28 (GoCardless collects on this day; 29–31 are not allowed)
 * months        how many collections a season: 10 (Sep–Jun) or 12
 * season_start  first month of the season, 1–12 (default 9 = September). Used
 *               to work out how many collections are left when someone joins
 *               mid-season, and for the "this month" report.
 * first_payment pence, optional one-off taken with the mandate (registration fee / first month now)
 * open          '1' if parents can pick it on the sign-up block
 * description   one line shown on the sign-up block
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CS_PLAN_FIELDS = array( 'amount', 'day_of_month', 'months', 'season_start', 'first_payment', 'open', 'description' );

function cs_plan( $id ) {
	$id = (int) $id;
	$p  = array( 'id' => $id, 'title' => get_the_title( $id ) );
	foreach ( CS_PLAN_FIELDS as $k ) {
		$p[ $k ] = (string) get_post_meta( $id, '_cs_' . $k, true );
	}
	$p['amount']        = (int) $p['amount'];
	$p['day_of_month']  = max( 1, min( 28, (int) ( $p['day_of_month'] ?: 1 ) ) );
	$p['months']        = (int) ( $p['months'] ?: 10 );
	$p['season_start']  = max( 1, min( 12, (int) ( $p['season_start'] ?: 9 ) ) );
	$p['first_payment'] = (int) $p['first_payment'];
	return $p;
}

/** All plans, open ones first, by title. */
function cs_plans( $open_only = false ) {
	$ids = get_posts( array( 'post_type' => 'cs_plan', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC' ) );
	$out = array();
	foreach ( $ids as $id ) {
		$p = cs_plan( $id );
		if ( $open_only && '1' !== $p['open'] ) {
			continue;
		}
		$out[] = $p;
	}
	return $out;
}

/**
 * How many monthly collections are left in the current season for this plan,
 * counting from the next collection date. A 10-month plan starting September
 * joined in January has 6 left (Jan–Jun). Never fewer than 1.
 */
function cs_plan_collections_left( $plan, $from_ts = null ) {
	$from  = $from_ts ? $from_ts : current_time( 'timestamp' );
	$year  = (int) date( 'Y', $from );
	$month = (int) date( 'n', $from );
	$day   = (int) date( 'j', $from );
	// Next collection month: this month if the collection day is still ahead (GoCardless needs ~3 working days), else next month.
	$next_m = ( $day + 4 <= $plan['day_of_month'] ) ? $month : $month + 1;
	$start  = $plan['season_start'];
	// Season index of next collection month (0 = season start month).
	$idx = ( $next_m - $start + 12 ) % 12;
	$left = $plan['months'] - $idx;
	if ( $left < 1 ) {
		$left = 1; // season over: one collection, the club can sort the rest by hand
	}
	return (int) $left;
}

add_action( 'add_meta_boxes_cs_plan', function () {
	add_meta_box( 'cs_plan_box', 'Plan', function ( $post ) {
		$p = cs_plan( $post->ID );
		wp_nonce_field( 'cs_plan_save', 'cs_plan_nonce' );
		?>
		<table class="form-table">
			<tr><th><label for="cs_amount">Amount per month (£)</label></th><td><input type="number" step="0.01" min="1" id="cs_amount" name="cs[amount]" value="<?php echo esc_attr( $p['amount'] ? number_format( $p['amount'] / 100, 2, '.', '' ) : '' ); ?>"></td></tr>
			<tr><th><label for="cs_day">Collect on day of month</label></th><td><input type="number" min="1" max="28" id="cs_day" name="cs[day_of_month]" value="<?php echo (int) $p['day_of_month']; ?>"> <span class="description">1–28</span></td></tr>
			<tr><th><label for="cs_months">Collections per season</label></th><td><input type="number" min="1" max="12" id="cs_months" name="cs[months]" value="<?php echo (int) $p['months']; ?>"> <span class="description">10 = Sep–Jun, 12 = all year</span></td></tr>
			<tr><th><label for="cs_start">Season starts in month</label></th><td><input type="number" min="1" max="12" id="cs_start" name="cs[season_start]" value="<?php echo (int) $p['season_start']; ?>"> <span class="description">9 = September</span></td></tr>
			<tr><th><label for="cs_first">One-off with the mandate (£)</label></th><td><input type="number" step="0.01" min="0" id="cs_first" name="cs[first_payment]" value="<?php echo esc_attr( $p['first_payment'] ? number_format( $p['first_payment'] / 100, 2, '.', '' ) : '' ); ?>"> <span class="description">Optional. A registration fee or the first month taken straight away. Leave blank for none.</span></td></tr>
			<tr><th><label for="cs_desc">One line for parents</label></th><td><input type="text" class="large-text" id="cs_desc" name="cs[description]" value="<?php echo esc_attr( $p['description'] ); ?>" placeholder="Training Tuesdays, games Sundays"></td></tr>
			<tr><th>Open for sign-up</th><td><label><input type="checkbox" name="cs[open]" value="1" <?php checked( $p['open'], '1' ); ?>> Show this plan on the sign-up block</label></td></tr>
		</table>
		<?php
	} );
} );

add_action( 'save_post_cs_plan', function ( $post_id ) {
	if ( ! isset( $_POST['cs_plan_nonce'] ) || ! wp_verify_nonce( $_POST['cs_plan_nonce'], 'cs_plan_save' ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$in = isset( $_POST['cs'] ) && is_array( $_POST['cs'] ) ? wp_unslash( $_POST['cs'] ) : array();
	update_post_meta( $post_id, '_cs_amount', (string) (int) round( (float) ( $in['amount'] ?? 0 ) * 100 ) );
	update_post_meta( $post_id, '_cs_day_of_month', (string) max( 1, min( 28, (int) ( $in['day_of_month'] ?? 1 ) ) ) );
	update_post_meta( $post_id, '_cs_months', (string) max( 1, min( 12, (int) ( $in['months'] ?? 10 ) ) ) );
	update_post_meta( $post_id, '_cs_season_start', (string) max( 1, min( 12, (int) ( $in['season_start'] ?? 9 ) ) ) );
	update_post_meta( $post_id, '_cs_first_payment', (string) (int) round( (float) ( $in['first_payment'] ?? 0 ) * 100 ) );
	update_post_meta( $post_id, '_cs_description', sanitize_text_field( $in['description'] ?? '' ) );
	update_post_meta( $post_id, '_cs_open', empty( $in['open'] ) ? '0' : '1' );
} );

add_filter( 'manage_cs_plan_posts_columns', function ( $cols ) {
	return array( 'cb' => $cols['cb'], 'title' => 'Plan', 'amount' => 'Per month', 'day' => 'Day', 'months' => 'Collections', 'open' => 'Open', 'members' => 'Active members' );
} );
add_action( 'manage_cs_plan_posts_custom_column', function ( $col, $id ) {
	$p = cs_plan( $id );
	switch ( $col ) {
		case 'amount':  echo esc_html( cs_money( $p['amount'] ) ); break;
		case 'day':     echo (int) $p['day_of_month']; break;
		case 'months':  echo (int) $p['months']; break;
		case 'open':    echo '1' === $p['open'] ? 'Yes' : '—'; break;
		case 'members': echo count( get_posts( array( 'post_type' => 'cs_member', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_cs_plan_id', 'value' => (string) $id ), array( 'key' => '_cs_status', 'value' => 'active' ) ) ) ) ); break;
	}
}, 10, 2 );
