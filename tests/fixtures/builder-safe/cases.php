<?php
// Fixture for bin/check-builder-safe.php. Never loaded; each line's comment says what the check must decide.

$a = strip_shortcodes( $content ); // Flagged: line 4.
$b = \strip_shortcodes( $content ); // Flagged: line 5 (fully qualified).
$c = STRIP_SHORTCODES ( $content ); // Flagged: line 6 (PHP function names ignore case and spacing).
$d = strip_shortcodes( $content ); // builder-safe: only an excerpt shown in a notice, never scanned or reviewed.
$e = strip_shortcodes( $content ); // builder-safe:
// A comment that says strip_shortcodes( $content ) is not a call.
$f = 'strip_shortcodes( $content )'; // Nor is a string.
$g = $obj->strip_shortcodes( $content ); // Nor a method of something else.
$h = Some_Class::strip_shortcodes( $content ); // Nor a static method.
$i = array_map( 'strip_shortcodes', $list ); // Flagged: line 13 (the function as a callable).
