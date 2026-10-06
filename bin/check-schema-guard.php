<?php
/**
 * CI check: every write to the plugin's own tables runs after Schema::ensure() (or a Schema::is_current()
 * check) in the same function.
 *
 * install() does not run on every kind of update (a zip uploaded over the plugin, SFTP, a request with no
 * admin_init). A write that names a column the table does not have yet fails silently, and 5.0's
 * Scan_Store::save_facts() did exactly that with body_text.
 *
 * In a file that uses Schema::table(), each $wpdb->query/insert/update/replace/delete call must sit in a
 * function whose body calls Schema::ensure( or Schema::is_current( , unless its line says why not:
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
	if ( false === strpos( $code, 'Schema::table(' ) ) {
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

	// Each function's body: [ first token, last token, its code ].
	$bodies = [];
	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || ! in_array( $tokens[ $i ][0], [ T_FUNCTION, T_FN ], true ) ) {
			continue;
		}
		$open = $i;
		while ( $open < $count && '{' !== $tokens[ $open ] && ';' !== $tokens[ $open ] ) {
			++$open;
		}
		if ( $open >= $count || '{' !== $tokens[ $open ] ) {
			continue; // Abstract or an arrow function: no body of its own.
		}
		$depth = 0;
		$text  = '';
		for ( $j = $open; $j < $count; $j++ ) {
			$t     = $tokens[ $j ];
			$text .= is_array( $t ) ? $t[1] : $t;
			if ( '{' === $t || ( is_array( $t ) && in_array( $t[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true ) ) ) {
				++$depth;
			} elseif ( '}' === $t && 0 === --$depth ) {
				break;
			}
		}
		$bodies[] = [ $open, $j, $text ];
	}

	$found = [];
	for ( $i = 0; $i < $count; $i++ ) {
		$t = $tokens[ $i ];
		if ( ! is_array( $t ) || T_VARIABLE !== $t[0] || '$wpdb' !== $t[1] ) {
			continue;
		}
		$arrow = $next( $i );
		$name  = $next( $arrow );
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
		$body = null;
		foreach ( $bodies as $b ) {
			if ( $b[0] < $i && $i < $b[1] && ( null === $body || $b[0] > $body[0] ) ) {
				$body = $b; // The innermost function around the call.
			}
		}
		if ( null === $body || ! preg_match( '/Schema::(ensure|is_current)\s*\(/', $body[2] ) ) {
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
		fwrite( STDERR, $aisa_finding . ': a write to the plugin\'s tables without Schema::ensure() first in its function. Call it, or add "// schema-guard: <reason>" to the line.' . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}
	echo [] === $aisa_findings ? 'schema-guard: every custom-table write is guarded.' . PHP_EOL : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI.
	exit( [] === $aisa_findings ? 0 : 1 );
}
