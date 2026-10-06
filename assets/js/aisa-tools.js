/**
 * AI SEO Assistant 5.0 — the agency screens' progressive enhancement.
 *
 * Every action here also works without this script (plain admin-post forms); the script adds progress:
 * Rescan now in steps over AJAX (so a 500-page site never hits a time limit), bulk "Generate for
 * selected" one page at a time with a cost estimate, the "Writing…" state while Claude works on a page,
 * and live fit meters while a suggestion is edited. Vanilla JS, no dependencies.
 */
( function () {
	'use strict';

	const cfg = window.aisaTools;
	if ( ! cfg ) {
		return;
	}
	const t = cfg.i18n;

	/**
	 * A tiny sprintf for %1$d / %2$s / %d / %s.
	 *
	 * @param {string} text Pattern.
	 * @param {...*}   args Values.
	 * @return {string} Text.
	 */
	function fmt( text, ...args ) {
		let i = 0;
		return text.replace( /%(?:(\d+)\$)?[ds]/g, ( m, n ) => String( args[ n ? n - 1 : i++ ] ) );
	}

	/**
	 * POST to admin-ajax.
	 *
	 * @param {string} action Action.
	 * @param {Object} data   Fields.
	 * @return {Promise<Object>} Parsed JSON ({ success, data }).
	 */
	async function post( action, data = {} ) {
		const body = new URLSearchParams( { action, nonce: cfg.nonce, ...data } );
		const res = await fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body } );
		let json = null;
		try {
			json = await res.json();
		} catch ( e ) {
			json = { success: false, data: { message: res.statusText } };
		}
		return json;
	}

	/* ---- Rescan now ---------------------------------------------------------------------------- */

	const scanForm = document.querySelector( '[data-aisa-scan]' );
	const scanStatus = document.querySelector( '[data-aisa-scan-status]' );

	async function runScan( start ) {
		const button = scanForm.querySelector( 'button' );
		button.disabled = true;
		button.classList.add( 'is-busy' );
		let state = start ? await post( 'aisa_scan_start' ) : { success: true, data: { state: 'working', done: 0, total: 0 } };
		while ( state && state.success && [ 'working', 'busy' ].includes( state.data.state ) ) {
			if ( state.data.total > 0 ) {
				scanStatus.textContent = state.data.done >= state.data.total ? t.finishing : fmt( t.scanning, state.data.done, state.data.total );
			}
			if ( 'busy' === state.data.state ) {
				await new Promise( ( resolve ) => setTimeout( resolve, 3000 ) );
			}
			state = await post( 'aisa_scan_step' );
		}
		if ( state && state.success && 'done' === state.data.state ) {
			scanStatus.textContent = t.done;
			window.location.reload();
			return;
		}
		scanStatus.textContent = t.failed;
		button.disabled = false;
		button.classList.remove( 'is-busy' );
	}

	if ( scanForm && scanStatus ) {
		scanForm.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			runScan( true );
		} );
		// A scan queued by a push or a save is waiting: work through it while the screen is open (where
		// WP-Cron is off, this is what finishes it).
		if ( scanForm.dataset.running ) {
			runScan( false );
		}
	}

	/* ---- Bulk generate ------------------------------------------------------------------------- */

	const bulk = document.querySelector( '[data-aisa-bulk]' );
	if ( bulk ) {
		const boxes = Array.from( document.querySelectorAll( '[data-aisa-select]' ) );
		const all = bulk.querySelector( '[data-aisa-select-all]' );
		const label = bulk.querySelector( '[data-aisa-selected]' );
		const button = bulk.querySelector( '[data-aisa-generate]' );
		const status = bulk.querySelector( '[data-aisa-bulk-status]' );
		const perPage = parseFloat( bulk.dataset.perPage ) || 0.03;
		const left = parseFloat( bulk.dataset.left ) || 0;

		const refresh = () => {
			const chosen = boxes.filter( ( box ) => box.checked );
			boxes.forEach( ( box ) => box.closest( 'tr' ).classList.toggle( 'is-selected', box.checked ) );
			bulk.classList.toggle( 'is-active', chosen.length > 0 );
			label.textContent = chosen.length ? fmt( t.selected, chosen.length ) : t.selectHint;
			if ( all ) {
				all.checked = chosen.length > 0 && chosen.length === boxes.length;
				all.indeterminate = chosen.length > 0 && chosen.length < boxes.length;
			}
			if ( button ) {
				const cost = '$' + ( chosen.length * perPage ).toFixed( 2 );
				button.disabled = 0 === chosen.length || chosen.length * perPage > left;
				button.querySelector( 'span' ).textContent = chosen.length ? fmt( t.generateN, chosen.length, cost ) : button.dataset.label || button.querySelector( 'span' ).textContent;
			}
		};
		if ( button ) {
			button.dataset.label = button.querySelector( 'span' ).textContent;
		}
		boxes.forEach( ( box ) => box.addEventListener( 'change', refresh ) );
		if ( all ) {
			all.addEventListener( 'change', () => {
				boxes.forEach( ( box ) => {
					box.checked = all.checked;
				} );
				refresh();
			} );
		}
		if ( button ) {
			button.addEventListener( 'click', async () => {
				const chosen = boxes.filter( ( box ) => box.checked );
				button.disabled = true;
				for ( let i = 0; i < chosen.length; i++ ) {
					const row = chosen[ i ].closest( 'tr' );
					const rowStatus = row.querySelector( '[data-aisa-row-status]' );
					status.textContent = fmt( t.writing, i + 1, chosen.length );
					rowStatus.textContent = '…';
					const res = await post( 'aisa_generate', { post: chosen[ i ].value } );
					rowStatus.textContent = res.success ? '✓' : ( res.data && res.data.message ) || '';
					if ( ! res.success && res.data && 'aisa_spend_cap' === res.data.code ) {
						status.textContent = res.data.message;
						return;
					}
				}
				status.textContent = t.written;
				window.location.reload();
			} );
		}
		refresh();
	}

	/* ---- Page roles: the list's tag, the bulk bar, the review header ----------------------------- */

	async function setRole( ids, role, status ) {
		if ( status ) {
			status.textContent = t.savingRole;
		}
		const res = await post( 'aisa_set_role', { posts: ids.join( ',' ), role } );
		if ( res.success ) {
			window.location.reload();
			return;
		}
		if ( status ) {
			status.textContent = ( res.data && res.data.message ) || t.roleFailed;
		}
	}

	document.querySelectorAll( '[data-aisa-role]' ).forEach( ( select ) => {
		select.addEventListener( 'change', () => {
			select.disabled = true;
			setRole( [ select.dataset.aisaRole ], select.value, select.closest( 'tr' ).querySelector( '[data-aisa-row-status]' ) );
		} );
	} );

	const bulkRole = document.querySelector( '[data-aisa-bulk-role]' );
	const bulkRoleButton = document.querySelector( '[data-aisa-set-role]' );
	if ( bulkRole && bulkRoleButton ) {
		const picks = Array.from( document.querySelectorAll( '[data-aisa-select]' ) );
		const all = document.querySelector( '[data-aisa-select-all]' );
		const update = () => {
			bulkRoleButton.disabled = '' === bulkRole.value || ! picks.some( ( box ) => box.checked );
		};
		[ bulkRole, all, ...picks ].forEach( ( el ) => el && el.addEventListener( 'change', update ) );
		bulkRoleButton.addEventListener( 'click', () => {
			bulkRoleButton.disabled = true;
			setRole( picks.filter( ( box ) => box.checked ).map( ( box ) => box.value ), bulkRole.value, document.querySelector( '[data-aisa-bulk-status]' ) );
		} );
		update();
	}

	document.querySelectorAll( '[data-aisa-role-submit]' ).forEach( ( select ) => {
		const button = select.form.querySelector( '[data-aisa-role-button]' );
		if ( button ) {
			button.hidden = true;
		}
		select.addEventListener( 'change', () => select.form.submit() );
	} );

	/* ---- One page: "Writing…" while Claude works (C2) ------------------------------------------- */

	document.querySelectorAll( '[data-aisa-generate-one]' ).forEach( ( form ) => {
		form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			const panel = document.querySelector( '[data-aisa-panel]' );
			const id = form.dataset.aisaGenerateOne;
			if ( panel && ! panel.querySelector( 'textarea' ) && panel.dataset.fields ) {
				// First suggestions for this page: show the fields being written (C2).
				Array.from( panel.children ).slice( 1 ).forEach( ( el ) => {
					el.hidden = true;
				} );
				panel.dataset.fields.split( '|' ).forEach( ( label ) => {
					const box = document.createElement( 'div' );
					box.className = 'aisa-sfield';
					const head = document.createElement( 'p' );
					head.className = 'aisa-sfield__head';
					const strong = document.createElement( 'strong' );
					strong.textContent = label;
					const pill = document.createElement( 'span' );
					pill.className = 'aisa-pill aisa-push';
					pill.textContent = t.writingField;
					head.append( strong, pill );
					const a = document.createElement( 'span' );
					a.className = 'aisa-skeleton';
					const b = document.createElement( 'span' );
					b.className = 'aisa-skeleton aisa-skeleton--short';
					box.append( head, a, b );
					panel.append( box );
				} );
			}
			if ( panel ) {
				panel.querySelectorAll( 'textarea' ).forEach( ( area ) => {
					const sk = document.createElement( 'span' );
					sk.className = 'aisa-skeleton';
					const sk2 = document.createElement( 'span' );
					sk2.className = 'aisa-skeleton aisa-skeleton--short';
					area.replaceWith( sk, sk2 );
				} );
				panel.querySelectorAll( 'button' ).forEach( ( b ) => {
					b.disabled = true;
				} );
				const note = document.createElement( 'div' );
				note.className = 'aisa-notice aisa-notice--info';
				note.setAttribute( 'role', 'status' );
				note.textContent = form.dataset.writing || panel.dataset.writing || '';
				panel.insertBefore( note, panel.children[ 1 ] || null );
			}
			const res = await post( 'aisa_generate', { post: id } );
			if ( res.success ) {
				window.location.reload();
				return;
			}
			const message = ( res.data && res.data.message ) || '';
			const err = document.createElement( 'div' );
			err.className = 'aisa-notice aisa-notice--error';
			err.setAttribute( 'role', 'alert' );
			err.textContent = message;
			( panel || form ).prepend( err );
		} );
	} );

	/* ---- Live fit meters, and "Edit" chosen as soon as the text changes -------------------------- */

	const canvas = document.createElement( 'canvas' );
	const ctx = canvas.getContext( '2d' );
	if ( ctx ) {
		ctx.font = '20px Arial';
	}

	document.querySelectorAll( 'textarea[data-aisa-meter]' ).forEach( ( area ) => {
		const kind = area.dataset.aisaMeter;
		const field = area.closest( '[data-aisa-field]' );
		const fit = field ? field.querySelector( '[data-aisa-fit]' ) : null;
		area.addEventListener( 'input', () => {
			const edit = field && field.querySelector( 'input[value="edit"]' );
			if ( edit ) {
				edit.checked = true;
			}
			if ( ! fit || ! kind ) {
				return;
			}
			const text = area.value.trim();
			const n = 'px' === kind && ctx ? Math.round( ctx.measureText( text ).width ) : text.length;
			const limit = 'px' === kind ? 580 : 155;
			const bad = 'px' === kind ? n > limit : n > 160 || n < 70;
			fit.classList.toggle( 'is-bad', bad );
			fit.classList.toggle( 'is-good', ! bad );
			fit.querySelector( '.aisa-fit__bar span' ).style.inlineSize = Math.min( 100, Math.round( ( n / limit ) * 100 ) ) + '%';
			fit.querySelector( '.aisa-fit__text' ).textContent = fmt( 'px' === kind ? t.fitPx : t.fitChars, n, limit );
		} );
	} );
}() );
