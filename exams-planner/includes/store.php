<?php
/**
 * Πρόσβαση στη βάση: δηλώσεις και ιστορικό.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dgn_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'dgn_' . $name;
}

/** Δηλώσεις σε εύρος ημερομηνιών (συμπεριλαμβανομένων). */
function dgn_exams_between( $from, $to ) {
	global $wpdb;
	$t = dgn_table( 'exams' );
	return $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM $t WHERE exam_date BETWEEN %s AND %s ORDER BY exam_date, cls", $from, $to ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);
}

function dgn_exam_get( $id ) {
	global $wpdb;
	$t = dgn_table( 'exams' );
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

function dgn_log( $action, $exam, $actor ) {
	global $wpdb;
	$wpdb->insert(
		dgn_table( 'log' ),
		array(
			'action'     => $action,
			'cls'        => $exam['cls'],
			'exam_date'  => $exam['exam_date'],
			'subject'    => $exam['subject'],
			'teacher'    => $exam['teacher'],
			'actor'      => mb_substr( $actor, 0, 100 ),
			'ip'         => dgn_client_ip(),
			'created_at' => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	dgn_log_prune();
}

/** Το ιστορικό κρατά τις τελευταίες DGN_LOG_KEEP ενέργειες· οι παλαιότερες διαγράφονται. */
if ( ! defined( 'DGN_LOG_KEEP' ) ) {
	define( 'DGN_LOG_KEEP', 2000 );
}
function dgn_log_prune() {
	global $wpdb;
	$t   = dgn_table( 'log' );
	$cut = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t ORDER BY id DESC LIMIT 1 OFFSET %d", DGN_LOG_KEEP ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $cut > 0 ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE id <= %d", $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}

/**
 * Οι «αντίθετες» μονάδες μιας μονάδας: για τμήμα οι ομάδες της τάξης του, για ομάδα τα τμήματα της τάξης της.
 * Έχουν κοινούς μαθητές με τη μονάδα (από 1.13.0: με βάση τα τμήματα που έχει επιλέξει κάθε ομάδα).
 * Κενό = δεν υπάρχουν ομάδες (Γυμνάσιο, Α΄ Λυκείου): κανόνες όπως πριν την 1.11.0.
 *
 * @return array{0: bool, 1: string[]} [είναι ομάδα;, αντίθετες μονάδες]
 */
function dgn_unit_overlaps( $unit, $s = null ) {
	$s      = $s ? $s : dgn_settings();
	$groups = dgn_groups( $s );
	if ( isset( $groups[ $unit ] ) ) {
		// 1.13.0: ομάδα με επιλεγμένα τμήματα έχει κοινούς μαθητές μόνο με αυτά· χωρίς επιλογή, με όλα τα τμήματα της τάξης.
		$of_grade = dgn_classes_of_grade( $groups[ $unit ]['grade'], $s );
		$only     = $groups[ $unit ]['classes'];
		return array( true, $only ? array_values( array_intersect( $of_grade, $only ) ) : $of_grade );
	}
	// Τμήμα: οι ομάδες της τάξης του που το περιλαμβάνουν (ομάδα χωρίς επιλογή τμημάτων = όλη η τάξη).
	$out = array();
	foreach ( dgn_groups( $s ) as $label => $g ) {
		if ( $g['grade'] === dgn_class_grade( $unit ) && ( ! $g['classes'] || in_array( $unit, $g['classes'], true ) ) ) {
			$out[] = $label;
		}
	}
	return array( false, $out );
}

/**
 * Έλεγχος κανόνων για νέα δήλωση της μονάδας $cls την ημέρα $day (εβδομάδα $week).
 * $before_id > 0: μετράνε μόνο οι δηλώσεις με μικρότερο id (επανέλεγχος μετά την καταχώριση, χωρίς κλείδωμα).
 *
 * Κανόνες (από 1.11.0, όταν υπάρχουν ομάδες προσανατολισμού στην τάξη):
 *  - σε κάθε τάξη μια ημέρα είναι ή ημέρα των τμημάτων ή ημέρα των ομάδων·
 *  - το όριο της εβδομάδας μετράει ανά μαθητή: τα διαγωνίσματα του τμήματος μαζί με όσα της ομάδας του.
 *    Επειδή κάθε τμήμα έχει μαθητές σε κάθε ομάδα της τάξης, για τμήμα μετράει η ομάδα με τα περισσότερα
 *    και για ομάδα το τμήμα με τα περισσότερα (ο κανόνας είναι αυστηρότερος, ποτέ χαλαρότερος).
 *
 * @return WP_Error|null
 */
function dgn_exam_rules_error( $cls, $day, $week, $before_id = 0 ) {
	global $wpdb;
	$s   = dgn_settings();
	$t   = dgn_table( 'exams' );
	$max = max( 1, (int) $s['weekly_max'] );
	$idq = $before_id > 0 ? $wpdb->prepare( ' AND id < %d', $before_id ) : '';
	list( $is_group, $other ) = dgn_unit_overlaps( $cls, $s );

	if ( $other ) {
		$in    = implode( ',', array_fill( 0, count( $other ), '%s' ) );
		$clash = $wpdb->get_row( $wpdb->prepare( "SELECT cls, subject FROM $t WHERE exam_date = %s AND cls IN ($in)", array_merge( array( $day ), $other ) ) . $idq . ' ORDER BY id LIMIT 1', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		if ( $clash ) {
			$grade = $is_group ? dgn_groups( $s )[ $cls ]['grade'] : dgn_class_grade( $cls );
			return new WP_Error(
				'dgn_day_kind',
				$is_group
					? sprintf( 'Την ημέρα αυτή η %s΄ τάξη έχει διαγώνισμα γενικού τμήματος (%s: %s). Σε κάθε τάξη μια ημέρα είναι ή των τμημάτων ή των ομάδων προσανατολισμού. Διαλέξτε άλλη ημέρα.', $grade, $clash['cls'], $clash['subject'] )
					: sprintf( 'Την ημέρα αυτή η %s΄ τάξη έχει διαγώνισμα ομάδας προσανατολισμού (%s: %s), με μαθητές και του %s. Σε κάθε τάξη μια ημέρα είναι ή των τμημάτων ή των ομάδων. Διαλέξτε άλλη ημέρα.', $grade, $clash['cls'], $clash['subject'], $cls ),
				array( 'status' => 409 )
			);
		}
	}

	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE cls = %s AND week_start = %s", $cls, $week ) . $idq ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $count >= $max ) {
		return new WP_Error(
			'dgn_week_full',
			sprintf( 'Το %s έχει ήδη %d διαγωνίσματα αυτή την εβδομάδα. Διαλέξτε άλλη εβδομάδα.', $cls, $count ),
			array( 'status' => 409 )
		);
	}
	if ( $other ) {
		$in   = implode( ',', array_fill( 0, count( $other ), '%s' ) );
		$top  = $wpdb->get_row( $wpdb->prepare( "SELECT cls, COUNT(*) AS n FROM $t WHERE week_start = %s AND cls IN ($in)", array_merge( array( $week ), $other ) ) . $idq . ' GROUP BY cls ORDER BY n DESC, cls LIMIT 1', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$topn = $top ? (int) $top['n'] : 0;
		if ( $count + $topn >= $max ) {
			return new WP_Error(
				'dgn_week_full',
				$is_group
					? sprintf( 'Η εβδομάδα είναι πλήρης για μαθητές της ομάδας %s: η ομάδα έχει %d και το τμήμα %s %d διαγωνίσματα (όριο %d την εβδομάδα, τμήμα και ομάδα μαζί). Διαλέξτε άλλη εβδομάδα.', $cls, $count, $top['cls'], $topn, $max )
					: sprintf( 'Η εβδομάδα είναι πλήρης για μαθητές του %s: το τμήμα έχει %d και η ομάδα %s %d διαγωνίσματα (όριο %d την εβδομάδα, τμήμα και ομάδα μαζί). Διαλέξτε άλλη εβδομάδα.', $cls, $count, $top['cls'], $topn, $max ),
				array( 'status' => 409 )
			);
		}
	}
	return null;
}

/**
 * Καταχώριση δήλωσης με έλεγχο κανόνων.
 * Το κλείδωμα (GET_LOCK) ανά τμήμα+εβδομάδα αποτρέπει δύο ταυτόχρονες δηλώσεις να περάσουν μαζί το όριο
 * της εβδομάδας. Αν η τάξη έχει ομάδες προσανατολισμού, το κλείδωμα είναι ανά τάξη+εβδομάδα, γιατί τμήματα
 * και ομάδες της ίδιας τάξης έχουν κοινούς μαθητές. Το μοναδικό κλειδί (cls, exam_date) εγγυάται μία δήλωση
 * την ημέρα ανά μονάδα ακόμη και χωρίς κλείδωμα.
 * Αν το κλείδωμα δεν δοθεί (όχι «απασχολημένο», αλλά σφάλμα), οι κανόνες ελέγχονται ξανά μετά την καταχώριση.
 *
 * @return array|WP_Error Η νέα δήλωση.
 */
function dgn_exam_create( $cls, DateTimeImmutable $date, $subject, $teacher, $user_id, $delete_hash, $actor ) {
	global $wpdb;
	$s      = dgn_settings();
	$t      = dgn_table( 'exams' );
	$day    = $date->format( 'Y-m-d' );
	$week   = dgn_week_start( $date )->format( 'Y-m-d' );
	list( $is_group, $other ) = dgn_unit_overlaps( $cls, $s );
	$scope  = $other ? 'grade:' . ( $is_group ? dgn_groups( $s )[ $cls ]['grade'] : dgn_class_grade( $cls ) ) : $cls;
	$lock   = 'dgn_' . md5( $scope . '|' . $week );
	$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) );
	if ( '0' === $locked ) {
		return new WP_Error( 'dgn_busy', 'Κάποιος συνάδελφος δηλώνει αυτή τη στιγμή για το ίδιο τμήμα. Δοκιμάστε ξανά σε λίγα δευτερόλεπτα.', array( 'status' => 409 ) );
	}

	try {
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT subject, teacher FROM $t WHERE cls = %s AND exam_date = %s", $cls, $day ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing ) {
			return new WP_Error(
				'dgn_day_taken',
				sprintf( 'Το %s έχει ήδη διαγώνισμα αυτή την ημέρα (%s).', $cls, $existing['subject'] ),
				array( 'status' => 409 )
			);
		}
		// Άλλο σύστημα (π.χ. το Draseis Planner) μπορεί να μπλοκάρει την ημέρα, π.χ. με εγκεκριμένη δράση.
		// Το φίλτρο επιστρέφει κενό '' (επιτρέπεται) ή το μήνυμα προς τον χρήστη (δεν επιτρέπεται).
		// Μόνο μη κενό κείμενο μπλοκάρει: ένα λάθος φίλτρο που επιστρέφει false/null δεν κλειδώνει όλες τις δηλώσεις.
		// Για ομάδα ρωτάμε και για κάθε τμήμα της τάξης της (οι μαθητές της είναι μαθητές αυτών των τμημάτων).
		foreach ( array_merge( array( $cls ), $is_group ? $other : array() ) as $ask ) {
			$blocked = apply_filters( 'dgn_exam_blocked', '', $ask, $day );
			if ( is_string( $blocked ) && '' !== trim( $blocked ) ) {
				return new WP_Error( 'dgn_blocked', trim( $blocked ), array( 'status' => 409 ) );
			}
		}
		$rule = dgn_exam_rules_error( $cls, $day, $week );
		if ( $rule ) {
			return $rule;
		}
		$row = array(
			'cls'         => $cls,
			'exam_date'   => $day,
			'week_start'  => $week,
			'subject'     => $subject,
			'teacher'     => $teacher,
			'user_id'     => (int) $user_id,
			'delete_hash' => $delete_hash,
			'created_at'  => current_time( 'mysql' ),
		);
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert( $t, $row, array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ) );
		$wpdb->suppress_errors( $suppress );
		if ( ! $ok && false === stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
			return new WP_Error( 'dgn_db', 'Η δήλωση δεν αποθηκεύτηκε λόγω σφάλματος της βάσης. Δοκιμάστε ξανά.', array( 'status' => 500 ) );
		}
		if ( ! $ok ) {
			// Το πιθανότερο: κάποιος άλλος πρόλαβε την ίδια ημέρα (μοναδικό κλειδί).
			return new WP_Error( 'dgn_day_taken', sprintf( 'Το %s μόλις δηλώθηκε από άλλον συνάδελφο για αυτή την ημέρα.', $cls ), array( 'status' => 409 ) );
		}
		$row['id'] = (int) $wpdb->insert_id;
		if ( '1' !== $locked ) {
			// Το κλείδωμα δεν δόθηκε (σφάλμα της βάσης ή διακομιστής χωρίς GET_LOCK): η δήλωση δεν μπλοκάρεται,
			// αλλά οι κανόνες ελέγχονται ξανά μετά την καταχώριση, μόνο με τις παλαιότερες δηλώσεις (μικρότερο id).
			// Αν κάποια παλαιότερη πρόλαβε (όριο εβδομάδας ή ημέρα της άλλης κατηγορίας), η δική μας αφαιρείται.
			$late = dgn_exam_rules_error( $cls, $day, $week, $row['id'] );
			if ( $late ) {
				$wpdb->delete( $t, array( 'id' => $row['id'] ), array( '%d' ) );
				return $late;
			}
		}
		dgn_log( 'δήλωση', $row, $actor );
		return $row;
	} finally {
		if ( '1' === $locked ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}

function dgn_exam_delete( $exam, $actor ) {
	global $wpdb;
	$ok = $wpdb->delete( dgn_table( 'exams' ), array( 'id' => (int) $exam['id'] ), array( '%d' ) );
	if ( $ok ) {
		dgn_log( 'διαγραφή', $exam, $actor );
	}
	return (bool) $ok;
}
