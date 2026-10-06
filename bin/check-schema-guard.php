<?php
/**
 * CI check: every write to the plugin's own tables runs after Schema::ensure(), or inside an
 * `if ( Schema::is_current() )` block, in the same function.
 *
 * install() does not run on every kind of update (a zip uploaded over the plugin, SFTP, a request with no
 * admin_init). A write that names a column the table does not have yet fails silently, and 5.0's
 * Scan_Store::save_facts() did exactly that with body_text.
 *
 * Checked: any file that uses Schema::table( or names one of the tables (aisa_scan, aisa_pages,
 * aisa_changes). In it, every query/insert/update/replace/delete call on a database object (a variable or
 * property whose name contains "db": $wpdb, $db, $this->db) must either
 *   - come after a Schema::ensure( call in the innermost function around it, or
 *   - sit inside the block of an `if` whose condition calls Schema::is_current( (not negated),
 * unless its line says why not:
 *     $wpdb->query( 'COMMIT' ); // schema-guard: <why this write needs no check>
 *
 * Usage: php bin/check-schema-guard.php [dir-or-file ...]   (default: src). Exit 1 on a finding.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

/**
 * The unguarded custom-table writes in one file's code.
 *
 * @param string $code PHP source.
 * @return array<int,int> Line numbers.
 */
function aisa_schema_guard_findings( string $code ): array {
	if ( false === strpos( $code, 'Schema::table(' ) && ! preg_match( '/aisa_(scan|pages|changes)\b/', $code ) ) {
		return []; // No custom table here: core tables (options, posts) need no check.
	}
	$tokens = token_get_all( $code );
	$lines  = explode( "\n", $code );
	$count  = count( $tokens );
	$skip   = [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ];
	$next   = static function ( int $i ) use ( $tokens, $count, $skip ): int {
		do {
			++$i;
		} while ( $i < $count && is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], $skip, true ) );
		return $i;
	};
	$text   = static fn( $t ): string => is_array( $t ) ? $t[1] : (string) $t;
	// The token index of the brace that closes the one at $open.
	$close = static function ( int $open ) use ( $tokens, $count ): int {
		$depth = 0;
		for ( $j = $open; $j < $count; $j++ ) {
			$t = $tokens[ $j ];
			if ( '{' === $t || ( is_array( $t ) && in_array( $t[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true ) ) ) {
				++$depth;
			} elseif ( '}' === $t && 0 === --$depth ) {
				return $j;
			}
		}
		return $count;
	};

	$functions = []; // [ open brace, close brace ].
	$guarded   = []; // if-blocks whose condition calls Schema::is_current(): [ open brace, close brace ].
	$ensures   = []; // Token indexes of Schema::ensure( calls.
	for ( $i = 0; $i < $count; $i++ ) {
		$t = $tokens[ $i ];
		if ( ! is_array( $t ) ) {
			continue;
		}
		if ( in_array( $t[0], [ T_FUNCTION, T_FN ], true ) ) {
			$open = $i;
			while ( $open < $count && '{' !== $tokens[ $open ] && ';' !== $tokens[ $open ] ) {
				++$open;
			}
			if ( $open < $count && '{' === $tokens[ $open ] ) {
				$functions[] = [ $open, $close( $open ) ];
			}
		} elseif ( T_IF === $t[0] ) {
			// The condition: from "(" to its matching ")".
			$p     = $next( $i );
			$depth = 0;
			$cond  = '';
			for ( $j = $p; $j < $count; $j++ ) {
				$cond .= $text( $tokens[ $j ] );
				if ( '(' === $tokens[ $j ] ) {
					++$depth;
				} elseif ( ')' === $tokens[ $j ] && 0 === --$depth ) {
					break;
				}
			}
			$brace = $next( $j );
			if ( '{' === ( $tokens[ $brace ] ?? null ) && preg_match( '/Schema::is_current\s*\(/', $cond ) && ! preg_match( '/!\s*(\\\\?[\w\\\\]*\\\\)?Schema::is_current/', $cond ) ) {
				$guarded[] = [ $brace, $close( $brace ) ];
			}
		} elseif ( T_STRING === $t[0] && 'Schema' === $t[1] ) {
			$colon = $next( $i );
			$name  = $next( $colon );
			if ( is_array( $tokens[ $colon ] ?? null ) && T_DOUBLE_COLON === $tokens[ $colon ][0] && is_array( $tokens[ $name ] ?? null ) && 'ensure' === $tokens[ $name ][1] && '(' === ( $tokens[ $next( $name ) ] ?? null ) ) {
				$ensures[] = $i;
			}
		}
	}

	$found = [];
	for ( $i = 0; $i < $count; $i++ ) {
		$t = $tokens[ $i ];
		if ( ! is_array( $t ) || T_VARIABLE !== $t[0] ) {
			continue;
		}
		// $wpdb->write(  |  $db->write(  |  $this->db->write(.
		$arrow = $next( $i );
		$name  = $next( $arrow );
		if ( '$this' === $t[1] && is_array( $tokens[ $name ] ?? null ) && T_STRING === $tokens[ $name ][0] && false !== stripos( $tokens[ $name ][1], 'db' ) ) {
			$arrow = $next( $name );
			$name  = $next( $arrow );
		} elseif ( false === stripos( $t[1], 'db' ) ) {
			continue;
		}
		$paren = $next( $name );
		if ( ! is_array( $tokens[ $arrow ] ?? null ) || T_OBJECT_OPERATOR !== $tokens[ $arrow ][0] || ! is_array( $tokens[ $name ] ?? null ) || '(' !== ( $tokens[ $paren ] ?? null ) ) {
			continue;
		}
		if ( ! in_array( strtolower( $tokens[ $name ][1] ), [ 'query', 'insert', 'update', 'replace', 'delete' ], true ) ) {
			continue;
		}
		$line = (int) $t[2];
		if ( preg_match( '#//\s*schema-guard:\s*\S#', $lines[ $line - 1 ] ?? '' ) ) {
			continue;
		}
		$fn = null;
		foreach ( $functions as $f ) {
			if ( $f[0] < $i && $i < $f[1] && ( null === $fn || $f[0] > $fn[0] ) ) {
				$fn = $f; // The innermost function around the call.
			}
		}
		$ok = false;
		foreach ( $ensures as $e ) {
			// An ensure() in the same function, before the write (not one in a nested closure).
			$inner = null;
			foreach ( $functions as $f ) {
				if ( $f[0] < $e && $e < $f[1] && ( null === $inner || $f[0] > $inner[0] ) ) {
					$inner = $f;
				}
			}
			if ( null !== $fn && $inner === $fn && $e < $i ) {
				$ok = true;
			}
		}
		foreach ( $guarded as $g ) {
			if ( $g[0] < $i && $i < $g[1] && ( null === $fn || $g[0] > $fn[0] ) ) {
				$ok = true;
			}
		}
		if ( ! $ok ) {
			$found[] = $line;
		}
	}

	return array_values( array_unique( $found ) );
}

/**
 * Every finding under the given directories or files.
 *
 * @param array<int,string> $paths Directories or files.
 * @return array<int,string> "path:line" each.
 */
function aisa_schema_guard_scan( array $paths ): array {
	$out = [];
	foreach ( $paths as $path ) {
		$files = is_file( $path ) ? [ $path ] : [];
		if ( is_dir( $path ) ) {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( 'php' === strtolower( $file->getExtension() ) ) {
					$files[] = $file->getPathname();
				}
			}
		}
		sort( $files );
		foreach ( $files as $file ) {
			foreach ( aisa_schema_guard_findings( (string) file_get_contents( $file ) ) as $line ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a CLI check, no WordPress.
				$out[] = $file . ':' . $line;
			}
		}
	}

	return $out;
}

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$aisa_paths    = array_slice( $argv, 1 );
	$aisa_findings = aisa_schema_guard_scan( [] === $aisa_paths ? [ dirname( __DIR__ ) . '/src' ] : $aisa_paths );
	foreach ( $aisa_findings as $aisa_finding ) {
		fwrite( STDERR, $aisa_finding . ': a write to the plugin\'s tables without Schema::ensure() before it in its function (or an enclosing if ( Schema::is_current() )). Add it, or "// schema-guard: <reason>" on the line.' . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}
	echo [] === $aisa_findings ? 'schema-guard: every custom-table write is guarded.' . PHP_EOL : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI.
	exit( [] === $aisa_findings ? 0 : 1 );
}
