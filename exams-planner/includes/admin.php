<?php
/**
 * Σελίδα διαχείρισης: Ρυθμίσεις, Δηλώσεις, Ιστορικό.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function () {
	add_menu_page(
		'Exams Planner',
		'Exams Planner',
		'manage_options',
		'exams-planner',
		'dgn_admin_page',
		'dashicons-calendar-alt',
		30
	);
} );

add_filter( 'plugin_action_links_' . plugin_basename( DGN_FILE ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=exams-planner' ) ) . '">Ρυθμίσεις</a>' );
	return $links;
} );

add_action( 'admin_post_dgn_save', 'dgn_admin_save' );
add_action( 'admin_post_dgn_delete', 'dgn_admin_delete' );
add_action( 'admin_post_dgn_purge', 'dgn_admin_purge' );
add_action( 'admin_post_dgn_clear_log', 'dgn_admin_clear_log' );
add_action( 'admin_post_dgn_create_page', 'dgn_admin_create_page' );
add_action( 'admin_post_dgn_fill_holidays', 'dgn_admin_fill_holidays' );
add_action( 'admin_post_dgn_draseis', 'dgn_admin_draseis_action' );

/** «Επαναφορά πανελλαδικών αργιών»: ξαναγράφει το πεδίο με τις αργίες της τρέχουσας σχολικής χρονιάς. */
function dgn_admin_fill_holidays() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.' );
	}
	check_admin_referer( 'dgn_fill_holidays' );
	$s                  = get_option( DGN_OPTION );
	$sy                 = (int) substr( dgn_school_year()[0], 0, 4 );
	$s['holidays']      = dgn_national_holidays_text( $sy );
	$s['holidays_year'] = $sy;
	update_option( DGN_OPTION, $s, false );
	wp_safe_redirect( dgn_admin_url( 'settings', array( 'dgn_msg' => 'holfilled' ) ) );
	exit;
}

/**
 * «Δημιουργία σελίδας»: φτιάχνει πρόχειρη σελίδα «Προγραμματισμός Διαγωνισμάτων» με το [examsplanner]
 * και διεύθυνση /exams-planner/, και ανοίγει τον επεξεργαστή για «Δημοσίευση».
 * Αν υπάρχει ήδη πρόχειρη σελίδα με το shortcode, ανοίγει αυτήν (δεν φτιάχνει δεύτερη).
 */
function dgn_admin_create_page() {
	global $wpdb;
	if ( ! current_user_can( 'publish_pages' ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.' );
	}
	check_admin_referer( 'dgn_create_page' );
	$id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status IN ('draft','pending','future','private') AND post_content LIKE '%[examsplanner%' ORDER BY ID DESC LIMIT 1" );
	if ( ! $id ) {
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Προγραμματισμός Διαγωνισμάτων',
				'post_name'    => 'exams-planner',
				'post_content' => '[examsplanner]',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_die( esc_html( 'Η σελίδα δεν δημιουργήθηκε: ' . $id->get_error_message() ) );
		}
	}
	wp_safe_redirect( admin_url( 'post.php?post=' . (int) $id . '&action=edit' ) );
	exit;
}

/**
 * Σύνδεση με το Draseis Planner (1.13.0).
 * Το Draseis προσφέρει τη σταθερή δημόσια συνάρτηση draseis_planner_classes() (από την έκδοση 1.4.73). Η κοινή ρύθμιση
 * WordPress «draseis_exams_link» είναι 'yes' (συνδεδεμένα), 'no' (ανεξάρτητα) ή κενή (δεν έχει απαντηθεί). Κάθε πρόσθετο
 * γράφει μόνο τις δικές του ρυθμίσεις, εκτός από αυτή την κοινή τιμή.
 */
function dgn_draseis_present() {
	return function_exists( 'draseis_planner_classes' );
}

/** Τα τμήματα του Draseis, περασμένα από τον καθαρισμό του Exams (γραμμές χωρίς κενά και διπλότυπα, έως 20 χαρακτήρες). */
function dgn_draseis_classes() {
	if ( ! dgn_draseis_present() ) {
		return array();
	}
	$list = draseis_planner_classes();
	$out  = array();
	foreach ( is_array( $list ) ? $list : array() as $c ) {
		$c = is_string( $c ) ? trim( sanitize_text_field( $c ) ) : '';
		if ( '' !== $c && mb_strlen( $c ) <= 20 && ! in_array( $c, $out, true ) ) {
			$out[] = $c;
		}
	}
	return $out;
}

/** 'yes', 'no' ή '' (δεν έχει απαντηθεί). */
function dgn_draseis_link() {
	$v = get_option( 'draseis_exams_link', '' );
	return in_array( $v, array( 'yes', 'no' ), true ) ? $v : '';
}

/**
 * Σύγκριση των τμημάτων των δύο πρόσθετων.
 *
 * @return array{draseis:string[],same:bool,leaving:string[],declared:string[]} leaving: τμήματα του Exams που λείπουν από το Draseis·
 *         declared: από αυτά, όσα έχουν δηλώσεις (οποιασδήποτε ημερομηνίας) στο Exams.
 */
function dgn_draseis_compare( $s ) {
	global $wpdb;
	$dr      = dgn_draseis_classes();
	$ex      = dgn_classes( $s );
	$leaving = array_values( array_diff( $ex, $dr ) );
	$same    = ! $leaving && ! array_diff( $dr, $ex );
	$declared = array();
	if ( $leaving ) {
		$t   = dgn_table( 'exams' );
		$has = $wpdb->get_col( "SELECT DISTINCT cls FROM $t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$declared = array_values( array_intersect( $leaving, $has ) );
	}
	return array( 'draseis' => $dr, 'same' => $same, 'leaving' => $leaving, 'declared' => $declared );
}

/** Σύνδεσμος-κουμπί προς τον χειριστή (με nonce). */
function dgn_draseis_btn( $do, $label, $primary ) {
	return '<a class="button' . ( $primary ? ' button-primary' : '' ) . '" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dgn_draseis&do=' . $do ), 'dgn_draseis' ) ) . '">' . esc_html( $label ) . '</a> ';
}

/** Το μπλε μήνυμα πάνω από το «Τύπος σχολείου»: μόνο όταν υπάρχει το Draseis και δεν έχει απαντηθεί η σύνδεση. */
function dgn_admin_draseis_box( $s ) {
	if ( ! dgn_draseis_present() || '' !== dgn_draseis_link() ) {
		return;
	}
	$c = dgn_draseis_compare( $s );
	echo '<div class="notice notice-info inline" style="max-width:1100px;margin:18px 0"><p><strong>Βρέθηκε το Draseis Planner (πρόγραμμα δράσεων).</strong></p>';
	if ( ! $c['draseis'] ) {
		echo '<p>Το Draseis Planner δεν έχει ακόμη τμήματα. Αν συνδεθείτε, θα χρησιμοποιεί τα τμήματα αυτής της σελίδας και δεν θα επιτρέπεται διαγώνισμα και δράση για το ίδιο τμήμα την ίδια ημέρα.</p><p>';
		echo dgn_draseis_btn( 'yes', 'Σύνδεση με τα τμήματα του Exams Planner', true ) . dgn_draseis_btn( 'no', 'Όχι, ανεξάρτητα', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped μέσα στη συνάρτηση
	} elseif ( $c['same'] ) {
		echo '<p>Τα τμήματα στα δύο πρόσθετα είναι ίδια. Να συνδεθούν; Το Draseis θα χρησιμοποιεί τα τμήματα αυτής της σελίδας και δεν θα επιτρέπεται διαγώνισμα και δράση για το ίδιο τμήμα την ίδια ημέρα.</p><p>';
		echo dgn_draseis_btn( 'yes', 'Ναι, σύνδεση', true ) . dgn_draseis_btn( 'no', 'Όχι, ανεξάρτητα', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} elseif ( ! $c['declared'] ) {
		echo '<p>Το Draseis Planner έχει τα τμήματα: <code>' . implode( '</code> <code>', array_map( 'esc_html', $c['draseis'] ) ) . '</code></p>';
		echo '<p>Μπορείτε να χρησιμοποιήσετε τα ίδια τμήματα και εδώ. Θα αντικαταστήσουν τη λίστα «Τμήματα» αυτής της σελίδας, και τα δύο πρόσθετα θα συνδεθούν: δεν θα επιτρέπεται διαγώνισμα και δράση για το ίδιο τμήμα την ίδια ημέρα.</p><p>';
		echo dgn_draseis_btn( 'copy', 'Χρήση αυτών των τμημάτων', true ) . dgn_draseis_btn( 'no', 'Όχι, ευχαριστώ', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} else {
		$n = count( $c['declared'] );
		echo '<p>Τα τμήματα των δύο πρόσθετων διαφέρουν: ' . ( 1 === $n ? 'το ' : 'τα ' ) . '<strong>' . esc_html( implode( ', ', $c['declared'] ) ) . '</strong> ' . ( 1 === $n ? 'έχει' : 'έχουν' ) . ' δηλώσεις στο Exams Planner αλλά ' . ( 1 === $n ? 'δεν υπάρχει' : 'δεν υπάρχουν' ) . ' στο Draseis.</p>';
		echo '<p>Για να μη χαθούν δηλώσεις, τα τμήματα δεν αντιγράφονται. Αν συνδεθείτε, το Draseis θα χρησιμοποιεί τα τμήματα του Exams Planner· τίποτα δεν αλλάζει εδώ.</p><p>';
		echo dgn_draseis_btn( 'yes', 'Σύνδεση με τα τμήματα του Exams Planner', true ) . dgn_draseis_btn( 'no', 'Όχι, ανεξάρτητα', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</p></div>';
}

/** Γραμμή «Σύνδεση με το Draseis Planner» στην ενότητα 1 των Ρυθμίσεων (μόνο όταν υπάρχει το Draseis). */
function dgn_admin_draseis_row() {
	if ( ! dgn_draseis_present() ) {
		return;
	}
	$dl = dgn_draseis_link();
	?>
			<tr>
				<th scope="row">Σύνδεση με το Draseis Planner</th>
				<td>
					<fieldset>
						<label style="margin-right:18px"><input type="radio" name="draseis_link" value="yes" <?php checked( $dl, 'yes' ); ?>> <strong>Ναι</strong>, συνδεδεμένα</label>
						<label><input type="radio" name="draseis_link" value="no" <?php checked( $dl, 'no' ); ?>> <strong>Όχι</strong>, ανεξάρτητα</label>
					</fieldset>
					<p class="description">Όταν είναι συνδεδεμένα, το Draseis Planner χρησιμοποιεί τα τμήματα αυτής της σελίδας και δεν επιτρέπει διαγώνισμα και δράση για το ίδιο τμήμα την ίδια ημέρα.</p>
				</td>
			</tr>
	<?php
}

/** Κουμπιά του μηνύματος: yes / no / copy. Ελέγχεται ξανά στον server πριν από κάθε αντιγραφή. */
function dgn_admin_draseis_action() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.' );
	}
	check_admin_referer( 'dgn_draseis' );
	$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
	if ( ! dgn_draseis_present() || ! in_array( $do, array( 'yes', 'no', 'copy' ), true ) ) {
		wp_safe_redirect( dgn_admin_url( 'settings' ) );
		exit;
	}
	$s   = dgn_settings();
	$msg = 'draseislinked';
	$args = array();
	if ( 'no' === $do ) {
		update_option( 'draseis_exams_link', 'no' );
		$msg = 'draseisunlinked';
	} elseif ( 'yes' === $do ) {
		update_option( 'draseis_exams_link', 'yes' );
	} else {
		$c = dgn_draseis_compare( $s );
		if ( ! $c['draseis'] || $c['declared'] ) {
			// Ο έλεγχος γίνεται ξανά εδώ: αν στο μεταξύ προστέθηκαν δηλώσεις, τα τμήματα δεν αντικαθίστανται.
			wp_safe_redirect( dgn_admin_url( 'settings', array( 'dgn_msg' => 'draseisblocked' ) ) );
			exit;
		}
		$raw            = get_option( DGN_OPTION, array() );
		$raw            = is_array( $raw ) ? $raw : array();
		$raw['classes'] = implode( "\n", $c['draseis'] );
		update_option( DGN_OPTION, $raw, false );
		update_option( 'draseis_exams_link', 'yes' );
		$msg    = 'draseiscopied';
		$nograde = array_values( array_filter( $c['draseis'], function ( $x ) {
			return '' === dgn_class_grade( $x );
		} ) );
		if ( $nograde ) {
			$msg .= ',draseisnograde';
			$args['names'] = implode( ', ', $nograde );
		}
	}
	wp_safe_redirect( dgn_admin_url( 'settings', array_merge( array( 'dgn_msg' => $msg ), $args ) ) );
	exit;
}

function dgn_admin_url( $tab = 'settings', $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => 'exams-planner', 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
}

function dgn_admin_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.' );
	}
	check_admin_referer( 'dgn_save' );
	$old = dgn_settings();
	$in  = wp_unslash( $_POST );

	$new                = $old;
	$new['classes']     = sanitize_textarea_field( isset( $in['classes'] ) ? $in['classes'] : '' );
	$new['subjects']    = sanitize_textarea_field( isset( $in['subjects'] ) ? $in['subjects'] : '' );
	$new['holidays']       = sanitize_textarea_field( isset( $in['holidays'] ) ? $in['holidays'] : '' );
	$new['holidays_local'] = sanitize_textarea_field( isset( $in['holidays_local'] ) ? $in['holidays_local'] : '' );
	$new['holidays_auto']  = empty( $in['holidays_auto'] ) ? 0 : 1;
	$new['holidays_year']  = isset( $old['holidays_year'] ) ? (int) $old['holidays_year'] : (int) substr( dgn_school_year()[0], 0, 4 );
	unset( $new['holidays_refreshed'], $new['holidays_eves_note'], $new['subjects_grades_note'], $new['subjects_grades_done'] );
	$new['holidays_eves_done']   = 1;
	$new['weekly_max']  = min( 5, max( 1, (int) ( isset( $in['weekly_max'] ) ? $in['weekly_max'] : 3 ) ) );
	$new['access']      = ( isset( $in['access'] ) && in_array( $in['access'], array( 'users', 'sso' ), true ) ) ? $in['access'] : 'code';
	$new['sso_server']  = ( isset( $in['sso_server'] ) && 'test' === $in['sso_server'] ) ? 'test' : 'prod';
	$new['sso_service'] = esc_url_raw( trim( isset( $in['sso_service'] ) ? $in['sso_service'] : '' ) );
	$new['sso_units']   = sanitize_text_field( isset( $in['sso_units'] ) ? $in['sso_units'] : '' );
	$new['sso_roles']   = sanitize_text_field( isset( $in['sso_roles'] ) ? $in['sso_roles'] : 'teacher, ateacher, pteacher' );
	if ( '' === trim( $new['sso_roles'] ) ) {
		$new['sso_roles'] = 'teacher, ateacher, pteacher';
	}
	if ( $new['access'] !== $old['access'] || $new['sso_server'] !== $old['sso_server'] || $new['sso_units'] !== $old['sso_units'] || $new['sso_roles'] !== $old['sso_roles'] ) {
		$new['sso_ver'] = (int) $old['sso_ver'] + 1; // αλλαγή κανόνων εισόδου: όλοι ξαναμπαίνουν
	}
	$new['school_name'] = sanitize_text_field( isset( $in['school_name'] ) ? $in['school_name'] : '' );
	$new['teachers']    = sanitize_textarea_field( isset( $in['teachers'] ) ? $in['teachers'] : '' );
	$new['public_view'] = empty( $in['public_view'] ) ? 0 : 1;
	$new['uninstall_wipe'] = empty( $in['uninstall_wipe'] ) ? 0 : 1;
	$new['allow_past']  = 0; // καταργήθηκε (1.9.80): οι καθηγητές δεν δηλώνουν περασμένες ημερομηνίες
	$new['min_notice']  = min( 30, max( 0, (int) ( isset( $in['min_notice'] ) ? $in['min_notice'] : 0 ) ) );

	// Τύπος σχολείου (1.11.0). Ο οριστικός τύπος δεν αλλάζει ποτέ από τη φόρμα.
	$type_set = false;
	if ( ! dgn_type_locked( $old ) ) {
		$choice = isset( $in['school_type'] ) && in_array( $in['school_type'], array( 'gym', 'lyk' ), true ) ? $in['school_type'] : (string) $old['school_type_choice'];
		$new['school_type_choice'] = in_array( $choice, array( 'gym', 'lyk' ), true ) ? $choice : '';
		if ( ! empty( $in['confirm_type'] ) && '' !== $new['school_type_choice'] ) {
			$new['school_type'] = $new['school_type_choice'];
			$type_set           = true;
		}
	}
	// Ομάδες προσανατολισμού: μόνο αν η φόρμα τις έστειλε (το JS της σελίδας προσθέτει το groups_present).
	// Έτσι, αν για κάποιο λόγο δεν φορτώσει το JS, οι αποθηκευμένες ομάδες δεν σβήνονται.
	if ( ! empty( $in['groups_present'] ) ) {
		$list     = array();
		$cl       = dgn_classes( $new ); // η ΝΕΑ λίστα τμημάτων: τα τμήματα κάθε ομάδας ελέγχονται απέναντί της
		$gc_lost  = false;
		foreach ( ( isset( $in['groups'] ) && is_array( $in['groups'] ) ? $in['groups'] : array() ) as $g ) {
			$posted = is_array( $g ) && isset( $g['classes'] ) && is_array( $g['classes'] ) ? $g['classes'] : array();
			$g      = dgn_clean_group( $g, $cl );
			if ( $g && count( $list ) < 30 ) {
				// Είχαν επιλεγεί τμήματα, αλλά κανένα δεν υπάρχει πια στην τάξη της ομάδας: η ομάδα αφορά όλη την τάξη.
				if ( $posted && ! $g['classes'] ) {
					$any = false;
					foreach ( $posted as $c ) {
						if ( is_string( $c ) && in_array( trim( $c ), $cl, true ) && dgn_class_grade( $c ) === $g['grade'] ) {
							$any = true;
							break;
						}
					}
					$gc_lost = $gc_lost || ! $any;
				}
				$list[] = $g;
			}
		}
		$new['groups'] = $list;
	}
	if ( 'gym' === dgn_effective_type( $new ) ) {
		$new['groups'] = array(); // το Γυμνάσιο δεν έχει ομάδες προσανατολισμού
		$gc_lost       = false;
	}

	// Χωρίς sanitize_text_field: αφαιρεί σιωπηλά ακολουθίες όπως «%2a» και αλλάζει τον κωδικό.
	// Ο κωδικός ελέγχεται με τους κανόνες του (μόνο εκτυπώσιμοι λατινικοί χαρακτήρες) και στην οθόνη περνά από esc_attr/esc_html.
	$code = trim( isset( $in['code'] ) && is_string( $in['code'] ) ? $in['code'] : '' );
	// Ο κωδικός ελέγχεται όταν αλλάζει, και όταν η είσοδος γίνεται «με κωδικό» ενώ πριν δεν ήταν.
	$check = ( '' !== $code && $code !== $old['code'] ) ? $code : ( ( 'code' === $new['access'] && 'code' !== $old['access'] ) ? $old['code'] : null );
	if ( null !== $check && dgn_code_problems( $check ) ) {
		wp_safe_redirect( dgn_admin_url( 'settings', array( 'dgn_msg' => 'weakcode' ) ) );
		exit;
	}
	if ( '' !== $code && $code !== $old['code'] ) {
		$new['code']     = $code;
		$new['code_ver'] = (int) $old['code_ver'] + 1; // αποσυνδέει όσους μπήκαν με τον παλιό κωδικό
	}

	$errors = array();
	if ( ! dgn_classes( $new ) ) {
		$errors[] = 'noclasses';
	}
	if ( ! dgn_subjects( $new ) ) {
		$errors[] = 'nosubjects';
	}
	list( , $bad )  = dgn_parse_holidays( $new['holidays'] );
	list( , $bad2 ) = dgn_parse_holidays( $new['holidays_local'] );
	$bad            = array_merge( $bad, $bad2 );
	if ( $errors ) {
		wp_safe_redirect( dgn_admin_url( 'settings', array( 'dgn_msg' => implode( ',', $errors ) ) ) );
		exit;
	}
	update_option( DGN_OPTION, $new, false );
	// Σύνδεση με το Draseis (1.13.0): γράφεται μόνο αν υπάρχει το Draseis και η φόρμα έστειλε επιλογή.
	if ( dgn_draseis_present() && isset( $in['draseis_link'] ) && in_array( $in['draseis_link'], array( 'yes', 'no' ), true ) ) {
		update_option( 'draseis_exams_link', $in['draseis_link'] );
	}
	$msg = $bad ? 'savedbad' : 'saved';
	if ( $type_set ) {
		$msg = ( 'lyk' === $new['school_type'] ? 'typelyk' : 'typegym' ) . ( $bad ? ',savedbad' : '' );
	}
	if ( ! empty( $gc_lost ) ) {
		$msg .= ',grpnoclass';
	}
	wp_safe_redirect( dgn_admin_url( 'settings', array( 'dgn_msg' => $msg ) ) );
	exit;
}

function dgn_admin_delete() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.' );
	}
	$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
	check_admin_referer( 'dgn_delete_' . $id );
	$exam = dgn_exam_get( $id );
	if ( $exam ) {
		dgn_exam_delete( $exam, 'Διαχειριστής: ' . wp_get_current_user()->display_name );
	}
	wp_safe_redirect( dgn_admin_url( 'exams', array( 'dgn_msg' => 'deleted' ) ) );
	exit;
}

function dgn_admin_purge() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.' );
	}
	check_admin_referer( 'dgn_purge' );
	$confirm = isset( $_POST['confirm'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) ) : '';
	$before  = isset( $_POST['before'] ) ? dgn_parse_date( sanitize_text_field( wp_unslash( $_POST['before'] ) ) ) : null;
	if ( 'ΔΙΑΓΡΑΦΗ' !== $confirm || ! $before ) {
		wp_safe_redirect( dgn_admin_url( 'exams', array( 'dgn_msg' => 'purgefail' ) ) );
		exit;
	}
	$t = dgn_table( 'exams' );
	$n = $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE exam_date < %s", $before->format( 'Y-m-d' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	dgn_log( 'εκκαθάριση', array( 'cls' => '—', 'exam_date' => $before->format( 'Y-m-d' ), 'subject' => (int) $n . ' δηλώσεις', 'teacher' => '' ), 'Διαχειριστής: ' . wp_get_current_user()->display_name );
	wp_safe_redirect( dgn_admin_url( 'exams', array( 'dgn_msg' => 'purged', 'n' => (int) $n ) ) );
	exit;
}

/** Διαγραφή όλου του ιστορικού (με επιβεβαίωση «ΔΙΑΓΡΑΦΗ»). Οι δηλώσεις δεν επηρεάζονται. */
function dgn_admin_clear_log() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Δεν έχετε δικαίωμα.' );
	}
	check_admin_referer( 'dgn_clear_log' );
	$confirm = isset( $_POST['confirm'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) ) : '';
	if ( 'ΔΙΑΓΡΑΦΗ' !== $confirm ) {
		wp_safe_redirect( dgn_admin_url( 'log', array( 'dgn_msg' => 'clearfail' ) ) );
		exit;
	}
	$t = dgn_table( 'log' );
	$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DELETE FROM $t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// Η στήλη «ενέργεια» δέχεται έως 12 χαρακτήρες.
	dgn_log( 'καθαρισμός', array( 'cls' => '—', 'exam_date' => dgn_today(), 'subject' => 'ιστορικού: ' . $n . ' εγγραφές', 'teacher' => '' ), 'Διαχειριστής: ' . wp_get_current_user()->display_name );
	wp_safe_redirect( dgn_admin_url( 'log', array( 'dgn_msg' => 'cleared', 'n' => $n ) ) );
	exit;
}

function dgn_admin_notice( $msg ) {
	$map = array(
		'saved'      => array( 'success', 'Οι ρυθμίσεις αποθηκεύτηκαν.' ),
		'typegym'    => array( 'success', 'Ο τύπος σχολείου ορίστηκε οριστικά: Γυμνάσιο. Οι ρυθμίσεις αποθηκεύτηκαν.' ),
		'typelyk'    => array( 'success', 'Ο τύπος σχολείου ορίστηκε οριστικά: Λύκειο. Οι ρυθμίσεις αποθηκεύτηκαν.' ),
		'holfilled'  => array( 'success', 'Οι πανελλαδικές αργίες επαναφέρθηκαν για την τρέχουσα σχολική χρονιά.' ),
		'grpnoclass' => array( 'warning', 'Μια ομάδα είχε επιλεγμένα τμήματα που δεν υπάρχουν πια στη λίστα «Τμήματα». Η ομάδα αφορά τώρα όλη την τάξη της· ελέγξτε τα τμήματα κάθε ομάδας.' ),
		'draseislinked'   => array( 'success', 'Τα δύο πρόσθετα συνδέθηκαν: το Draseis Planner χρησιμοποιεί τα τμήματα αυτής της σελίδας.' ),
		'draseisunlinked' => array( 'success', 'Τα δύο πρόσθετα λειτουργούν ανεξάρτητα.' ),
		'draseiscopied'   => array( 'success', 'Τα τμήματα αντιγράφηκαν από το Draseis Planner και τα δύο πρόσθετα συνδέθηκαν.' ),
		'draseisblocked'  => array( 'error', 'Τα τμήματα δεν αντιγράφηκαν: κάποιο τμήμα του Exams Planner έχει δηλώσεις. Διαβάστε ξανά το μήνυμα.' ),
		'draseisnograde'  => array( 'warning', 'Ελέγξτε τα τμήματα που αντιγράφηκαν: ' . ( isset( $_GET['names'] ) ? sanitize_text_field( wp_unslash( $_GET['names'] ) ) : '' ) . ' (δεν ξεκινούν με γράμμα τάξης, π.χ. Α, Β, Γ· γι\' αυτά δεν ισχύουν οι κανόνες τάξης).' ),
		'savedbad'   => array( 'warning', 'Οι ρυθμίσεις αποθηκεύτηκαν, αλλά κάποιες γραμμές στις αργίες δεν αναγνωρίστηκαν και αγνοούνται (δείτε παρακάτω).' ),
		'noclasses'  => array( 'error', 'Δεν αποθηκεύτηκε: γράψτε τουλάχιστον ένα τμήμα.' ),
		'nosubjects' => array( 'error', 'Δεν αποθηκεύτηκε: γράψτε τουλάχιστον ένα μάθημα.' ),
		'weakcode'   => array( 'error', 'Δεν αποθηκεύτηκε: ο κωδικός καθηγητών πρέπει να έχει τουλάχιστον 8 λατινικούς χαρακτήρες, με κεφαλαίο, μικρό, αριθμό και ένα σύμβολο (# $ ! %).' ),
		'deleted'    => array( 'success', 'Η δήλωση διαγράφηκε.' ),
		'purged'     => array( 'success', sprintf( 'Διαγράφηκαν %d δηλώσεις.', isset( $_GET['n'] ) ? (int) $_GET['n'] : 0 ) ),
		'purgefail'  => array( 'error', 'Η εκκαθάριση δεν έγινε: γράψτε ΔΙΑΓΡΑΦΗ με κεφαλαία και επιλέξτε ημερομηνία.' ),
		'cleared'    => array( 'success', sprintf( 'Το ιστορικό διαγράφηκε (%d εγγραφές).', isset( $_GET['n'] ) ? (int) $_GET['n'] : 0 ) ),
		'clearfail'  => array( 'error', 'Η διαγραφή ιστορικού δεν έγινε: γράψτε ΔΙΑΓΡΑΦΗ με κεφαλαία.' ),
	);
	foreach ( explode( ',', $msg ) as $m ) {
		if ( isset( $map[ $m ] ) ) {
			printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $map[ $m ][0] ), esc_html( $map[ $m ][1] ) );
		}
	}
}

function dgn_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
	$tabs = array(
		'settings' => 'Ρυθμίσεις',
		'exams'    => 'Δηλώσεις',
		'log'      => 'Ιστορικό',
	);
	// Παράδειγμα του άλλου τύπου σχολείου (1.12.0): μόνο αφού ο τύπος οριστεί οριστικά.
	$s_tabs = dgn_settings();
	if ( dgn_type_locked( $s_tabs ) ) {
		$tabs['example'] = 'lyk' === $s_tabs['school_type'] ? 'Παράδειγμα Γυμνασίου' : 'Παράδειγμα Λυκείου';
	}
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = 'settings';
	}
	echo '<div class="wrap"><h1>Exams Planner</h1>';
	if ( isset( $_GET['dgn_msg'] ) ) {
		dgn_admin_notice( sanitize_text_field( wp_unslash( $_GET['dgn_msg'] ) ) );
	}
	echo '<nav class="nav-tab-wrapper">';
	foreach ( $tabs as $k => $label ) {
		printf( '<a class="nav-tab%s" href="%s">%s</a>', $k === $tab ? ' nav-tab-active' : '', esc_url( dgn_admin_url( $k ) ), esc_html( $label ) );
	}
	echo '</nav>';
	call_user_func( 'dgn_admin_tab_' . $tab );
	echo '</div>';
}

/** Δημοσιευμένες σελίδες που περιέχουν το shortcode. */
function dgn_app_pages() {
	global $wpdb;
	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') AND post_content LIKE '%[examsplanner%' LIMIT 5" );
	return array_map( 'intval', $ids );
}

function dgn_admin_tab_settings() {
	$s = dgn_settings();
	list( , $bad )      = dgn_parse_holidays( $s['holidays'] );
	list( , $bad_local ) = dgn_parse_holidays( $s['holidays_local'] );
	$sy                 = (int) substr( dgn_school_year()[0], 0, 4 );
	if ( ! empty( $s['holidays_eves_note'] ) ) {
		$opt = get_option( DGN_OPTION );
		unset( $opt['holidays_eves_note'] );
		update_option( DGN_OPTION, $opt, false );
		echo '<div class="notice notice-info inline" style="max-width:780px"><p>Προστέθηκαν οι σχολικές εορτές της 28ης Οκτωβρίου και της 25ης Μαρτίου στις πανελλαδικές αργίες.</p></div>';
	}
	if ( ! empty( $s['holidays_refreshed'] ) ) {
		// Μήνυμα μία φορά, μετά την αυτόματη ενημέρωση της νέας χρονιάς.
		$opt = get_option( DGN_OPTION );
		unset( $opt['holidays_refreshed'] );
		update_option( DGN_OPTION, $opt, false );
		echo '<div class="notice notice-info inline" style="max-width:780px"><p>Οι πανελλαδικές αργίες ενημερώθηκαν αυτόματα για το σχολικό έτος ' . (int) $s['holidays_refreshed'] . '–' . ( (int) $s['holidays_refreshed'] + 1 ) . '. Ελέγξτε αν χρειάζεται κάποια διόρθωση.</p></div>';
	}
	?>
	<?php $pages = dgn_app_pages(); ?>
	<?php if ( ! $pages ) : ?>
		<div class="notice notice-warning inline" style="margin:16px 0;max-width:780px;padding:10px 14px">
			<p style="margin:.3em 0 .6em"><strong>Το πρόγραμμα δεν εμφανίζεται ακόμα στην ιστοσελίδα.</strong> Για να το δουν καθηγητές, μαθητές και γονείς:</p>
			<ol style="margin:0 0 .6em 1.6em;line-height:1.9">
				<li>Πατήστε <strong>Δημιουργία σελίδας</strong>: ανοίγει έτοιμη η σελίδα «Προγραμματισμός Διαγωνισμάτων».</li>
				<li>Στη σελίδα που ανοίγει, πατήστε <strong>Δημοσίευση</strong>.</li>
			</ol>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:.4em 0 .5em">
				<input type="hidden" name="action" value="dgn_create_page">
				<?php wp_nonce_field( 'dgn_create_page' ); ?>
				<button type="submit" class="button button-primary">Δημιουργία σελίδας</button>
			</form>
			<p style="margin:.2em 0 .4em;color:#646970">Εναλλακτικά: Σελίδες → Προσθήκη νέας, γράψτε μέσα στη σελίδα <code>[examsplanner]</code> και πατήστε Δημοσίευση.</p>
		</div>
	<?php endif; ?>
	<div style="background:#fff;border:1px solid #c3c4c7;padding:12px 16px;margin:16px 0;max-width:780px;line-height:1.7">
		<?php if ( $pages ) : ?>
			<?php foreach ( $pages as $p ) : ?>
				<strong>Σελίδα προγράμματος:</strong> <a href="<?php echo esc_url( get_permalink( $p ) ); ?>" target="_blank"><?php echo esc_html( get_permalink( $p ) ); ?></a><br>
				<?php if ( 'code' === $s['access'] ) : ?>
					<strong>Σελίδα εισόδου καθηγητών:</strong> <a href="<?php echo esc_url( add_query_arg( 'dgn', 'login', get_permalink( $p ) ) ); ?>" target="_blank"><?php echo esc_html( add_query_arg( 'dgn', 'login', get_permalink( $p ) ) ); ?></a><br>
				<?php endif; ?>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php if ( 'code' === $s['access'] ) : ?>
			<strong>Κωδικός καθηγητών:</strong> <code style="font-size:15px"><?php echo esc_html( $s['code'] ); ?></code><br>
		<?php endif; ?>
		<span style="color:#646970">Αν χρησιμοποιείτε plugin cache (π.χ. WP Fastest Cache, LiteSpeed, WP Rocket), εξαιρέστε <?php
		// Το όνομα (slug) της σελίδας όπως είναι στη διεύθυνσή της, π.χ. «exams-planner».
		$slugs = array_filter( array_map( function ( $p ) { return get_post_field( 'post_name', $p ); }, $pages ) );
		if ( $slugs ) {
			echo 'τη σελίδα <code>' . implode( '</code>, <code>', array_map( 'esc_html', $slugs ) ) . '</code>';
		} else {
			echo 'τη σελίδα του προγράμματος';
		}
		?> από την cache, ώστε να εμφανίζεται πάντα η σωστή ημερομηνία και η τρέχουσα έκδοση.</span>
	</div>
	<?php dgn_admin_settings_css(); ?>
	<?php
	$locked     = dgn_type_locked( $s );
	$etype      = dgn_effective_type( $s );
	$type_names = array( 'gym' => 'Γυμνάσιο', 'lyk' => 'Λύκειο' );
	// Οι ομάδες όπως είναι αποθηκευμένες (και όσες αγνοούνται λόγω ίδιου ονόματος, για να φαίνονται και να διορθωθούν).
	$form_groups = array_values( array_filter( array_map( 'dgn_clean_group', is_array( $s['groups'] ) ? $s['groups'] : array() ) ) );
	dgn_admin_draseis_box( $s ); // μόνο όταν υπάρχει το Draseis Planner και δεν έχει απαντηθεί η σύνδεση
	?>
	<form class="dgn-settings" data-type="<?php echo esc_attr( $etype ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="dgn_save">
		<?php wp_nonce_field( 'dgn_save' ); ?>
		<?php if ( $locked ) : ?>
			<div class="dgn-typebox dgn-typebox-done">
				<p style="margin:0"><strong>Τύπος σχολείου: <?php echo esc_html( $type_names[ $s['school_type'] ] ); ?></strong> (οριστικό)</p>
				<p class="description" style="margin:4px 0 0">Προσοχή: αν ο τύπος του σχολείου ορίστηκε κατά λάθος και θέλετε να τον αλλάξετε, θα πρέπει να διαγράψετε το πρόσθετο και να το εγκαταστήσετε ξανά. Πριν από τη διαγραφή, πηγαίνετε στην ενότητα «Δεδομένα», στο τέλος αυτής της σελίδας, και επιλέξτε «Να σβήνονται όλα τα δεδομένα όταν διαγράφεται το πρόσθετο». Αποθηκεύστε τις ρυθμίσεις και μετά διαγράψτε το πρόσθετο. Έτσι θα σβηστούν οι δηλώσεις, το ιστορικό και οι ρυθμίσεις, και το πρόσθετο θα ξεκινήσει από την αρχή.</p>
			</div>
		<?php else : ?>
			<div class="dgn-typebox">
				<h2 class="dgn-typebox-h">Τύπος σχολείου <span class="dgn-badge">δεν έχει οριστεί · επιλέγεται μία φορά</span></h2>
				<fieldset class="dgn-type-radios">
					<label><input type="radio" name="school_type" value="gym" <?php checked( $etype, 'gym' ); ?>> Γυμνάσιο</label>
					<label><input type="radio" name="school_type" value="lyk" <?php checked( $etype, 'lyk' ); ?>> Λύκειο</label>
				</fieldset>
				<p class="dgn-type-text"><strong class="dgn-only-gym">Γυμνάσιο:</strong><strong class="dgn-only-lyk">Λύκειο:</strong> όταν αλλάζετε την επιλογή, τα τμήματα και τα μαθήματα παρακάτω γεμίζουν με τα προτεινόμενα <span class="dgn-only-gym">του Γυμνασίου (ΦΕΚ Β΄ 2132/2026)</span><span class="dgn-only-lyk">του Λυκείου, με τις ομάδες προσανατολισμού (ΦΕΚ Β΄ 2106/2026)</span>. Μπορείτε να τα αλλάξετε ελεύθερα παρακάτω. Για να δείτε πώς αλλάζουν τα μαθήματα και τα τμήματα σε κάθε τύπο, δοκιμάστε τον έναν και μετά τον άλλον: δεν αποθηκεύεται τίποτα μέχρι να πατήσετε «Επιβεβαίωση τύπου σχολείου».</p>
				<p class="dgn-type-text">Οι λίστες που έχετε ήδη δεν αλλάζουν αυτόματα: αλλάζουν μόνο αν αλλάξετε εσείς την επιλογή. Μετά την επιβεβαίωση ο τύπος δεν αλλάζει πια. Η επιβεβαίωση αποθηκεύει και <span class="dgn-only-gym">τα τμήματα και τα μαθήματα</span><span class="dgn-only-lyk">τα τμήματα, τα μαθήματα και τις ομάδες</span> που βλέπετε στην ενότητα 1.</p>
				<p style="margin:10px 0 0"><button type="button" class="button button-primary" id="dgn-confirm-type">Επιβεβαίωση τύπου σχολείου</button></p>
			</div>
		<?php endif; ?>
		<div class="dgn-sec">
			<h2 class="dgn-sec-h"><span class="dgn-n">1</span>Σχολείο <small>τα βασικά στοιχεία του σχολείου σας</small></h2>
			<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dgn-school">Όνομα σχολείου</label></th>
				<td>
					<input id="dgn-school" name="school_name" class="regular-text" value="<?php echo esc_attr( $s['school_name'] ); ?>">
					<p class="description">Δεν εμφανίζεται στη σελίδα. Χρησιμοποιείται μόνο όταν οι καθηγητές μπαίνουν με κοινό κωδικό. Με αυτό το όνομα θα αποθηκεύεται στον browser τους ο κωδικός.</p>
				</td>
			</tr>
			<?php dgn_admin_draseis_row(); dgn_admin_section1_rows( $s, $form_groups ); ?>
			</table>
		</div>
		<div class="dgn-sec">
			<h2 class="dgn-sec-h"><span class="dgn-n">2</span>Είσοδος καθηγητών <small>ποιοι και πώς κάνουν δηλώσεις</small></h2>
			<table class="form-table" role="presentation">
			<tr class="dgn-access-row">
				<th scope="row">Τρόπος εισόδου</th>
				<td>
					<fieldset>
						<label><input type="radio" name="access" value="sso" <?php checked( $s['access'], 'sso' ); ?>> Με τον <strong>λογαριασμό ΠΣΔ (sch.gr)</strong>, μόνο καθηγητές της σχολικής μονάδας με εγγραφή στο ΠΣΔ</label><br>
						<label><input type="radio" name="access" value="users" <?php checked( $s['access'], 'users' ); ?>> Με <strong>λογαριασμό στην ιστοσελίδα</strong> (Χρήστες του WordPress), αφού συνδεθούν σε αυτόν</label><br>
						<?php if ( dgn_open_registration() ) : ?>
							<div class="dgn-danger" style="margin:4px 0 8px 24px"><strong>Προσοχή:</strong> η ιστοσελίδα επιτρέπει σε οποιονδήποτε να φτιάξει λογαριασμό (Ρυθμίσεις → Γενικά → «Οποιοσδήποτε μπορεί να εγγραφεί»). Για ασφάλεια, με αυτόν τον τρόπο εισόδου δεν γίνονται δεκτοί ως καθηγητές οι λογαριασμοί με τον ρόλο των νέων εγγραφών (Συνδρομητής). Δώστε στους καθηγητές ρόλο Συνεργάτη ή ανώτερο, ή κλείστε την ελεύθερη εγγραφή.</div>
						<?php endif; ?>
						<label><input type="radio" name="access" value="code" <?php checked( $s['access'], 'code' ); ?>> Με <strong>κοινό κωδικό καθηγητών</strong>, που τον ορίζετε εσείς παρακάτω</label>
					</fieldset>
				</td>
			</tr>
			<tr class="dgn-code-row">
				<th scope="row"><label for="dgn-code">Κωδικός καθηγητών</label></th>
				<td>
					<input id="dgn-code" name="code" class="regular-text" value="<?php echo esc_attr( $s['code'] ); ?>" autocomplete="off" autocapitalize="none" spellcheck="false">
					<p id="dgn-code-strength" class="dgn-strength" aria-live="polite"></p>
					<p class="description">Τουλάχιστον 8 λατινικοί χαρακτήρες, με κεφαλαίο, μικρό, αριθμό και ένα σύμβολο από τα <code>#</code> <code>$</code> <code>!</code> <code>%</code>. Αν τον αλλάξετε, όσοι είχαν μπει με τον παλιό θα πρέπει να γράψουν τον νέο. Οι δηλώσεις τους μένουν.</p>
				</td>
			</tr>
			<tr class="dgn-sso-row">
				<th scope="row">Σύνδεση με ΠΣΔ</th>
				<td>
					<?php if ( ! dgn_sso_requirements_ok() ) : ?>
						<p style="color:#b32d2e"><strong>Ο server δεν έχει τις επεκτάσεις PHP curl/dom που χρειάζεται η σύνδεση με ΠΣΔ.</strong> Ζητήστε τις από τη φιλοξενία.</p>
					<?php endif; ?>
					<fieldset class="dgn-group"><strong>Διακομιστής ΠΣΔ</strong><br>
						<label><input type="radio" name="sso_server" value="prod" <?php checked( $s['sso_server'], 'prod' ); ?>> Κανονικός (<code>sso.sch.gr</code>)</label><br>
						<label><input type="radio" name="sso_server" value="test" <?php checked( $s['sso_server'], 'test' ); ?>> Δοκιμαστικός (<code>sso-01-test.sch.gr</code>) — μόνο όσο το ΠΣΔ έχει εγγράψει την υπηρεσία εκεί</label>
						<?php if ( defined( 'DGN_CAS_HOST' ) ) : ?><br><em>Προσοχή: το wp-config.php ορίζει <code>DGN_CAS_HOST</code> = <code><?php echo esc_html( DGN_CAS_HOST ); ?></code>, που υπερισχύει αυτής της επιλογής.</em><?php endif; ?>
					</fieldset>
					<p class="dgn-group"><label for="dgn-sso-service"><strong>Διεύθυνση υπηρεσίας</strong> (ακριβώς όπως δηλώθηκε στο ΠΣΔ)</label><br>
					<input id="dgn-sso-service" name="sso_service" class="regular-text" value="<?php echo esc_attr( $s['sso_service'] ); ?>" placeholder="https://&lt;σχολείο&gt;.&lt;νομός&gt;.sch.gr/exams-planner/"></p>
					<p class="dgn-group"><label for="dgn-sso-units"><strong>Κωδικός σχολικής μονάδας</strong> (όπως στο myschool· περισσότεροι με κόμμα)</label><br>
					<input id="dgn-sso-units" name="sso_units" class="regular-text" value="<?php echo esc_attr( $s['sso_units'] ); ?>"></p>
					<p class="dgn-group"><label for="dgn-sso-roles"><strong>Ρόλοι που επιτρέπονται</strong> (τιμές του umdobject)</label><br>
					<input id="dgn-sso-roles" name="sso_roles" class="regular-text" value="<?php echo esc_attr( $s['sso_roles'] ); ?>"></p>
					<details class="dgn-notes"><summary>Οδηγίες για την αίτηση στο ΠΣΔ</summary>
						<p class="description">Στο ΠΣΔ ζητήστε τα attributes: <code>uid, sn, umdobject, edupersonorgunitdn:gsnunitcode:extended</code>.</p>
						<p class="description">Οι απορρίψεις εισόδου (π.χ. μαθητής ή καθηγητής άλλου σχολείου) φαίνονται στο «Ιστορικό» με τον ρόλο και τους κωδικούς μονάδας, χωρίς ονόματα.</p>
						<p class="description">Διεύθυνση που θα στέλνεται στο ΠΣΔ: <code><?php echo esc_html( dgn_sso_service_url( $s ) ); ?></code></p>
					</details>
				</td>
			</tr>
			<tr class="dgn-teachers-row">
				<th scope="row"><label for="dgn-teachers">Καθηγητές</label></th>
				<td><textarea id="dgn-teachers" name="teachers" rows="10" class="dgn-list"><?php echo esc_textarea( $s['teachers'] ); ?></textarea>
				<p style="margin:2px 0 14px;color:#646970"><?php echo count( dgn_teachers( $s ) ); ?> καθηγητές στη λίστα.</p>
				<p class="description"><strong>Η παραπάνω λίστα γίνεται ενεργή μόνο όταν επιλέξετε είσοδο καθηγητών με λογαριασμούς WordPress ή με κοινό κωδικό.</strong></p><ul class="description" style="list-style:disc;margin:4px 0 0 18px;color:#646970"><li>Ένα επώνυμο ανά γραμμή, όπως θέλετε να εμφανίζεται (π.χ. <code>ΠΑΠΑΔΑΚΗΣ</code> ή <code>ΠΑΠΑΔΑΚΗΣ Γ.</code> αν υπάρχουν ίδια επώνυμα).</li><li><strong>Με επώνυμα στην παραπάνω λίστα:</strong> οι καθηγητές διαλέγουν υποχρεωτικά το επώνυμό τους από τη λίστα.</li><li><strong>Χωρίς κανένα επώνυμο:</strong> οι καθηγητές γράφουν ελεύθερα το επώνυμό τους (προτείνεται η λίστα, για ομοιόμορφα ονόματα).</li><li>Η λίστα εμφανίζεται μόνο στους καθηγητές που έχουν συνδεθεί, ποτέ στους επισκέπτες.</li></ul><p class="dgn-sso-note" hidden>Έχετε επιλέξει είσοδο μέσω ΠΣΔ: το επώνυμο του καθηγητή αντλείται αυτόματα από τον λογαριασμό του, οπότε η λίστα δεν χρησιμοποιείται.</p></td>
			</tr>
			</table>
		</div>
		<div class="dgn-sec">
			<h2 class="dgn-sec-h"><span class="dgn-n">3</span>Κανόνες δηλώσεων <small>τι επιτρέπεται και ποιος βλέπει τον πίνακα</small></h2>
			<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dgn-max">Διαγωνίσματα ανά εβδομάδα</label></th>
				<td><input id="dgn-max" name="weekly_max" type="number" min="1" max="5" value="<?php echo (int) $s['weekly_max']; ?>" class="small-text"> ανά τμήμα<span class="dgn-only-lyk">· σε τάξη με ομάδες προσανατολισμού, ανά μαθητή (τα διαγωνίσματα του τμήματος και της ομάδας μετρούν μαζί)</span>. Σύμφωνα με το ισχύον πλαίσιο αξιολόγησης των μαθητών, έως 1 την ημέρα και έως 3 την εβδομάδα.</td>
			</tr>
			<tr>
				<th scope="row">Προθεσμίες δήλωσης</th>
				<td>
					<div class="dgn-opt"><label>Ελάχιστη προειδοποίηση: <input type="number" name="min_notice" min="0" max="30" class="small-text" value="<?php echo (int) $s['min_notice']; ?>"> εργάσιμες ημέρες πριν το διαγώνισμα, χωρίς Σαββατοκύριακα και αργίες. 0 = χωρίς όριο.</label></div>
					<p class="description">Οι καθηγητές δεν μπορούν να δηλώνουν περασμένες ημερομηνίες ούτε ημερομηνίες που εμπίπτουν στο διάστημα της ελάχιστης προειδοποίησης. Η Διεύθυνση (διαχειριστής) δεν έχει αυτούς τους περιορισμούς. Σήμερα, η νωρίτερη ημερομηνία που μπορεί να δηλώσει ένας καθηγητής είναι η <strong><?php echo esc_html( dgn_parse_date( (int) $s['min_notice'] > 0 ? dgn_earliest_allowed( (int) $s['min_notice'], $s ) : dgn_today() )->format( 'd/m/Y' ) ); ?></strong>.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Προβολή</th>
				<td>
					<label><input type="checkbox" name="public_view" value="1" <?php checked( $s['public_view'], 1 ); ?>> Μαθητές και γονείς βλέπουν τον πίνακα (χωρίς ονόματα καθηγητών, χωρίς δυνατότητα αλλαγών)</label>
				</td>
			</tr>
			</table>
		</div>
		<div class="dgn-sec">
			<h2 class="dgn-sec-h"><span class="dgn-n">4</span>Αργίες <small>ημέρες χωρίς διαγωνίσματα</small></h2>
			<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dgn-holidays">Πανελλαδικές αργίες <?php echo (int) $sy . '–' . ( (int) $sy + 1 ); ?></label></th>
				<td>
					<textarea id="dgn-holidays" name="holidays" rows="9" class="code dgn-list"><?php echo esc_textarea( $s['holidays'] ); ?></textarea>
					<?php if ( $bad ) : ?>
						<p style="color:#b32d2e"><strong>Δεν αναγνωρίστηκαν:</strong> <?php echo esc_html( implode( ' · ', $bad ) ); ?></p>
					<?php endif; ?>
					<label style="display:block;margin:8px 0 4px"><input type="checkbox" name="holidays_auto" value="1" <?php checked( ! empty( $s['holidays_auto'] ) ); ?>> Αυτόματη ενημέρωση των πανελλαδικών αργιών κάθε σχολική χρονιά</label>
					<p class="description" style="margin:0 0 8px">Κάθε νέα σχολική χρονιά το πεδίο ξαναγεμίζει μόνο του με τις αργίες της. Μέσα στη χρονιά μπορείτε να το διορθώσετε, π.χ. αν μετατεθεί η Πρωτομαγιά. Βγάλτε το τικ αν θέλετε να το συμπληρώνετε μόνοι σας.</p>
					<p style="margin:0"><button type="submit" form="dgn-fill-holidays" class="button" onclick="return confirm('Το πεδίο «Πανελλαδικές αργίες» θα αντικατασταθεί με τις αργίες του <?php echo (int) $sy . '–' . ( (int) $sy + 1 ); ?>. Συνέχεια;');">Επαναφορά πανελλαδικών αργιών</button> <span style="color:#646970">ξαναγράφει το πεδίο με τις αργίες του <?php echo (int) $sy . '–' . ( (int) $sy + 1 ); ?>, αν το έχετε αλλάξει ή σβήσει κατά λάθος</span></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dgn-holidays-local">Τοπικές αργίες</label></th>
				<td>
					<textarea id="dgn-holidays-local" name="holidays_local" rows="3" class="code dgn-list"><?php echo esc_textarea( $s['holidays_local'] ); ?></textarea>
					<?php if ( $bad_local ) : ?>
						<p style="color:#b32d2e"><strong>Δεν αναγνωρίστηκαν:</strong> <?php echo esc_html( implode( ' · ', $bad_local ) ); ?></p>
					<?php endif; ?>
					<p class="description">Του σχολείου ή της πόλης σας, μία ανά γραμμή. Χωρίς έτος ισχύει κάθε χρόνο: <code>11-08 | Πολιούχος</code>. Με έτος ισχύει μόνο τότε: <code>2027-05-20 έως 2027-05-21 | Επέτειος</code>. Σε όλες τις αργίες δεν γίνονται δηλώσεις.</p>
				</td>
			</tr>
			</table>
		</div>
		<div class="dgn-sec">
			<h2 class="dgn-sec-h"><span class="dgn-n">5</span>Δεδομένα <small>τι γίνεται με τις δηλώσεις και τις ρυθμίσεις</small></h2>
			<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Διαγραφή πρόσθετου</th>
				<td>
					<div class="dgn-danger">
						<label><input type="checkbox" name="uninstall_wipe" value="1" <?php checked( ! empty( $s['uninstall_wipe'] ) ); ?>> Να σβήνονται όλα τα δεδομένα όταν διαγράφεται το πρόσθετο</label>
						<p class="description">Αν το checkbox παραμείνει χωρίς τικ (προτείνεται), τα δεδομένα του πρόσθετου, όπως οι δηλώσεις, το ιστορικό και οι ρυθμίσεις, παραμένουν στη βάση δεδομένων. Επομένως, αν το πρόσθετο διαγραφεί και εγκατασταθεί ξανά, όλα τα δεδομένα θα είναι διαθέσιμα όπως πριν.</p>
						<p class="description">Επιλέξτε το checkbox μόνο αν θέλετε, κατά τη διαγραφή του πρόσθετου, να διαγραφούν οριστικά και όλα τα δεδομένα του.</p>
					</div>
				</td>
			</tr>
			</table>
		</div>
		<?php submit_button( 'Αποθήκευση' ); ?>
	</form>
	<form id="dgn-fill-holidays" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="dgn_fill_holidays">
		<?php wp_nonce_field( 'dgn_fill_holidays' ); ?>
	</form>
	<script>
	( function () {
		var form = document.querySelector( 'form.dgn-settings' );
		if ( ! form ) return;
		// Κάθε γραμμή είναι ενεργή μόνο για τον τρόπο εισόδου της· οι Εκπαιδευτικοί για όλους εκτός από ΠΣΔ.
		var rows = { code: form.querySelector( 'tr.dgn-code-row' ), sso: form.querySelector( 'tr.dgn-sso-row' ), teachers: form.querySelector( 'tr.dgn-teachers-row' ) };
		function sync() {
			var cur = ( form.querySelector( 'input[name=access]:checked' ) || {} ).value;
			Object.keys( rows ).forEach( function ( k ) {
				var row = rows[ k ];
				if ( ! row ) return;
				var off = 'teachers' === k ? 'sso' === cur : cur !== k;
				row.classList.toggle( 'dgn-off', off );
				row.querySelectorAll( 'input, textarea, select' ).forEach( function ( el ) { el.disabled = off; } );
			} );
			var note = form.querySelector( '.dgn-sso-note' );
			if ( note ) note.hidden = 'sso' !== cur;
			strength();
		}
		form.querySelectorAll( 'input[name=access]' ).forEach( function ( r ) { r.addEventListener( 'change', sync ); } );
		// Τα ανενεργά πεδία δεν στέλνονται: τα ενεργοποιούμε πριν την αποθήκευση, ώστε να μη χαθούν οι τιμές τους.
		form.addEventListener( 'submit', function () {
			form.querySelectorAll( 'tr.dgn-off input, tr.dgn-off textarea, tr.dgn-off select' ).forEach( function ( el ) { el.disabled = false; } );
		} );
		sync();
		// Ζωντανή ένδειξη ισχύος του κωδικού (ίδιοι κανόνες με το dgn_code_problems() της PHP).
		var codeIn = form.querySelector( '#dgn-code' ), codeOut = form.querySelector( '#dgn-code-strength' );
		function strength() {
			if ( ! codeIn || ! codeOut ) return;
			codeOut.hidden = codeIn.disabled; // ανενεργός κωδικός (άλλος τρόπος εισόδου): χωρίς ένδειξη
			var v = codeIn.value.trim(), p = [];
			if ( v && ! /^[\x21-\x7E]+$/.test( v ) ) p.push( 'μόνο λατινικοί χαρακτήρες, αριθμοί και σύμβολα, χωρίς κενά' );
			if ( v.length < 8 ) p.push( 'τουλάχιστον 8 χαρακτήρες' );
			if ( ! /[A-Z]/.test( v ) ) p.push( 'ένα κεφαλαίο γράμμα' );
			if ( ! /[a-z]/.test( v ) ) p.push( 'ένα μικρό γράμμα' );
			if ( ! /[0-9]/.test( v ) ) p.push( 'έναν αριθμό' );
			if ( ! /[#$!%]/.test( v ) ) p.push( 'ένα σύμβολο (# $ ! %)' );
			codeOut.textContent = p.length ? 'Αδύναμος κωδικός: χρειάζεται ' + p.join( ', ' ) + '.' : '✓ Ισχυρός κωδικός';
			codeOut.className = 'dgn-strength ' + ( p.length ? 'bad' : 'ok' );
		}
		if ( codeIn ) { codeIn.addEventListener( 'input', strength ); strength(); }
	} )();
	</script>
	<?php dgn_admin_group_card_js(); ?>
	<script>
	/* Τύπος σχολείου και ομάδες προσανατολισμού (1.11.0). */
	( function () {
		var form = document.querySelector( 'form.dgn-settings' );
		if ( ! form ) return;
		var D = <?php echo wp_json_encode( array( 'rec' => dgn_recommended_lists(), 'groups' => $form_groups, 'locked' => $locked, 'type' => $etype ) ); ?>;
		var box = form.querySelector( '#dgn-groups' ), addBtn = form.querySelector( '#dgn-add-group' );
		var classesEl = form.querySelector( '#dgn-classes' ), subjectsEl = form.querySelector( '#dgn-subjects' );
		if ( ! box || ! classesEl || ! subjectsEl || ! window.dgnGroupCard ) return;
		// Μόνο αν φτάσει εδώ το JS στέλνονται οι ομάδες· αλλιώς ο server κρατά τις αποθηκευμένες.
		var gp = document.createElement( 'input' );
		gp.type = 'hidden'; gp.name = 'groups_present'; gp.value = '1';
		form.appendChild( gp );
		// Η κάρτα μιας ομάδας: κοινή συνάρτηση με την καρτέλα «Παράδειγμα» (dgn_admin_group_card_js, 1.12.0).
		// Η τρέχουσα λίστα «Τμήματα» της φόρμας (γραμμές, χωρίς κενά και διπλότυπα, έως 20 χαρακτήρες) — όπως στον server.
		function getClasses() {
			var out = [];
			classesEl.value.split( /\r?\n/ ).forEach( function ( l ) { l = l.trim(); if ( l && l.length <= 20 && out.indexOf( l ) === -1 ) out.push( l ); } );
			return out;
		}
		function card( g ) { return window.dgnGroupCard( g, renumber, getClasses ); }
		function cards() { return Array.prototype.slice.call( box.querySelectorAll( '.dgn-group-card' ) ); }
		function renumber() {
			cards().forEach( function ( c, i ) {
				c.dgnPrefix = 'groups[' + i + ']';
				c.querySelectorAll( '[data-f]:not([data-multi])' ).forEach( function ( el ) { el.name = c.dgnPrefix + '[' + el.getAttribute( 'data-f' ) + ']'; } );
				if ( c.dgnRefresh ) c.dgnRefresh(); // τα τικ παίρνουν το νέο όνομα πεδίου
			} );
		}
		function readGroups() {
			return cards().map( function ( c ) {
				var o = { classes: [] };
				c.querySelectorAll( '[data-f]' ).forEach( function ( el ) {
					if ( el.getAttribute( 'data-multi' ) ) { if ( el.checked ) o.classes.push( el.value ); }
					else o[ el.getAttribute( 'data-f' ) ] = el.value;
				} );
				return o;
			} );
		}
		// Όταν αλλάζει η λίστα «Τμήματα», τα τικ των ομάδων ακολουθούν.
		classesEl.addEventListener( 'input', function () { cards().forEach( function ( c ) { if ( c.dgnRefresh ) c.dgnRefresh(); } ); } );
		function renderGroups( list ) {
			box.textContent = '';
			( list || [] ).forEach( function ( g ) { box.appendChild( card( g ) ); } );
			renumber();
		}
		renderGroups( D.groups );
		if ( addBtn ) addBtn.addEventListener( 'click', function () {
			var c = card( { name: '', grade: 'Β', short: '', subjects: '', classes: [] } );
			box.appendChild( c ); renumber();
			c.querySelector( 'input' ).focus();
		} );
		if ( D.locked ) return;

		// Αλλαγή τύπου πριν την επιβεβαίωση: οι λίστες γεμίζουν με τις προτεινόμενες του τύπου.
		// Όσο η σελίδα μένει ανοιχτή, η φόρμα θυμάται τι είχε κάθε τύπος, ώστε να γυρίσει κανείς στις δικές του λίστες.
		var cur = D.type, memory = {}, base = {};
		function snap() { return { classes: classesEl.value, subjects: subjectsEl.value, groups: readGroups() }; }
		function same( a, b ) { return JSON.stringify( a ) === JSON.stringify( b ); }
		base[ cur ] = snap();
		form.querySelectorAll( 'input[name=school_type]' ).forEach( function ( r ) {
			r.addEventListener( 'change', function () {
				if ( ! r.checked || r.value === cur ) return;
				var now = snap();
				if ( ! same( now, base[ cur ] ) && ! window.confirm( 'Οι αλλαγές σας στις λίστες θα αντικατασταθούν από τις προτεινόμενες. Συνέχεια;' ) ) {
					form.querySelector( 'input[name=school_type][value=' + cur + ']' ).checked = true;
					return;
				}
				memory[ cur ] = now;
				var v = JSON.parse( JSON.stringify( memory[ r.value ] || D.rec[ r.value ] ) );
				classesEl.value = v.classes; subjectsEl.value = v.subjects; renderGroups( v.groups );
				cur = r.value;
				base[ cur ] = snap();
				form.setAttribute( 'data-type', cur );
			} );
		} );
		var btn = form.querySelector( '#dgn-confirm-type' );
		if ( btn ) btn.addEventListener( 'click', function () {
			var lyk = cur === 'lyk';
			if ( ! window.confirm( 'Ορισμός τύπου σχολείου: ' + ( lyk ? 'Λύκειο' : 'Γυμνάσιο' ) + '\n\nΟ τύπος σχολείου δεν μπορεί να αλλάξει αργότερα. Θα αποθηκευτούν και τα τμήματα, τα μαθήματα' + ( lyk ? ' και οι ομάδες' : '' ) + ' που βλέπετε στη φόρμα, μαζί με τις υπόλοιπες ρυθμίσεις.\n\nΣυνέχεια;' ) ) return;
			var h = document.createElement( 'input' );
			h.type = 'hidden'; h.name = 'confirm_type'; h.value = '1';
			form.appendChild( h );
			var sub = form.querySelector( '#submit' );
			if ( sub ) sub.click(); else form.submit();
			// Τα δεδομένα της φόρμας έχουν ήδη συλλεχθεί· αν η αποστολή δεν έγινε (π.χ. μη έγκυρο πεδίο), το επόμενο
			// απλό «Αποθήκευση» δεν πρέπει να ορίσει τύπο.
			window.setTimeout( function () { if ( h.parentNode ) h.parentNode.removeChild( h ); }, 0 );
		} );
	} )();
	</script>
	<?php
}

/** Τα στυλ της σελίδας Ρυθμίσεων· τα χρησιμοποιεί και η καρτέλα «Παράδειγμα» (1.12.0). */
function dgn_admin_settings_css() {
	?><style>
		.dgn-settings input[type=number].small-text { width: 4.2em; height: 30px; min-height: 30px; padding: 0 4px 0 8px; line-height: 28px; }
		.dgn-settings td > label { display: inline-block; line-height: 2; }
		/* Ο κωδικός καθηγητών «ανήκει» στην τελευταία επιλογή από πάνω: μικρότερο κενό ανάμεσά τους. */
		.dgn-settings tr.dgn-access-row > th, .dgn-settings tr.dgn-access-row > td { padding-bottom: 6px; }
		.dgn-settings tr.dgn-code-row > th, .dgn-settings tr.dgn-code-row > td { padding-top: 6px; }
		/* Γραμμή που δεν αφορά τον τρέχοντα «Τρόπο εισόδου»: γκρι και ανενεργή. */
		.dgn-settings tr.dgn-off > th, .dgn-settings tr.dgn-off > td { opacity: .5; }
		/* Καθηγητές με ΠΣΔ: γκρι όλα εκτός από το πλαίσιο που εξηγεί γιατί. */
		.dgn-settings tr.dgn-teachers-row.dgn-off > td { opacity: 1; }
		.dgn-settings tr.dgn-teachers-row.dgn-off > td > :not(.dgn-sso-note) { opacity: .5; }
		/* Επικίνδυνη επιλογή (διαγραφή δεδομένων): πλαίσιο με κόκκινη γραμμή αριστερά, όπως το μπλε του ΠΣΔ. */
		.dgn-danger { padding: 8px 12px; max-width: 640px; background: #fff; border-left: 4px solid #d63638; box-shadow: 0 1px 1px rgba(0,0,0,.08); }
		.dgn-danger p.description { margin: 6px 0 0; }
		.dgn-sso-note { margin: 12px 0 4px; padding: 8px 12px; max-width: 560px; background: #fff; border-left: 4px solid #72aee6; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
		/* Επιλογές μέσα στην ίδια γραμμή: η καθεμία χωριστά, με κενό ανάμεσα. */
		.dgn-settings .dgn-opt { margin: 0 0 14px; }
		.dgn-settings .dgn-opt:last-of-type { margin-bottom: 8px; }
		/* Σύνδεση με ΠΣΔ: κενό ανάμεσα στις ομάδες πεδίων· οι σημειώσεις χωριστά, κάτω από μια λεπτή γραμμή. */
		.dgn-settings .dgn-group { margin: 0 0 16px; }
		.dgn-settings .dgn-group label { line-height: 2; }
		.dgn-settings .dgn-notes { border-top: 1px solid #dcdcde; padding-top: 10px; margin-top: 4px; }
		.dgn-settings .dgn-notes p.description { margin: 0 0 8px; }
		.dgn-settings .dgn-strength { margin: 6px 0 4px; font-weight: 600; }
		.dgn-settings .dgn-strength.ok { color: #00a32a; }
		.dgn-settings .dgn-strength.bad { color: #d63638; }
		/* Ενότητες (1.9.96): λευκά πλαίσια με αριθμό και τίτλο. */
		.dgn-settings .dgn-sec { background: #fff; border: 1px solid #c3c4c7; margin: 18px 0; max-width: 1100px; }
		.dgn-settings .dgn-sec-h { margin: 0; padding: 10px 16px; font-size: 15px; border-bottom: 1px solid #dcdcde; display: flex; align-items: center; gap: 10px; }
		.dgn-settings .dgn-sec-h small { font-weight: 400; color: #646970; font-size: 12px; }
		.dgn-settings .dgn-n { background: #2271b1; color: #fff; border-radius: 50%; width: 22px; height: 22px; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; flex: none; }
		.dgn-settings .dgn-sec .form-table { margin: 0; }
		.dgn-settings .dgn-sec .form-table th { padding-left: 16px; }
		.dgn-settings .dgn-sec .form-table tr + tr > th, .dgn-settings .dgn-sec .form-table tr + tr > td { border-top: 1px solid #f0f0f1; }
		/* Όλες οι λίστες και τα πεδία κειμένου στο ίδιο πλάτος, όσο χρειάζεται για να χωράει μία γραμμή αργιών· στο κινητό όσο η οθόνη. */
		.dgn-settings textarea.dgn-list, .dgn-settings input.regular-text { width: 31em; max-width: 100%; box-sizing: border-box; }
		@media (max-width: 782px) { .dgn-settings textarea.dgn-list, .dgn-settings input.regular-text { width: 100%; } }
		.dgn-settings details.dgn-notes summary { cursor: pointer; color: #2271b1; margin: 0 0 8px; }
		/* Τύπος σχολείου (1.11.0): ό,τι αφορά μόνο τον έναν τύπο κρύβεται στον άλλον. */
		.dgn-settings[data-type="gym"] .dgn-only-lyk, .dgn-settings[data-type="lyk"] .dgn-only-gym { display: none !important; }
		.dgn-settings .dgn-typebox { background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 12px 16px; margin: 18px 0; max-width: 1100px; box-sizing: border-box; }
		.dgn-settings .dgn-typebox-h { margin: 0 0 8px; font-size: 15px; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
		.dgn-settings .dgn-badge { font-size: 11px; font-weight: 600; background: #fcf9e8; border: 1px solid #dba617; color: #6b4e00; border-radius: 3px; padding: 1px 6px; }
		.dgn-settings .dgn-type-radios label { display: inline-block; margin: 0 22px 6px 0; font-size: 14px; line-height: 2; }
		.dgn-settings .dgn-type-text { margin: 6px 0 0; max-width: 900px; }
		.dgn-settings .dgn-groups-info { background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 10px 14px; margin: 0 0 14px; max-width: 720px; }
		.dgn-settings .dgn-groups { display: flex; flex-wrap: wrap; gap: 16px; max-width: 900px; }
		.dgn-settings .dgn-group-card { flex: 1 1 380px; min-width: 0; box-sizing: border-box; border: 1px solid #dcdcde; border-radius: 4px; padding: 12px 14px; background: #fff; }
		.dgn-settings .dgn-group-top { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
		.dgn-settings .dgn-group-card label { display: block; font-weight: 600; font-size: 13px; margin: 0 0 4px; }
		.dgn-settings .dgn-group-card input, .dgn-settings .dgn-group-card select, .dgn-settings .dgn-group-card textarea { width: 100%; box-sizing: border-box; }
		.dgn-settings .dgn-group-card textarea { margin-top: 0; }
		.dgn-settings .dgn-group-label { color: #646970; font-size: 12px; font-weight: 400; }
		.dgn-settings .dgn-group-classes { margin-top: 10px; }
		.dgn-settings .dgn-group-classes .dgn-gc-h { display: block; font-weight: 600; font-size: 13px; margin: 0 0 2px; }
		.dgn-settings .dgn-group-classes .description { margin: 0 0 6px; font-size: 12px; }
		.dgn-settings .dgn-group-card .dgn-group-ticks { display: flex; flex-wrap: wrap; gap: 4px 16px; margin: 4px 0; }
		.dgn-settings .dgn-group-card .dgn-group-ticks label { display: inline-flex; align-items: center; gap: 6px; font-weight: 400; font-size: 13px; margin: 0; }
		.dgn-settings .dgn-group-card .dgn-group-ticks input[type=checkbox] { width: 16px; height: 16px; min-width: 16px; margin: 0; }
		.dgn-settings .dgn-group-del { color: #b32d2e; background: none; border: 0; padding: 0; cursor: pointer; text-decoration: underline; font-size: 13px; }
	</style>
<?php
}

/**
 * Οι γραμμές της ενότητας 1 που εξαρτώνται από τον τύπο: τμήματα, μαθήματα, ομάδες προσανατολισμού (1.12.0).
 * Τις χρησιμοποιούν οι Ρυθμίσεις και η καρτέλα «Παράδειγμα», ώστε τα κείμενα να είναι πάντα ίδια.
 * Στο παράδειγμα ($ex = true) τα πεδία ΔΕΝ έχουν name (δεν στέλνονται ποτέ), έχουν δικά τους id
 * και δεν εμφανίζονται οι προειδοποιήσεις, που αφορούν τα δεδομένα του σχολείου.
 *
 * @param array $s           Ρυθμίσεις (ή, στο παράδειγμα, οι προτεινόμενες λίστες: classes, subjects).
 * @param array $form_groups Ομάδες για τις κάρτες.
 * @param bool  $ex          Παράδειγμα (true) ή οι πραγματικές Ρυθμίσεις (false).
 */
function dgn_admin_section1_rows( $s, $form_groups, $ex = false ) {
	$id = $ex ? 'dgn-ex-' : 'dgn-';
	$nm = function ( $n ) use ( $ex ) {
		return $ex ? ' autocomplete="off"' : ' name="' . $n . '"';
	};
	?>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( $id ); ?>classes"><span class="dgn-only-gym">Τμήματα</span><span class="dgn-only-lyk">Γενικά τμήματα</span></label></th>
				<td><textarea id="<?php echo esc_attr( $id ); ?>classes"<?php echo $nm( 'classes' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- σταθερό κείμενο ?> rows="8" class="dgn-list"><?php echo esc_textarea( $s['classes'] ); ?></textarea>
				<?php if ( ! $ex ) { dgn_admin_orphan_warnings( $s ); } ?>
				<p class="description">Ένα ανά γραμμή, με τη σειρά που θέλετε να εμφανίζονται. Το πρώτο γράμμα είναι η τάξη (Α, Β, Γ).<br>Αν αλλάξετε το όνομα ενός τμήματος (π.χ. το Β1 σε Β4) ή το αφαιρέσετε, τα διαγωνίσματα που έχουν ήδη δηλωθεί γι' αυτό δεν θα φαίνονται πια στον πίνακα σε μαθητές, γονείς και καθηγητές, και θα μπορεί να δηλωθεί άλλο διαγώνισμα τις ίδιες ημέρες. Γι' αυτό, κάντε τέτοιες αλλαγές πριν αρχίσουν οι δηλώσεις.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( $id ); ?>subjects"><span class="dgn-only-gym">Μαθήματα και τάξεις</span><span class="dgn-only-lyk">Μαθήματα γενικής παιδείας</span></label></th>
				<td><textarea id="<?php echo esc_attr( $id ); ?>subjects"<?php echo $nm( 'subjects' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- σταθερό κείμενο ?> rows="12" class="dgn-list"><?php echo esc_textarea( $s['subjects'] ); ?></textarea>
				<?php if ( ! $ex ) { dgn_admin_subject_warnings( $s ); } ?>
				<p class="description">Ένα μάθημα ανά γραμμή, με τη συντομογραφία του (έως 5 γράμματα, φαίνεται στον πίνακα): <code>Μαθηματικά | ΜΑΘ</code>.<br>Αν ένα μάθημα διδάσκεται μόνο σε ορισμένες τάξεις, προσθέστε μία ακόμη κάθετο και τις τάξεις: <code>Χημεία | ΧΗΜ | Β, Γ</code>. Τότε το μάθημα εμφανίζεται μόνο στα τμήματα αυτών των τάξεων.</p>
				<p class="description dgn-only-lyk">Η «Ιστορία» και τα «Μαθηματικά» της Γ΄ τάξης δηλώνονται εδώ, ως μαθήματα γενικής παιδείας, παρότι δεν τα παρακολουθούν όλοι οι μαθητές: την Ιστορία οι μαθητές των Θετικών και Υγείας και της Οικονομίας και Πληροφορικής, και τα Μαθηματικά οι μαθητές των Ανθρωπιστικών.</p></td>
			</tr>
			<tr class="dgn-groups-row dgn-only-lyk">
				<th scope="row">Ομάδες προσανατολισμού</th>
				<td>
					<div class="dgn-groups-info"><strong>Πώς λειτουργούν οι ομάδες.</strong> Οι μαθητές κάθε γενικού τμήματος μπορεί να συμμετέχουν σε διαφορετικές ομάδες προσανατολισμού της ίδιας τάξης. Για τον λόγο αυτό, σε κάθε τάξη, μια ημέρα μπορεί να είναι είτε ημέρα διαγωνισμάτων των γενικών τμημάτων είτε ημέρα διαγωνισμάτων των ομάδων, όχι και τα δύο. Το εβδομαδιαίο όριο μετρά μαζί τα διαγωνίσματα του γενικού τμήματος και της ομάδας κάθε μαθητή.</div>
					<div id="<?php echo esc_attr( $id ); ?>groups" class="dgn-groups"><p class="description">Οι ομάδες εμφανίζονται όταν φορτώσει η σελίδα (χρειάζεται JavaScript).</p></div>
					<p style="margin:10px 0 0"><button type="button" class="button" id="<?php echo esc_attr( $id ); ?>add-group">+ Προσθήκη ομάδας</button></p>
					<?php if ( ! $ex ) { dgn_admin_group_warnings( $s, $form_groups ); } ?>
					<p class="description" style="margin-top:12px"><strong>Σημείωση:</strong> το ίδιο μάθημα (π.χ. «Φυσική») μπορεί να υπάρχει και στα μαθήματα γενικής παιδείας και σε μια ομάδα. Αν αλλάξετε την τάξη ή τη συντομογραφία μιας ομάδας (π.χ. το <code>Β·ΘΕΤ</code> σε <code>Β·ΘΕΣ</code>), τα διαγωνίσματα που έχουν ήδη δηλωθεί γι' αυτήν δεν θα φαίνονται πια στον πίνακα σε μαθητές, γονείς και καθηγητές, και θα μπορεί να δηλωθεί άλλο διαγώνισμα τις ίδιες ημέρες. Γι' αυτό, κάντε τέτοιες αλλαγές πριν αρχίσουν οι δηλώσεις.</p>
				</td>
			</tr>
	<?php
}

/** Η κάρτα μιας ομάδας προσανατολισμού (κοινή για Ρυθμίσεις και Παράδειγμα, 1.12.0). Χωρίς name: τα ονόματα τα βάζει μόνο η φόρμα των Ρυθμίσεων. */
function dgn_admin_group_card_js() {
	?>
	<script>
	window.dgnGroupCard = window.dgnGroupCard || ( function () {
		var GRADES = [ 'Α', 'Β', 'Γ', 'Δ' ], uid = 0;
		function mk( tag, attrs ) {
			var n = document.createElement( tag );
			Object.keys( attrs || {} ).forEach( function ( k ) { if ( k === 'text' ) n.textContent = attrs[ k ]; else n.setAttribute( k, attrs[ k ] ); } );
			return n;
		}
		// Η τάξη ενός τμήματος = το πρώτο του γράμμα (όπως στο PHP: λατινικά A, B → Α, Β).
		function gradeChar( name ) {
			var ch = String( name ).trim().charAt( 0 ).toUpperCase();
			ch = { 'A': 'Α', 'B': 'Β', 'Ά': 'Α', 'Έ': 'Ε', 'Ή': 'Η', 'Ί': 'Ι', 'Ό': 'Ο', 'Ύ': 'Υ', 'Ώ': 'Ω' }[ ch ] || ch;
			return /^[Α-Ω]$/.test( ch ) ? ch : '';
		}
		// getClasses: συνάρτηση που επιστρέφει τη λίστα «Τμήματα» της σελίδας (τα τικ ακολουθούν τη λίστα).
		return function ( g, onRemove, getClasses ) {
			var id = 'dgn-g' + ( ++uid );
			var c = mk( 'div', { 'class': 'dgn-group-card' } );
			var topRow = mk( 'div', { 'class': 'dgn-group-top' } );
			var fName = mk( 'div', { style: 'flex:1 1 200px' } ), fGrade = mk( 'div', { style: 'flex:0 0 64px' } ), fShort = mk( 'div', { style: 'flex:0 0 110px' } );
			var name = mk( 'input', { type: 'text', id: id + 'n', maxlength: '40', 'data-f': 'name' } ); name.value = g.name || '';
			var grade = mk( 'select', { id: id + 't', 'data-f': 'grade' } );
			var grades = GRADES.slice(); if ( g.grade && grades.indexOf( g.grade ) === -1 ) grades.push( g.grade );
			grades.forEach( function ( x ) { grade.appendChild( mk( 'option', { value: x, text: x } ) ); } );
			grade.value = g.grade || 'Β';
			var short = mk( 'input', { type: 'text', id: id + 's', maxlength: '5', 'data-f': 'short' } ); short.value = g.short || '';
			fName.appendChild( mk( 'label', { 'for': id + 'n', text: 'Όνομα ομάδας' } ) ); fName.appendChild( name );
			fGrade.appendChild( mk( 'label', { 'for': id + 't', text: 'Τάξη' } ) ); fGrade.appendChild( grade );
			fShort.appendChild( mk( 'label', { 'for': id + 's', text: 'Συντομογραφία' } ) ); fShort.appendChild( short );
			topRow.appendChild( fName ); topRow.appendChild( fGrade ); topRow.appendChild( fShort );
			var preview = mk( 'p', { 'class': 'dgn-group-label', style: 'margin:6px 0 0' } );
			function upd() { preview.textContent = 'Στον πίνακα: ' + grade.value + '·' + ( short.value.trim().toUpperCase() || '…' ); }
			name.addEventListener( 'input', upd ); grade.addEventListener( 'change', upd ); short.addEventListener( 'input', upd ); upd();
			var subj = mk( 'textarea', { id: id + 'm', rows: '4', 'data-f': 'subjects', placeholder: 'Ένα ανά γραμμή, με τη συντομογραφία:\nΦυσική | ΦΥΣ' } ); subj.value = g.subjects || '';
			var del = mk( 'button', { type: 'button', 'class': 'dgn-group-del', text: 'Αφαίρεση ομάδας' } );
			del.addEventListener( 'click', function () { c.parentNode.removeChild( c ); if ( onRemove ) onRemove(); } );
			// Τμήματα της ομάδας (1.13.0): κενό = όλη η τάξη. Όσα επιλεγούν έχουν κοινούς μαθητές με την ομάδα.
			var picked = {};
			( g.classes || [] ).forEach( function ( x ) { picked[ x ] = true; } );
			var cwrap = mk( 'div', { 'class': 'dgn-group-classes' } );
			var ticks = mk( 'div', { 'class': 'dgn-group-ticks', role: 'group', 'aria-labelledby': id + 'ch' } );
			var cstat = mk( 'p', { 'class': 'dgn-group-label', style: 'margin:4px 0 0' } );
			cwrap.appendChild( mk( 'span', { 'class': 'dgn-gc-h', id: id + 'ch', text: 'Τμήματα της ομάδας' } ) );
			cwrap.appendChild( mk( 'p', { 'class': 'description', text: 'Αφήστε κενό αν η ομάδα έχει μαθητές από όλα τα τμήματα της τάξης. Επιλέξτε μόνο όσα τμήματα έχουν μαθητές στην ομάδα, αν είναι λιγότερα.' } ) );
			cwrap.appendChild( ticks ); cwrap.appendChild( cstat );
			function refresh() {
				var list = getClasses ? getClasses() : [], gr = grade.value;
				var mine = list.filter( function ( x ) { return gradeChar( x ) === gr; } );
				ticks.textContent = '';
				mine.forEach( function ( x, i ) {
					var lab = mk( 'label', {} ), cb = mk( 'input', { type: 'checkbox', value: x, 'data-f': 'classes', 'data-multi': '1' } );
					if ( c.dgnPrefix ) cb.name = c.dgnPrefix + '[classes][]';
					cb.checked = !! picked[ x ];
					cb.addEventListener( 'change', function () { picked[ x ] = cb.checked; status(); } );
					lab.appendChild( cb ); lab.appendChild( document.createTextNode( x ) );
					ticks.appendChild( lab );
				} );
				status();
			}
			function status() {
				var gr = grade.value, boxes = ticks.querySelectorAll( 'input:checked' ), n = boxes.length, all = ticks.querySelectorAll( 'input' ).length;
				var t;
				if ( ! all ) t = 'Δεν υπάρχουν τμήματα της ' + gr + '΄ τάξης στη λίστα «Τμήματα».';
				else if ( ! n ) t = 'Δεν έχουν επιλεγεί τμήματα: η ομάδα αφορά όλη την ' + gr + '΄ τάξη.';
				else if ( n === all ) t = 'Έχουν επιλεγεί όλα τα τμήματα: η ομάδα αφορά όλη την ' + gr + '΄ τάξη.';
				else t = 'Η ομάδα αφορά τα ' + Array.prototype.map.call( boxes, function ( b ) { return b.value; } ).join( ', ' ) + '.';
				cstat.textContent = t;
			}
			c.dgnRefresh = refresh;
			grade.addEventListener( 'change', refresh );
			c.appendChild( topRow ); c.appendChild( preview );
			c.appendChild( mk( 'label', { 'for': id + 'm', style: 'margin-top:10px', text: 'Μαθήματα της ομάδας' } ) );
			c.appendChild( subj );
			c.appendChild( cwrap );
			refresh();
			var p = mk( 'p', { style: 'margin:8px 0 0' } ); p.appendChild( del ); c.appendChild( p );
			return c;
		};
	} )();
	</script>
	<?php
}

/**
 * Καρτέλα «Παράδειγμα Λυκείου / Γυμνασίου» (1.12.0): η ενότητα 1 του ΑΛΛΟΥ τύπου, με τις προτεινόμενες λίστες,
 * για να δει ο διαχειριστής τι προσφέρει το πρόσθετο σε αυτόν. Εμφανίζεται μόνο αφού ο τύπος οριστεί οριστικά.
 * Δεν υπάρχει φόρμα ούτε κουμπί αποθήκευσης και τα πεδία δεν έχουν name: τίποτα δεν στέλνεται στον server.
 */
function dgn_admin_tab_example() {
	$s = dgn_settings();
	if ( ! dgn_type_locked( $s ) ) {
		return;
	}
	$other = 'lyk' === $s['school_type'] ? 'gym' : 'lyk';
	$rec   = dgn_recommended_lists()[ $other ];
	$rec   = array(
		'classes'  => (string) $rec['classes'],
		'subjects' => (string) $rec['subjects'],
		'groups'   => isset( $rec['groups'] ) && is_array( $rec['groups'] ) ? array_values( $rec['groups'] ) : array(),
	);
	$names = array( 'gym' => 'Γυμνάσιο', 'lyk' => 'Λύκειο' );
	$in    = array( 'gym' => 'σε ένα Γυμνάσιο', 'lyk' => 'σε ένα Λύκειο' );
	echo "\t";
	dgn_admin_settings_css();
	?>
	<div class="dgn-settings dgn-example" data-type="<?php echo esc_attr( $other ); ?>">
		<div class="dgn-example-note" style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:10px 16px;background:#fcf9e8;border:1px solid #f0d58c;border-left:4px solid #dba617;padding:10px 14px;margin:18px 0;max-width:1100px;box-sizing:border-box">
			<p style="margin:0;flex:1 1 420px;max-width:860px"><strong>Παράδειγμα:</strong> το σχολείο σας είναι <?php echo esc_html( $names[ $s['school_type'] ] ); ?>. Εδώ βλέπετε πώς είναι η ενότητα 1 των Ρυθμίσεων <?php echo esc_html( $in[ $other ] ); ?>, με τις προτεινόμενες λίστες. <?php echo esc_html( 'lyk' === $other ? 'Μπορείτε να προσθέσετε ή να αφαιρέσετε τμήματα, μαθήματα και ομάδες.' : 'Μπορείτε να προσθέσετε ή να αφαιρέσετε τμήματα και μαθήματα.' ); ?> <strong>Δεν επηρεάζει τις ρυθμίσεις σας και δεν αποθηκεύεται τίποτα.</strong></p>
			<button type="button" class="button" id="dgn-ex-reset">Επαναφορά παραδείγματος</button>
		</div>
		<div class="dgn-sec">
			<h2 class="dgn-sec-h"><span class="dgn-n">1</span>Σχολείο <small>όπως <?php echo esc_html( $in[ $other ] ); ?></small></h2>
			<table class="form-table" role="presentation">
			<?php dgn_admin_section1_rows( $rec, $rec['groups'], true ); ?>
			</table>
		</div>
	</div>
	<?php dgn_admin_group_card_js(); ?>
	<script>
	/* Παράδειγμα (1.12.0): γέμισμα των πεδίων και «Επαναφορά παραδείγματος». Τίποτα δεν στέλνεται στον server. */
	( function () {
		var root = document.querySelector( '.dgn-example' );
		if ( ! root || ! window.dgnGroupCard ) return;
		var D = <?php echo wp_json_encode( $rec ); ?>;
		var box = root.querySelector( '#dgn-ex-groups' ), add = root.querySelector( '#dgn-ex-add-group' );
		var cl = root.querySelector( '#dgn-ex-classes' ), su = root.querySelector( '#dgn-ex-subjects' );
		var reset = root.querySelector( '#dgn-ex-reset' );
		if ( ! box || ! cl || ! su ) return;
		// Πάντα από τις προτεινόμενες λίστες (και αν ο browser «θυμηθεί» τιμές μετά από ανανέωση).
		function fill() {
			cl.value = D.classes; su.value = D.subjects;
			box.textContent = '';
			D.groups.forEach( function ( g ) { box.appendChild( window.dgnGroupCard( g, null, getClasses ) ); } );
		}
		function getClasses() {
			var out = [];
			cl.value.split( /\r?\n/ ).forEach( function ( l ) { l = l.trim(); if ( l && l.length <= 20 && out.indexOf( l ) === -1 ) out.push( l ); } );
			return out;
		}
		cl.addEventListener( 'input', function () { box.querySelectorAll( '.dgn-group-card' ).forEach( function ( c ) { if ( c.dgnRefresh ) c.dgnRefresh(); } ); } );
		fill();
		if ( add ) add.addEventListener( 'click', function () {
			var c = window.dgnGroupCard( { name: '', grade: 'Β', short: '', subjects: '', classes: [] }, null, getClasses );
			box.appendChild( c );
			c.querySelector( 'input' ).focus();
		} );
		if ( reset ) reset.addEventListener( 'click', fill );
	} )();
	</script>
	<?php
}

/**
 * Προειδοποιήσεις κάτω από τις «Ομάδες προσανατολισμού». Δεν εμποδίζουν την αποθήκευση.
 *
 * @param array $form_groups Οι ομάδες όπως είναι αποθηκευμένες (καθαρισμένες), και όσες αγνοούνται.
 */
function dgn_admin_group_warnings( $s, $form_groups ) {
	global $wpdb;
	if ( ! $form_groups ) {
		return;
	}
	$school  = dgn_school_grades( $s['classes'] );
	$classes = dgn_classes( $s );
	$warn    = array();
	$nograde = array();
	$nosubj  = array();
	$dup     = array();
	$seen    = array();
	foreach ( $form_groups as $g ) {
		$label = dgn_group_label( $g );
		if ( ! in_array( $g['grade'], $school, true ) ) {
			$nograde[] = 'η «' . $g['name'] . '» ανήκει στη ' . $g['grade'] . ' τάξη, που δεν έχει τμήματα';
		}
		$has = false;
		foreach ( preg_split( '/\R/u', (string) $g['subjects'] ) as $line ) {
			if ( '' !== dgn_subject_line( $line )[0] ) {
				$has = true;
				break;
			}
		}
		if ( ! $has ) {
			$nosubj[] = $g['name'];
		}
		if ( in_array( $label, $classes, true ) ) {
			$dup[] = 'το «' . $label . '» υπάρχει και ως τμήμα';
		} elseif ( isset( $seen[ $label ] ) ) {
			$dup[] = 'το «' . $label . '» το έχουν δύο ομάδες';
		}
		$seen[ $label ] = true;
	}
	if ( $nograde ) {
		$warn[] = '<strong>Ελέγξτε τις ομάδες:</strong> ' . esc_html( implode( ' · ', $nograde ) ) . '.';
	}
	if ( $nosubj ) {
		$warn[] = '<strong>Ομάδες χωρίς κανένα μάθημα:</strong> ' . esc_html( implode( ', ', $nosubj ) ) . '.';
	}
	if ( $dup ) {
		$warn[] = '<strong>Η ετικέτα στον πίνακα πρέπει να είναι μοναδική:</strong> ' . esc_html( implode( ' · ', $dup ) ) . '. Η ομάδα δεν εμφανίζεται και δεν δέχεται δηλώσεις μέχρι να αλλάξετε τη συντομογραφία της.';
	}
	$groups = dgn_groups( $s );
	if ( $groups ) {
		$t    = dgn_table( 'exams' );
		$in   = implode( ',', array_fill( 0, count( $groups ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT cls, exam_date, subject FROM $t WHERE exam_date >= %s AND cls IN ($in) ORDER BY exam_date, cls", array_merge( array( dgn_school_year()[0] ), array_keys( $groups ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$mis  = array();
		foreach ( (array) $rows as $r ) {
			if ( ! isset( $groups[ $r->cls ]['subjects'][ $r->subject ] ) ) {
				$mis[] = date_i18n( 'j/n', strtotime( $r->exam_date ) ) . ' ' . $r->cls . ' ' . $r->subject;
			}
		}
		if ( $mis ) {
			$more   = count( $mis ) > 15 ? ' κ.ά. (' . count( $mis ) . ' συνολικά)' : '';
			$warn[] = '<strong>Δηλώσεις σε μάθημα που δεν ανήκει στην ομάδα:</strong> ' . esc_html( implode( ' · ', array_slice( $mis, 0, 15 ) ) . $more ) . '. Διορθώστε τις από τις <a href="' . esc_url( dgn_admin_url( 'exams' ) ) . '">Δηλώσεις</a>.';
		}
	}
	foreach ( $warn as $w ) {
		echo '<p style="color:#996800;margin:8px 0 0">' . $w . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
	}
}

/**
 * Προειδοποίηση κάτω από τα «Τμήματα»: δηλώσεις της φετινής χρονιάς σε τμήμα ή ομάδα που δεν υπάρχει πια
 * στις ρυθμίσεις (π.χ. μετά από μετονομασία). Δεν φαίνονται στον πίνακα, αλλά μένουν στη βάση και στο Excel.
 */
function dgn_admin_orphan_warnings( $s ) {
	global $wpdb;
	$units = dgn_units( $s );
	if ( ! $units ) {
		return;
	}
	$t    = dgn_table( 'exams' );
	$in   = implode( ',', array_fill( 0, count( $units ), '%s' ) );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT cls, COUNT(*) AS n FROM $t WHERE exam_date >= %s AND cls NOT IN ($in) GROUP BY cls ORDER BY cls", array_merge( array( dgn_school_year()[0] ), $units ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	if ( ! $rows ) {
		return;
	}
	$list = array();
	foreach ( $rows as $r ) {
		$list[] = $r->cls . ' (' . (int) $r->n . ')';
	}
	echo '<p style="color:#996800;margin:6px 0 0"><strong>Δηλώσεις σε τμήμα ή ομάδα που δεν υπάρχει πια στις ρυθμίσεις:</strong> ' . esc_html( implode( ', ', $list ) ) . '. Δεν εμφανίζονται στον πίνακα. Αν μετονομάσατε κάτι, επαναφέρετε το παλιό όνομα ή διορθώστε τις δηλώσεις από τις <a href="' . esc_url( dgn_admin_url( 'exams' ) ) . '">Δηλώσεις</a>.</p>';
}

function dgn_admin_tab_exams() {
	global $wpdb;
	$t    = dgn_table( 'exams' );
	$from = dgn_today();
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE exam_date >= %s ORDER BY exam_date, cls LIMIT 500", $from ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$days = array( 1 => 'Δευ', 'Τρι', 'Τετ', 'Πεμ', 'Παρ', 'Σαβ', 'Κυρ' );
	$years = dgn_export_years();
	?>
	<div style="background:#fff;border:1px solid #c3c4c7;padding:12px 16px;margin:16px 0;max-width:900px">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;margin:0">
			<input type="hidden" name="action" value="dgn_export">
			<?php wp_nonce_field( 'dgn_export' ); ?>
			<strong>Εξαγωγή σε Excel:</strong>
			<label>Σχολικό έτος
				<select name="year">
					<?php foreach ( $years as $y ) : ?>
						<option value="<?php echo (int) $y; ?>"><?php echo (int) $y . '–' . ( (int) $y + 1 ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<?php submit_button( 'Λήψη αρχείου Excel', 'primary', 'submit', false ); ?>
			<span class="description">Όλα τα διαγωνίσματα της χρονιάς (1/9 – 30/6), ένα ανά γραμμή.</span>
		</form>
		<p class="description" style="margin:8px 0 0"><strong>Συμβουλή:</strong> κατεβάζετε κατά διαστήματα το αρχείο Excel και το κρατάτε ως αντίγραφο των δηλώσεων. Αν χρειαστεί ποτέ, οι δηλώσεις ξαναπερνιούνται από αυτό σε λίγα λεπτά.</p>
	</div>
	<?php
	echo '<h2>Επόμενα διαγωνίσματα</h2>';
	if ( ! $rows ) {
		echo '<p>Δεν υπάρχουν δηλώσεις από σήμερα και μετά.</p>';
	} else {
		// Σε στενή οθόνη (κινητό) ο πίνακας κυλά οριζόντια μέσα στο πλαίσιό του αντί να στριμώχνεται.
		echo '<div style="max-width:900px;overflow-x:auto"><table class="widefat striped" style="min-width:640px"><thead><tr><th>Ημερομηνία</th><th>Τμήμα</th><th>Μάθημα</th><th>Καθηγητής</th><th>Δηλώθηκε</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$d   = dgn_parse_date( $r['exam_date'] );
			$del = wp_nonce_url( admin_url( 'admin-post.php?action=dgn_delete&id=' . (int) $r['id'] ), 'dgn_delete_' . (int) $r['id'] );
			printf(
				'<tr><td>%s %s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s" class="button-link-delete" onclick="return window.confirm(\'Να διαγραφεί η δήλωση;\')">Διαγραφή</a></td></tr>',
				esc_html( $days[ (int) $d->format( 'N' ) ] ),
				esc_html( $d->format( 'd/m/Y' ) ),
				esc_html( $r['cls'] ),
				esc_html( $r['subject'] ),
				esc_html( $r['teacher'] ),
				esc_html( mysql2date( 'd/m H:i', $r['created_at'] ) ),
				esc_url( $del )
			);
		}
		echo '</tbody></table></div>';
	}
	?>
	<h2 style="margin-top:32px">Νέα σχολική χρονιά</h2>
	<p>Στην αρχή της νέας σχολικής χρονιάς, διαγράψτε τις δηλώσεις της προηγούμενης. Διαγράφονται όλες οι δηλώσεις με ημερομηνία πριν από αυτήν που θα επιλέξετε, και η διαγραφή δεν αναιρείται. Αν θέλετε να τις κρατήσετε, κατεβάστε πρώτα το αρχείο Excel. Το ιστορικό δεν επηρεάζεται.</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="dgn_purge">
		<?php wp_nonce_field( 'dgn_purge' ); ?>
		<label>Δηλώσεις πριν από <input type="date" name="before" required></label>
		<label style="margin-left:12px">Γράψτε <strong>ΔΙΑΓΡΑΦΗ</strong> για επιβεβαίωση <input name="confirm" required></label>
		<?php submit_button( 'Διαγραφή παλιών δηλώσεων', 'delete', 'submit', false ); ?>
	</form>
	<?php
}

function dgn_admin_tab_log() {
	global $wpdb;
	$t    = dgn_table( 'log' );
	$rows  = $wpdb->get_results( "SELECT * FROM $t ORDER BY id DESC LIMIT 300", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	?>
	<div style="background:#fff;border:1px solid #c3c4c7;padding:12px 16px;margin:16px 0;max-width:1100px;display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:space-between">
		<span>Διατηρούνται έως <?php echo esc_html( number_format_i18n( DGN_LOG_KEEP ) ); ?> πρόσφατες ενέργειες. Οι παλαιότερες διαγράφονται αυτόματα. Αυτή τη στιγμή υπάρχουν: <strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>.</span>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
			<input type="hidden" name="action" value="dgn_export_log">
			<?php wp_nonce_field( 'dgn_export_log' ); ?>
			<?php submit_button( 'Εξαγωγή ιστορικού (Excel)', 'secondary', 'submit', false ); ?>
		</form>
	</div>
	<?php
	echo '<h2>Τελευταίες 300 ενέργειες</h2>';
	if ( ! $rows ) {
		echo '<p>Δεν υπάρχουν ακόμη ενέργειες.</p>';
		return;
	}
	echo '<div style="max-width:1100px;overflow-x:auto"><table class="widefat striped" style="min-width:860px"><thead><tr><th>Πότε</th><th>Ενέργεια</th><th>Τμήμα</th><th>Ημ. διαγωνίσματος</th><th>Μάθημα</th><th>Καθηγητής</th><th>Από</th><th>IP</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( mysql2date( 'd/m/Y H:i', $r['created_at'] ) ),
			esc_html( $r['action'] ),
			esc_html( $r['cls'] ),
			esc_html( mysql2date( 'd/m/Y', $r['exam_date'] ) ),
			esc_html( $r['subject'] ),
			esc_html( $r['teacher'] ),
			esc_html( $r['actor'] ),
			esc_html( $r['ip'] )
		);
	}
	echo '</tbody></table></div>';
	?>
	<h2 style="margin-top:32px">Διαγραφή ιστορικού</h2>
	<p>Διαγράφει όλες τις εγγραφές του ιστορικού (π.χ. στην αρχή της σχολικής χρονιάς). Οι δηλώσεις δεν επηρεάζονται. Αν θέλετε αρχείο, κάντε πρώτα «Εξαγωγή ιστορικού».</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="dgn_clear_log">
		<?php wp_nonce_field( 'dgn_clear_log' ); ?>
		<label>Γράψτε <strong>ΔΙΑΓΡΑΦΗ</strong> για επιβεβαίωση <input name="confirm" required></label>
		<?php submit_button( 'Διαγραφή ιστορικού', 'delete', 'submit', false ); ?>
	</form>
	<?php
}

/**
 * Προειδοποιήσεις κάτω από τα «Μαθήματα»: τάξεις που δεν αντιστοιχούν σε τμήμα, τμήματα χωρίς κανένα μάθημα,
 * και δηλώσεις της φετινής χρονιάς σε μάθημα που δεν διδάσκεται στην τάξη του τμήματος.
 */
function dgn_admin_subject_warnings( $s ) {
	global $wpdb;
	$school = dgn_school_grades( $s['classes'] );
	$warn   = array();
	$odd    = array();
	foreach ( preg_split( '/\R/u', (string) $s['subjects'] ) as $line ) {
		list( $name, , $gtext ) = dgn_subject_line( $line );
		if ( '' === $name || '' === $gtext ) {
			continue;
		}
		list( $grades, $bad ) = dgn_parse_grades( $gtext );
		if ( $bad ) {
			$odd[] = $name . ': δεν αναγνωρίστηκε «' . implode( '', $bad ) . '»';
		}
		$none = array_diff( $grades, $school );
		if ( $grades && $school && $none ) {
			$odd[] = $name . ': δεν υπάρχει τμήμα της ' . implode( ', ', $none ) . ' τάξης';
		}
	}
	if ( $odd ) {
		$warn[] = '<strong>Ελέγξτε τις τάξεις:</strong> ' . esc_html( implode( ' · ', $odd ) );
	}
	$empty = array();
	foreach ( dgn_classes( $s ) as $c ) {
		$ok = false;
		foreach ( array_keys( dgn_subjects( $s ) ) as $subj ) {
			if ( dgn_subject_allowed( $subj, $c, $s ) ) {
				$ok = true;
				break;
			}
		}
		if ( ! $ok ) {
			$empty[] = $c;
		}
	}
	if ( $empty ) {
		$warn[] = '<strong>Τμήματα χωρίς κανένα μάθημα:</strong> ' . esc_html( implode( ', ', $empty ) );
	}
	if ( dgn_subject_grades( $s ) ) {
		$t    = dgn_table( 'exams' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT cls, exam_date, subject FROM $t WHERE exam_date >= %s ORDER BY exam_date, cls", dgn_school_year()[0] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$mis  = array();
		$grp  = dgn_groups( $s );
		foreach ( (array) $rows as $r ) {
			if ( isset( $grp[ $r->cls ] ) ) {
				continue; // δήλωση ομάδας: ελέγχεται κάτω από τις ομάδες, με τα μαθήματα της ομάδας
			}
			if ( isset( dgn_subjects( $s )[ $r->subject ] ) && ! dgn_subject_allowed( $r->subject, $r->cls, $s ) ) {
				$mis[] = date_i18n( 'j/n', strtotime( $r->exam_date ) ) . ' ' . $r->cls . ' ' . $r->subject;
			}
		}
		if ( $mis ) {
			$more   = count( $mis ) > 15 ? ' κ.ά. (' . count( $mis ) . ' συνολικά)' : '';
			$warn[] = '<strong>Δηλώσεις σε μάθημα που δεν διδάσκεται στην τάξη του τμήματος:</strong> ' . esc_html( implode( ' · ', array_slice( $mis, 0, 15 ) ) . $more ) . '. Διορθώστε τις από τις <a href="' . esc_url( dgn_admin_url( 'exams' ) ) . '">Δηλώσεις</a>.';
		}
	}
	foreach ( $warn as $w ) {
		echo '<p style="color:#996800;margin:6px 0 0">' . $w . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
	}
}
