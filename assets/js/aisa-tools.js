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
		const body = new URLSearchParams( { action, nonce: ( cfg.nonces || {} )[ action ] || '', ...data } );
		const res = await fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body } );
		let json = null;
		try {
			json = await res.json();
		} catch ( e ) {
			json = { success: false, data: { message: res.statusText } };
		}
		return json;
	}

	/* ---- Rescan now: a progress bar in the header ------------------------------------------------ */

	const scanForm = document.querySelector( '[data-aisa-scan]' );
	const scanStatus = document.querySelector( '[data-aisa-scan-status]' );
	const progress = document.querySelector( '[data-aisa-progress]' );
	let stopRequested = false;

	/**
	 * Show the bar at done / total, with the time left from this screen's average per page.
	 *
	 * @param {number}      done    Pages scanned.
	 * @param {number}      total   Pages in the scan.
	 * @param {number|null} perPage Seconds per page so far (null: not known yet).
	 */
	function showProgress( done, total, perPage ) {
		const track = progress.querySelector( '[role="progressbar"]' );
		const text = progress.querySelector( '[data-aisa-progress-text]' );
		const live = progress.querySelector( '[data-aisa-progress-live]' );
		total = Math.max( 1, total );
		track.setAttribute( 'aria-valuemax', String( total ) );
		track.setAttribute( 'aria-valuenow', String( Math.min( done, total ) ) );
		progress.querySelector( '.aisa-progress__fill' ).style.inlineSize = Math.min( 100, Math.round( ( 100 * done ) / total ) ) + '%';
		let line = done >= total ? t.finishing : fmt( t.scanning, done, total );
		if ( done < total && perPage ) {
			const mins = Math.round( ( ( total - done ) * perPage ) / 60 );
			line += mins >= 1 ? fmt( t.leftMin, mins ) : t.leftSoon;
		}
		text.textContent = line;
		// Screen readers hear it every 10 pages and at the end, not on every step.
		const bucket = Math.floor( done / 10 );
		if ( bucket !== Number( live.dataset.bucket ) || done >= total ) {
			live.dataset.bucket = String( bucket );
			live.textContent = line;
		}
	}

	/**
	 * The finish line, and the list and issue chips refreshed in place.
	 *
	 * @param {string} line What to say.
	 */
	async function finishScan( line ) {
		progress.querySelector( '[data-aisa-progress-text]' ).textContent = line;
		progress.querySelector( '[data-aisa-progress-live]' ).textContent = line;
		progress.querySelector( '[data-aisa-cancel]' ).hidden = true;
		progress.querySelector( '.aisa-progress__fill' ).style.inlineSize = '100%';
		try {
			const url = new URL( window.location.href );
			url.searchParams.delete( 'aisa' );
			const html = await ( await fetch( url.toString(), { credentials: 'same-origin' } ) ).text();
			const doc = new DOMParser().parseFromString( html, 'text/html' );
			const fresh = doc.querySelector( '[data-aisa-refresh]' );
			const here = document.querySelector( '[data-aisa-refresh]' );
			const sub = doc.querySelector( '.aisa-hero__sub' );
			if ( fresh && here ) {
				// Nodes, never an HTML string: the fetched page's own nodes, adopted into this document.
				here.replaceChildren( ...Array.from( fresh.childNodes, ( node ) => document.importNode( node, true ) ) );
				initList();
			}
			if ( sub && document.querySelector( '.aisa-hero__sub' ) ) {
				document.querySelector( '.aisa-hero__sub' ).textContent = sub.textContent;
			}
		} catch ( e ) {
			window.location.reload();
			return;
		}
		scanForm.hidden = false;
		const button = scanForm.querySelector( 'button' );
		button.disabled = false;
		button.classList.remove( 'is-busy' );
	}

	/**
	 * Work through the queue a step at a time (resuming at its stored position when one is running).
	 *
	 * @param {boolean} start Queue a new full scan first.
	 */
	async function runScan( start ) {
		const button = scanForm.querySelector( 'button' );
		button.disabled = true;
		scanForm.hidden = true;
		scanStatus.hidden = true;
		progress.hidden = false;
		progress.querySelector( '[data-aisa-cancel]' ).hidden = false;
		stopRequested = false;
		let state = start ? await post( 'aisa_scan_start' ) : { success: true, data: { state: 'working', done: Number( scanForm.dataset.done ) || 0, total: Number( scanForm.dataset.total ) || 0 } };
		const began = Date.now();
		const firstDone = state && state.data ? state.data.done : 0;
		let lastTotal = 0;
		while ( state && state.success && [ 'working', 'busy' ].includes( state.data.state ) ) {
			lastTotal = state.data.total;
			const scanned = state.data.done - firstDone;
			// Time left only once this screen has seen ten pages go by (an early guess swings wildly).
			showProgress( state.data.done, state.data.total, scanned >= 10 ? ( Date.now() - began ) / 1000 / scanned : null );
			if ( stopRequested ) {
				const res = await post( 'aisa_scan_cancel' );
				const d = res && res.data ? res.data : state.data;
				await finishScan( fmt( t.stopped, d.done, d.total ) );
				return;
			}
			if ( 'busy' === state.data.state ) {
				await new Promise( ( resolve ) => setTimeout( resolve, 3000 ) );
			}
			state = await post( 'aisa_scan_step' );
		}
		if ( state && state.success && 'done' === state.data.state ) {
			const total = state.data.total;
			let line = fmt( t.finished, total );
			const now = state.data.result ? Number( state.data.result.issues ) : NaN;
			const before = state.data.before;
			if ( null !== before && undefined !== before && ! Number.isNaN( now ) ) {
				const diff = now - Number( before );
				if ( 0 === diff ) {
					line += t.same;
				} else if ( 1 === Math.abs( diff ) ) {
					line += diff < 0 ? t.fewer1 : t.more1;
				} else {
					line += fmt( diff < 0 ? t.fewer : t.more, Math.abs( diff ) );
				}
			}
			showProgress( total, total, null );
			await finishScan( line );
			return;
		}
		if ( state && state.success && 'idle' === state.data.state ) {
			// Finished by another screen or by cron while this one waited: nothing failed.
			await finishScan( fmt( t.finished, lastTotal ) );
			return;
		}
		if ( state && state.success && 'cancelled' === state.data.state ) {
			// Cancelled on another screen: say so, keep what was scanned.
			await finishScan( fmt( t.stopped, state.data.done, state.data.total ) );
			return;
		}
		progress.querySelector( '[data-aisa-progress-text]' ).textContent = t.failed;
		progress.querySelector( '[data-aisa-cancel]' ).hidden = true;
		scanForm.hidden = false;
		button.disabled = false;
	}

	if ( scanForm && scanStatus && progress ) {
		scanForm.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			runScan( true );
		} );
		progress.querySelector( '[data-aisa-cancel]' ).addEventListener( 'click', ( event ) => {
			stopRequested = true;
			event.currentTarget.hidden = true;
			progress.querySelector( '[data-aisa-progress-text]' ).textContent = t.stopping;
		} );
		// A scan already running (a push, a save, another screen, cron): show it from its stored position and
		// work through it while the screen is open (where WP-Cron is off, this is what finishes it). Leaving
		// the screen is safe: the queue carries on in cron.
		if ( scanForm.dataset.running ) {
			runScan( false );
		}
	}

	/* ---- Bulk generate ------------------------------------------------------------------------- */

	/**
	 * Bind the list's controls (bulk bar, page type tags). Run at load and after the list is refreshed.
	 */
	function initList() {
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
					// The label span, never the first span (that is the aria-hidden icon).
					const text = button.querySelector( '[data-aisa-label]' );
					text.textContent = chosen.length ? fmt( t.generateN, chosen.length, cost ) : button.dataset.label || text.textContent;
					if ( status && chosen.length ) {
						// Heard through the bar's live region, but not shown twice on screen.
						status.textContent = text.textContent;
						status.classList.add( 'screen-reader-text' );
					}
				}
			};
			if ( button ) {
				button.dataset.label = button.querySelector( '[data-aisa-label]' ).textContent;
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
						status.classList.remove( 'screen-reader-text' ); // Progress is worth seeing.
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

		/* ---- Page types (AJR Core): the list's tag and the bulk bar ---------------------------------- */

		document.querySelectorAll( '[data-aisa-role]' ).forEach( ( select ) => {
			select.addEventListener( 'change', () => {
				select.disabled = true;
				setType( [ select.dataset.aisaRole ], select.value, select.closest( 'tr' ).querySelector( '[data-aisa-row-status]' ) );
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
				setType( picks.filter( ( box ) => box.checked ).map( ( box ) => box.value ), bulkRole.value, document.querySelector( '[data-aisa-bulk-status]' ) );
			} );
			update();
		}
	}
	initList();

	async function setType( ids, type, status ) {
		if ( status ) {
			status.textContent = t.savingRole;
		}
		const res = await post( 'aisa_set_page_type', { posts: ids.join( ',' ), type } );
		if ( res.success ) {
			window.location.reload();
			return;
		}
		if ( status ) {
			status.textContent = ( res.data && res.data.message ) || t.roleFailed;
		}
	}

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
