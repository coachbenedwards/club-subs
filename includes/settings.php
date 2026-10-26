<?php
/**
 * Subs → Settings. Tokens are write-only: the screen shows whether one is
 * held, never the token itself.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function () {
	add_submenu_page( 'club-subs', 'Settings', 'Settings', 'manage_options', 'club-subs-settings', 'cs_settings_page' );
	add_submenu_page( 'club-subs', 'Import from GoCardless', 'Import', 'manage_options', 'club-subs-import', 'cs_import_page' );
	add_submenu_page( 'club-subs', 'Log', 'Log', 'manage_options', 'club-subs-log', 'cs_log_page' );
}, 20 );

function cs_settings_page() {
	if ( isset( $_POST['cs_settings_nonce'] ) && wp_verify_nonce( $_POST['cs_settings_nonce'], 'cs_settings' ) && current_user_can( 'manage_options' ) ) {
		$in = wp_unslash( $_POST );
		update_option( 'cs_gc_env', ( isset( $in['cs_gc_env'] ) && 'live' === $in['cs_gc_env'] ) ? 'live' : 'sandbox' );
		foreach ( array( 'sandbox', 'live' ) as $env ) {
			if ( ! empty( $in[ 'cs_gc_token_' . $env ] ) ) {
				update_option( 'cs_gc_token_' . $env, sanitize_text_field( $in[ 'cs_gc_token_' . $env ] ), false );
			}
			if ( ! empty( $in[ 'cs_gc_webhook_secret_' . $env ] ) ) {
				update_option( 'cs_gc_webhook_secret_' . $env, sanitize_text_field( $in[ 'cs_gc_webhook_secret_' . $env ] ), false );
			}
			if ( ! empty( $in[ 'cs_clear_' . $env ] ) ) {
				delete_option( 'cs_gc_token_' . $env );
				delete_option( 'cs_gc_webhook_secret_' . $env );
			}
		}
		update_option( 'cs_club_name', sanitize_text_field( $in['cs_club_name'] ?? '' ) );
		update_option( 'cs_notify_email', sanitize_email( $in['cs_notify_email'] ?? '' ) );
		update_option( 'cs_from_name', sanitize_text_field( $in['cs_from_name'] ?? '' ) );
		update_option( 'cs_from_email', sanitize_email( $in['cs_from_email'] ?? '' ) );
		update_option( 'cs_account_page', (int) ( $in['cs_account_page'] ?? 0 ) );
		echo '<div class="notice notice-success"><p>Saved.</p></div>';
	}
	$env  = cs_gc_env();
	$test = null;
	if ( isset( $_GET['cs_test'] ) && cs_gc_token() ) {
		$test = cs_gc( 'GET', '/creditors' );
	}
	?>
	<div class="wrap">
		<h1>Subs settings</h1>
		<form method="post">
			<?php wp_nonce_field( 'cs_settings', 'cs_settings_nonce' ); ?>
			<h2>GoCardless</h2>
			<p>Create an access token at GoCardless → Developers → Create → Access token, with read-write access. The sandbox one comes from <code>manage-sandbox.gocardless.com</code>, the live one from <code>manage.gocardless.com</code>. Paste it here once.</p>
			<table class="form-table">
				<tr><th>Environment in use</th><td>
					<label><input type="radio" name="cs_gc_env" value="sandbox" <?php checked( $env, 'sandbox' ); ?>> Sandbox (no real money)</label><br>
					<label><input type="radio" name="cs_gc_env" value="live" <?php checked( $env, 'live' ); ?>> <strong>Live</strong> — real Direct Debits</label>
				</td></tr>
				<?php foreach ( array( 'sandbox', 'live' ) as $e ) : ?>
				<tr><th><?php echo ucfirst( $e ); ?> access token</th><td>
					<input type="password" name="cs_gc_token_<?php echo $e; ?>" class="regular-text" autocomplete="off" placeholder="<?php echo cs_gc_token( $e ) ? 'Held — paste to replace' : 'Not set'; ?>">
					<?php if ( cs_gc_token( $e ) ) : ?><span class="description">✔ held</span><?php endif; ?>
				</td></tr>
				<tr><th><?php echo ucfirst( $e ); ?> webhook secret</th><td>
					<input type="password" name="cs_gc_webhook_secret_<?php echo $e; ?>" class="regular-text" autocomplete="off" placeholder="<?php echo cs_gc_webhook_secret( $e ) ? 'Held — paste to replace' : 'Not set'; ?>">
					<?php if ( cs_gc_webhook_secret( $e ) ) : ?><span class="description">✔ held</span><?php endif; ?>
					<br><label><input type="checkbox" name="cs_clear_<?php echo $e; ?>" value="1"> Clear the <?php echo $e; ?> token and secret</label>
				</td></tr>
				<?php endforeach; ?>
				<tr><th>Webhook address</th><td><code><?php echo esc_html( rest_url( 'club-subs/v1/webhook' ) ); ?></code>
					<p class="description">At GoCardless → Developers → Create → Webhook endpoint, paste this address, then copy the secret it gives you into the box above for the same environment.</p></td></tr>
			</table>
			<p><a class="button" href="<?php echo esc_url( add_query_arg( 'cs_test', '1' ) ); ?>">Test the connection</a>
			<?php if ( null !== $test ) : ?>
				<?php if ( is_wp_error( $test ) ) : ?><span style="color:#b32d2e">&nbsp;Failed: <?php echo esc_html( $test->get_error_message() ); ?></span>
				<?php else : $c = $test['creditors'][0] ?? array(); ?><span style="color:#1a7f37">&nbsp;Connected to <strong><?php echo esc_html( $c['name'] ?? 'account' ); ?></strong> (<?php echo esc_html( $env ); ?>)<?php if ( isset( $c['verification_status'] ) ) echo ', verification: ' . esc_html( $c['verification_status'] ); ?></span><?php endif; ?>
			<?php endif; ?></p>

			<h2>Club</h2>
			<table class="form-table">
				<tr><th><label for="cs_club_name">Club name</label></th><td><input type="text" id="cs_club_name" name="cs_club_name" class="regular-text" value="<?php echo esc_attr( get_option( 'cs_club_name', get_bloginfo( 'name' ) ) ); ?>"> <span class="description">Shown to parents on GoCardless' page and in emails.</span></td></tr>
				<tr><th><label for="cs_notify_email">Tell the club when</label></th><td><input type="email" id="cs_notify_email" name="cs_notify_email" class="regular-text" value="<?php echo esc_attr( get_option( 'cs_notify_email', '' ) ); ?>"> <span class="description">A new member signs up, a payment fails or a mandate is cancelled. Blank = no emails.</span></td></tr>
				<tr><th><label for="cs_from_name">Emails come from</label></th><td><input type="text" id="cs_from_name" name="cs_from_name" placeholder="Eastwood FC" value="<?php echo esc_attr( get_option( 'cs_from_name', '' ) ); ?>"> <input type="email" id="cs_from_email" name="cs_from_email" placeholder="info@club.com" class="regular-text" value="<?php echo esc_attr( get_option( 'cs_from_email', '' ) ); ?>"></td></tr>
				<tr><th><label for="cs_account_page">Parent account page</label></th><td>
					<?php wp_dropdown_pages( array( 'name' => 'cs_account_page', 'id' => 'cs_account_page', 'selected' => (int) get_option( 'cs_account_page', 0 ), 'show_option_none' => '— none —' ) ); ?>
					<span class="description">The page carrying <code>[club_subs_account]</code>. Emails link parents there.</span></td></tr>
			</table>
			<?php submit_button( 'Save' ); ?>
		</form>
		<h2>Blocks</h2>
		<p><code>[club_subs_signup]</code> — the sign-up form. <code>[club_subs_account]</code> — parent self-serve. Every word either shows is an attribute; see the README.</p>
	</div>
	<?php
}

function cs_log_page() {
	$log = array_reverse( (array) get_option( 'cs_log', array() ) );
	echo '<div class="wrap"><h1>Subs log</h1><table class="widefat striped"><thead><tr><th>When</th><th>What</th><th>Detail</th></tr></thead><tbody>';
	foreach ( $log as $l ) {
		echo '<tr><td>' . esc_html( $l['t'] ) . '</td><td>' . esc_html( $l['what'] ) . '</td><td><code style="white-space:pre-wrap">' . esc_html( wp_json_encode( $l['data'] ) ) . '</code></td></tr>';
	}
	echo '</tbody></table></div>';
}

/** Club name shown to parents. */
function cs_club_name() {
	return (string) get_option( 'cs_club_name', get_bloginfo( 'name' ) );
}
