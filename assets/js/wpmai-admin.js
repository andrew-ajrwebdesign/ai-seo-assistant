/* global wpmai */
( function () {
	'use strict';

	// -------------------------------------------------------------------------
	// Noindex toggle — show/hide excluded content table
	// -------------------------------------------------------------------------
	var toggleBtn   = document.getElementById( 'wpmai-noindex-toggle' );
	var toggleTable = document.getElementById( 'wpmai-noindex-list' );

	if ( toggleBtn && toggleTable ) {
		var open = false;

		toggleBtn.addEventListener( 'click', function () {
			open                    = ! open;
			toggleTable.style.display = open ? 'table' : 'none';
			toggleBtn.textContent   = open
				? wpmai.i18n.hideExcluded
				: wpmai.i18n.showExcluded;
		} );
	}

	// -------------------------------------------------------------------------
	// Markdown preview — fetch and display ?format=markdown output
	// -------------------------------------------------------------------------
	var previewSelect = document.getElementById( 'wpmai-preview-select' );
	var previewBtn    = document.getElementById( 'wpmai-preview-btn' );
	var previewWrap   = document.getElementById( 'wpmai-preview-wrap' );
	var previewOutput = document.getElementById( 'wpmai-preview-output' );
	var previewStatus = document.getElementById( 'wpmai-preview-status' );
	var previewLink   = document.getElementById( 'wpmai-preview-link' );

	if ( previewBtn && previewSelect ) {
		previewBtn.addEventListener( 'click', function () {
			var url = previewSelect.value;

			if ( ! url ) {
				return;
			}

			previewWrap.style.display   = 'block';
			previewOutput.value         = '';
			previewStatus.textContent   = wpmai.i18n.loading;
			previewLink.style.display   = 'none';

			fetch( url, { credentials: 'same-origin' } )
				.then( function ( res ) {
					if ( ! res.ok ) {
						throw new Error( 'HTTP ' + res.status );
					}
					return res.text();
				} )
				.then( function ( text ) {
					previewOutput.value       = text;
					previewStatus.textContent = wpmai.i18n.success;
					previewLink.href          = url;
					previewLink.style.display = 'inline';
				} )
				.catch( function ( err ) {
					previewStatus.textContent = wpmai.i18n.error + err.message;
				} );
		} );
	}
} () );
