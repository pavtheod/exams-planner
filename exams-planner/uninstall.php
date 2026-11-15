<?php
/**
 * Εκτελείται μόνο όταν το πρόσθετο ΔΙΑΓΡΑΦΕΤΑΙ (όχι στην απενεργοποίηση).
 * Αφαιρεί δηλώσεις, ιστορικό και ρυθμίσεις ΜΟΝΟ αν ο διαχειριστής το έχει επιλέξει
 * (Ρυθμίσεις → Δεδομένα → «Να σβήνονται όλα τα δεδομένα όταν διαγράφεται το πρόσθετο»).
 * Αλλιώς δεν σβήνει τίποτα, ώστε μια νέα εγκατάσταση να βρει τα δεδομένα όπως ήταν.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$dgn_opt = get_option( 'dgn_settings' );
if ( ! is_array( $dgn_opt ) || empty( $dgn_opt['uninstall_wipe'] ) ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dgn_exams" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dgn_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
delete_option( 'dgn_settings' );
delete_option( 'dgn_db_version' );
