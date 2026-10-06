<?php
// Fixture for bin/check-schema-guard.php: names a table directly, never through the Schema helper; still checked.

function aisa_fixture_raw() {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->prefix}aisa_scan" ); // Flagged: line 6.
}
