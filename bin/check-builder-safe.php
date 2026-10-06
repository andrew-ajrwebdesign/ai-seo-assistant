<?php
/**
 * CI check: no strip_shortcodes() call in src/ unless its line says why it is safe.
 *
 * strip_shortcodes() deletes the enclosed body of every registered shortcode. With Divi's modules
 * registered, that is all of a Divi page's text. The scan and the review read a page's words, so a call
 * that feeds them makes builder pages look empty (5.0's Utils::clean_plain_text() did, at line 159).
 *
 * A call is allowed only when its line carries a reason:
 *     strip_shortcodes( $x ); // builder-safe: <why this text never reaches the scan or review>
 *
 * Calls are found with PHP's tokenizer, so a comment or string that mentions the function is not a call.
 *
 * Usage: php bin/check-builder-safe.php [dir ...]   (default: src). Exit 1 on a finding.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

/**
 * The unexplained strip_shortcodes() calls in one file's code.
 *
 * @param string $code PHP source.
 * @return array<int,int> Line numbers.
 */
function aisa_builder_safe_findings( string $code ): array {
	$tokens = token_get_all( $code );
	$lines  = explode( "\n", $code );
	$found  = [];
	$count  = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		$t = $tokens[ $i ];
		if ( ! is_array( $t ) ) {
			continue;
		}
		// The function passed as a callable ( array_map( 'strip_shortcodes', … ) ) is a call too.
		if ( T_CONSTANT_ENCAPSED_STRING === $t[0] && 'strip_shortcodes' === strtolower( ltrim( trim( $t[1], '\'"' ), '\\' ) ) ) {
			if ( ! preg_match( '#//\s*builder-safe:\s*\S#', $lines[ (int) $t[2] - 1 ] ?? '' ) ) {
				$found[] = (int) $t[2];
			}
			continue;
		}
		if ( ! in_array( $t[0], [ T_STRING, T_NAME_FULLY_QUALIFIED ], true ) || 'strip_shortcodes' !== strtolower( ltrim( $t[1], '\\' ) ) ) {
			continue;
		}
		// The next code token must be "(" (a call), and the one before must not make it a method or a declaration.
		$next = $i + 1;
		while ( $next < $count && is_array( $tokens[ $next ] ) && in_array( $tokens[ $next ][0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
			++$next;
		}
		$prev = $i - 1;
		while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
			--$prev;
		}
		if ( $next >= $count || '(' !== $tokens[ $next ] ) {
			continue;
		}
		if ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], [ T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR ], true ) ) {
			continue;
		}
		$line = (int) $t[2];
		if ( ! preg_match( '#//\s*builder-safe:\s*\S#', $lines[ $line - 1 ] ?? '' ) ) {
			$found[] = $line;
		}
	}

	return array_values( array_unique( $found ) );
}

/**
 * Every finding under the given directories.
 *
 * @param array<int,string> $dirs Directories or files.
 * @return array<int,string> "path:line" each.
 */
function aisa_builder_safe_scan( array $dirs ): array {
	$out = [];
	foreach ( $dirs as $dir ) {
		$files = is_file( $dir ) ? [ $dir ] : [];
		if ( is_dir( $dir ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( 'php' === strtolower( $file->getExtension() ) ) {
					$files[] = $file->getPathname();
				}
			}
		}
		sort( $files );
		foreach ( $files as $file ) {
			foreach ( aisa_builder_safe_findings( (string) file_get_contents( $file ) ) as $line ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a CLI check, no WordPress.
				$out[] = $file . ':' . $line;
			}
		}
	}

	return $out;
}

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$aisa_dirs     = array_slice( $argv, 1 );
	$aisa_findings = aisa_builder_safe_scan( [] === $aisa_dirs ? [ dirname( __DIR__ ) . '/src' ] : $aisa_dirs );
	foreach ( $aisa_findings as $aisa_finding ) {
		fwrite( STDERR, $aisa_finding . ': strip_shortcodes() deletes a builder module\'s whole text. Use Utils::visible_text(), or add "// builder-safe: <reason>" to the line.' . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}
	echo [] === $aisa_findings ? 'builder-safe: no unexplained strip_shortcodes() calls.' . PHP_EOL : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI.
	exit( [] === $aisa_findings ? 0 : 1 );
}
