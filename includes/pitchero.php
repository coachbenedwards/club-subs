<?php
/**
 * Subs → Import from Pitchero, and Subs → People.
 *
 * Takes the file Pitchero gives any club at Membership → Export (one row per
 * person, standard columns plus the club's own extra fields) and keeps every
 * person as a record on the club's own site. Re-running it updates people by
 * their Pitchero user id rather than adding them twice.
 *
 * Pitchero does not link children to the adult who pays, but families often
 * register siblings under one parent email. So a Direct Debit payer's email
 * finds the people registered under it, and the under-18s among them are
 * taken to be the children that Direct Debit is for. The chase list uses that
 * to show the child and a phone number where GoCardless has neither.
 *
 * "Hidden" in a Pitchero cell means the member hid it; it is stored as empty.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function () {
	register_post_type( 'cs_person', array(
		'labels'       => array( 'name' => 'People', 'singular_name' => 'Person', 'menu_name' => 'People', 'all_items' => 'People' ),
		'public'       => false,
		'show_ui'      => true,
		'show_in_menu' => 'club-subs',
		'supports'     => array( 'title' ),
		'capability_type' => 'post',
		'capabilities' => array( 'create_posts' => 'do_not_allow' ),
		'map_meta_cap' => true,
	) );
} );

const CS_PERSON_FIELDS = array(
	'first' => 'First name', 'last' => 'Last name', 'gender' => 'Gender', 'dob' => 'Date of birth',
	'email' => 'Email', 'phone' => 'Phone', 'address1' => 'Address line 1', 'address2' => 'Address line 2',
	'city' => 'City', 'postcode' => 'Postcode', 'country' => 'Country', 'kit' => 'Kit size',
	'registered' => 'Registered on Pitchero', 'pitchero_id' => 'Pitchero user id',
);

/** Pitchero header (trimmed, lower case) → our field. Anything else is kept under extras. */
function cs_pitchero_map() {
	return array(
		'user id' => 'pitchero_id', 'registered' => 'registered', 'first name' => 'first', 'last name' => 'last',
		'gender' => 'gender', 'date of birth' => 'dob', 'email address' => 'email', 'address line 1' => 'address1',
		'address line 2' => 'address2', 'city' => 'city', 'postcode' => 'postcode', 'country' => 'country',
		'telephone' => 'phone', 'kit size' => 'kit',
	);
}

function cs_person( $id ) {
	$out = array( 'id' => (int) $id );
	foreach ( array_keys( CS_PERSON_FIELDS ) as $k ) {
		$out[ $k ] = (string) get_post_meta( $id, '_cs_' . $k, true );
	}
	$out['extras'] = (array) json_decode( (string) get_post_meta( $id, '_cs_extras', true ), true );
	return $out;
}

/** Age in whole years from Y-m-d, or null. */
function cs_age( $dob ) {
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $dob ) ) {
		return null;
	}
	return (int) date_diff( date_create( $dob ), date_create( current_time( 'Y-m-d' ) ) )->y;
}

/**
 * Import a Pitchero export file.
 *
 * @return array|WP_Error { rows, created, updated, skipped }
 */
function cs_pitchero_import( $path ) {
	$fh = fopen( $path, 'r' );
	if ( ! $fh ) {
		return new WP_Error( 'cs_pitchero_file', 'Could not open the file.' );
	}
	$head = fgetcsv( $fh );
	if ( ! $head ) {
		return new WP_Error( 'cs_pitchero_file', 'The file is empty.' );
	}
	$head[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $head[0] );
	$keys = array();
	$map  = cs_pitchero_map();
	foreach ( $head as $i => $h ) {
		$n = strtolower( trim( (string) $h ) );
		$keys[ $i ] = isset( $map[ $n ] ) ? $map[ $n ] : 'x:' . trim( (string) $h );
	}
	if ( ! in_array( 'pitchero_id', $keys, true ) || ! in_array( 'first', $keys, true ) ) {
		return new WP_Error( 'cs_pitchero_cols', 'This does not look like a Pitchero membership export: no User ID or First name column.' );
	}
	@set_time_limit( 600 );
	wp_defer_term_counting( true );
	wp_suspend_cache_invalidation( true );

	$existing = array();
	global $wpdb;
	foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_cs_pitchero_id'" ) as $r ) {
		$existing[ (string) $r->meta_value ] = (int) $r->post_id;
	}

	$n = array( 'rows' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0 );
	while ( false !== ( $row = fgetcsv( $fh ) ) ) {
		if ( 1 === count( $row ) && '' === trim( (string) $row[0] ) ) {
			continue;
		}
		$n['rows']++;
		$p = array();
		$x = array();
		foreach ( $keys as $i => $k ) {
			$v = trim( (string) ( $row[ $i ] ?? '' ) );
			if ( 'Hidden' === $v ) {
				$v = '';
			}
			if ( 0 === strpos( $k, 'x:' ) ) {
				if ( '' !== $v ) {
					$x[ substr( $k, 2 ) ] = $v;
				}
			} else {
				$p[ $k ] = $v;
			}
		}
		if ( '' === ( $p['pitchero_id'] ?? '' ) ) {
			$n['skipped']++;
			continue;
		}
		if ( ! empty( $p['dob'] ) && preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $p['dob'], $m ) ) {
			$p['dob'] = sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] ); // Pitchero writes UK dates
		}
		$p['email'] = strtolower( sanitize_email( $p['email'] ?? '' ) );
		if ( '' === $p['email'] && ! empty( $x['Email'] ) ) {
			$p['email'] = strtolower( sanitize_email( $x['Email'] ) );
		}
		if ( '' === ( $p['phone'] ?? '' ) && ! empty( $x['Contact'] ) && preg_match( '/(\+?\d[\d ]{9,})/', $x['Contact'], $m ) ) {
			$p['phone'] = trim( $m[1] );
		}
		$title = trim( ( $p['first'] ?? '' ) . ' ' . ( $p['last'] ?? '' ) ) ?: 'Pitchero ' . $p['pitchero_id'];
		$id    = $existing[ $p['pitchero_id'] ] ?? 0;
		if ( $id ) {
			wp_update_post( array( 'ID' => $id, 'post_title' => $title ) );
			$n['updated']++;
		} else {
			$id = wp_insert_post( array( 'post_type' => 'cs_person', 'post_status' => 'publish', 'post_title' => $title ) );
			if ( ! $id || is_wp_error( $id ) ) {
				$n['skipped']++;
				continue;
			}
			$existing[ $p['pitchero_id'] ] = $id;
			$n['created']++;
		}
		foreach ( CS_PERSON_FIELDS as $k => $label ) {
			update_post_meta( $id, '_cs_' . $k, (string) ( $p[ $k ] ?? '' ) );
		}
		update_post_meta( $id, '_cs_extras', wp_json_encode( $x ) );
		update_post_meta( $id, '_cs_source', 'pitchero' );
	}
	fclose( $fh );
	wp_suspend_cache_invalidation( false );
	wp_defer_term_counting( false );
	delete_transient( 'cs_people_by_email' );
	cs_log( 'pitchero import', $n );
	return $n;
}

/** email → [person ids], cached. */
function cs_people_by_email() {
	$idx = get_transient( 'cs_people_by_email' );
	if ( is_array( $idx ) ) {
		return $idx;
	}
	global $wpdb;
	$idx = array();
	foreach ( $wpdb->get_results( "SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_cs_email' AND m.meta_value <> '' AND p.post_type = 'cs_person' AND p.post_status = 'publish'" ) as $r ) {
		$idx[ strtolower( $r->meta_value ) ][] = (int) $r->post_id;
	}
	set_transient( 'cs_people_by_email', $idx, DAY_IN_SECONDS );
	return $idx;
}

/**
 * What the club knows about a payer email from Pitchero.
 *
 * @return array { children: "Amy, Ben", phone: "07…" }
 */
function cs_people_for_payer( $email ) {
	$email = strtolower( trim( (string) $email ) );
	$idx   = cs_people_by_email();
	$out   = array( 'children' => '', 'phone' => '' );
	if ( '' === $email || empty( $idx[ $email ] ) ) {
		return $out;
	}
	$kids = array();
	foreach ( $idx[ $email ] as $pid ) {
		$p   = cs_person( $pid );
		$age = cs_age( $p['dob'] );
		if ( null !== $age && $age < 18 ) {
			$kids[] = trim( $p['first'] . ' ' . $p['last'] ) . ' (' . $age . ')';
		}
		if ( '' === $out['phone'] && '' !== $p['phone'] ) {
			$out['phone'] = $p['phone'];
		}
	}
	$out['children'] = implode( ', ', $kids );
	return $out;
}

/* ---------------- Screens ---------------- */

add_action( 'admin_menu', function () {
	add_submenu_page( 'club-subs', 'Import from Pitchero', 'Import from Pitchero', 'manage_options', 'club-subs-pitchero', 'cs_pitchero_page' );
}, 25 );

function cs_pitchero_page() {
	$msg = '';
	if ( isset( $_POST['cs_pitchero_nonce'] ) && wp_verify_nonce( $_POST['cs_pitchero_nonce'], 'cs_pitchero' ) && current_user_can( 'manage_options' ) ) {
		if ( empty( $_FILES['cs_pitchero_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['cs_pitchero_csv']['tmp_name'] ) ) {
			$msg = '<div class="notice notice-error"><p>Choose the file Pitchero gave you first.</p></div>';
		} else {
			$res = cs_pitchero_import( $_FILES['cs_pitchero_csv']['tmp_name'] );
			$msg = is_wp_error( $res )
				? '<div class="notice notice-error"><p>' . esc_html( $res->get_error_message() ) . '</p></div>'
				: '<div class="notice notice-success"><p>' . (int) $res['rows'] . ' people read: ' . (int) $res['created'] . ' added, ' . (int) $res['updated'] . ' updated' . ( $res['skipped'] ? ', ' . (int) $res['skipped'] . ' skipped' : '' ) . '.</p></div>';
			delete_transient( 'cs_chase_' . cs_gc_env() . '_' . current_time( 'Ym' ) );
		}
	}
	$count = (int) wp_count_posts( 'cs_person' )->publish;
	?>
	<div class="wrap">
		<h1>Import from Pitchero</h1>
		<?php echo $msg; // built from escaped parts ?>
		<p>In Pitchero go to <strong>Membership → Export</strong>, tick the fields you want (leave Medical unticked unless you need it) and press Export. Then choose that file here.</p>
		<p>Running it again later updates people already here, matched on their Pitchero user id. <?php echo (int) $count; ?> people are held now.</p>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'cs_pitchero', 'cs_pitchero_nonce' ); ?>
			<input type="file" name="cs_pitchero_csv" accept=".csv,text/csv">
			<?php submit_button( 'Import', 'primary', 'cs_pitchero_go', false ); ?>
		</form>
		<p class="description">Children are linked to a Direct Debit when they are registered on Pitchero under the same email as the person paying. The chase list shows them, and a phone number, where GoCardless has none.</p>
	</div>
	<?php
}

add_filter( 'manage_cs_person_posts_columns', function () {
	return array( 'cb' => '<input type="checkbox">', 'title' => 'Name', 'dob' => 'Born', 'email' => 'Email', 'phone' => 'Phone', 'pays' => 'Direct Debit' );
} );
add_action( 'manage_cs_person_posts_custom_column', function ( $col, $id ) {
	$p = cs_person( $id );
	switch ( $col ) {
		case 'dob':   echo esc_html( $p['dob'] ? date( 'j M Y', strtotime( $p['dob'] ) ) . ' (' . cs_age( $p['dob'] ) . ')' : '' ); break;
		case 'email': echo esc_html( $p['email'] ); break;
		case 'phone': echo esc_html( $p['phone'] ); break;
		case 'pays':  echo $p['email'] && cs_member_by_gc( 'parent_email', $p['email'] ) ? 'yes' : ''; break;
	}
}, 10, 2 );

add_action( 'add_meta_boxes_cs_person', function () {
	add_meta_box( 'cs_person_box', 'From Pitchero', function ( $post ) {
		$p = cs_person( $post->ID );
		echo '<table class="form-table">';
		foreach ( CS_PERSON_FIELDS as $k => $label ) {
			if ( '' !== $p[ $k ] ) {
				echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $p[ $k ] ) . '</td></tr>';
			}
		}
		foreach ( $p['extras'] as $k => $v ) {
			echo '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
		}
		echo '</table>';
	} );
} );

/* Search People by email as well as name. */
add_filter( 'posts_search', function ( $search, $q ) {
	global $wpdb;
	if ( ! is_admin() || 'cs_person' !== $q->get( 'post_type' ) || '' === (string) $q->get( 's' ) ) {
		return $search;
	}
	$like = '%' . $wpdb->esc_like( $q->get( 's' ) ) . '%';
	return $wpdb->prepare( " AND ({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cs_email' AND meta_value LIKE %s)) ", $like, $like );
}, 10, 2 );
