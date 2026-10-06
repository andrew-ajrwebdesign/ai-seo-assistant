/**
 * AI SEO Assistant — the Report screen's "Print or save as PDF" button (the print stylesheet does the rest).
 */
( function () {
	'use strict';
	document.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '[data-aisa-print]' );
		if ( button ) {
			window.print();
		}
	} );
}() );
