<?php
// Fixture for bin/check-schema-guard.php. Never loaded; each write's comment says what the check must decide.

class Cases {
	public function unguarded() {
		global $wpdb;
		$wpdb->update( Schema::table( 'scan' ), [], [] ); // Flagged: line 7.
		$wpdb->query( 'DELETE FROM x' ); // Flagged: line 8.
	}

	public function guarded() {
		global $wpdb;
		Schema::ensure();
		$wpdb->insert( Schema::table( 'changes' ), [] ); // Fine.
		$f = function () use ( $wpdb ) {
			$wpdb->replace( 'x', [] ); // Flagged: line 16 (a closure is its own function).
		};
	}

	public function checked() {
		global $wpdb;
		if ( Schema::is_current() ) {
			$wpdb->delete( 'x', [] ); // Fine.
		}
	}

	public function explained() {
		global $wpdb;
		$wpdb->query( 'COMMIT' ); // schema-guard: ends the transaction replace_all() opened after its check.
		$wpdb->query( 'ROLLBACK' ); // schema-guard:
	}

	public function reads() {
		global $wpdb;
		// $wpdb->query( 'in a comment' ) is not a write.
		return $wpdb->get_results( 'SELECT 1' ); // A read: fine.
	}
}
