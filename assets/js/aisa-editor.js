/**
 * AI SEO Assistant — the editor's "SEO to-do for this page" box: "Done" ticks, "Copy" buttons, and
 * Ctrl+C / Ctrl+A that work inside the box.
 *
 * WHY THE KEY HANDLER. Divi's backend builder listens for Ctrl+C on the window (to copy modules) and calls
 * preventDefault, so selecting a to-do and pressing Ctrl+C copied nothing (Andrew, 2026-10-06; reproduced on
 * a Divi page's edit screen). A capture listener on the window runs before Divi's: when the selection is in
 * one of our panels it writes the selected text to the clipboard itself and stops the event there. Outside
 * our panels it does nothing, so Divi's own shortcuts keep working.
 *
 * Nodes only, never an HTML string. Vanilla JS, no dependencies; without it the box still links to the
 * full review and its text can be selected as usual.
 */
( function () {
	'use strict';

	const cfg = window.aisaEditor || { i18n: {} };
	const PANEL = '[data-aisa-todo-box], .aisa-tool';

	/**
	 * Put text on the clipboard: the async API, else a hidden textarea and execCommand.
	 *
	 * @param {string} text Text.
	 * @return {Promise<boolean>} Whether it worked.
	 */
	async function copyText( text ) {
		try {
			if ( navigator.clipboard && window.isSecureContext ) {
				await navigator.clipboard.writeText( text );
				return true;
			}
		} catch {
			// Fall through to the textarea.
		}
		const area = document.createElement( 'textarea' );
		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.style.position = 'fixed';
		area.style.insetInlineStart = '-9999px';
		document.body.append( area );
		area.select();
		let ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch {
			ok = false;
		}
		area.remove();
		return ok;
	}

	/**
	 * The panel of ours a node is in, or null.
	 *
	 * @param {Node|null} node Node.
	 * @return {Element|null} Panel.
	 */
	function panelOf( node ) {
		const el = node && ( 1 === node.nodeType ? node : node.parentElement );
		return el ? el.closest( PANEL ) : null;
	}

	// Ctrl/Cmd+C and Ctrl/Cmd+A inside our panels, before any other window listener (Divi's) sees them.
	window.addEventListener(
		'keydown',
		( event ) => {
			if ( ! ( event.ctrlKey || event.metaKey ) || event.altKey ) {
				return;
			}
			const key = String( event.key ).toLowerCase();
			// The document the key was pressed in (never assumed to be the top one).
			const doc =
				( event.target && event.target.ownerDocument ) || document;
			const sel = doc.defaultView.getSelection();
			if ( 'c' === key ) {
				const text = sel ? sel.toString() : '';
				const panel = sel ? panelOf( sel.anchorNode ) : null;
				if (
					'' === text ||
					! panel ||
					panel !== panelOf( sel.focusNode )
				) {
					return; // Not ours: leave it to the page (Divi's own copy keeps working).
				}
				// Typing somewhere else (a field, a textarea, a module's editable text) keeps its own Ctrl+C,
				// even with the pointer resting over the panel.
				const active = doc.activeElement;
				if (
					active &&
					! panel.contains( active ) &&
					( active.isContentEditable ||
						/^(INPUT|TEXTAREA|SELECT)$/.test( active.tagName ) )
				) {
					return;
				}
				// A selection left in the panel while the person works on a Divi module is not a copy of
				// ours: only when the pointer or the focus is in the panel too.
				if (
					! panel.matches( ':hover' ) &&
					! panel.contains( active )
				) {
					return;
				}
				event.stopImmediatePropagation();
				copyText( text );
				event.preventDefault(); // The text is already on its way to the clipboard.
				return;
			}
			if ( 'a' === key ) {
				const active = doc.activeElement;
				const item =
					active && active.closest
						? active.closest( '[data-aisa-todo-box] [data-key]' )
						: null;
				const inPanel = sel && panelOf( sel.anchorNode );
				const target =
					item ||
					( inPanel &&
						sel.anchorNode &&
						( 1 === sel.anchorNode.nodeType
							? sel.anchorNode
							: sel.anchorNode.parentElement
						).closest( '[data-key]' ) );
				if ( ! target ) {
					return;
				}
				event.stopImmediatePropagation();
				event.preventDefault();
				const range = document.createRange();
				range.selectNodeContents(
					target.querySelector( '.aisa-todo__text' ) || target
				);
				sel.removeAllRanges();
				sel.addRange( range );
			}
		},
		true
	);

	const box = document.querySelector( '[data-aisa-todo-box]' );
	if ( ! box ) {
		return;
	}
	const live = box.querySelector( '[data-aisa-todo-live]' );

	box.addEventListener( 'click', async ( event ) => {
		const copy = event.target.closest( '[data-aisa-todo-copy]' );
		if ( copy ) {
			const ok = await copyText( copy.dataset.aisaTodoCopy || '' );
			const label = copy.textContent;
			copy.textContent = ok ? cfg.i18n.copied : cfg.i18n.copyFailed;
			if ( live ) {
				live.textContent = copy.textContent;
			}
			setTimeout( () => {
				copy.textContent = label;
			}, 1500 );
			return;
		}
		const button = event.target.closest( '[data-aisa-todo-done]' );
		if ( ! button || ! cfg.ajax ) {
			return;
		}
		const item = button.closest( '[data-key]' );
		button.disabled = true;
		button.textContent = cfg.i18n.saving;
		const body = new URLSearchParams( {
			action: 'aisa_todo_done',
			nonce: cfg.nonce,
			post: box.dataset.post,
			key: item.dataset.key,
		} );
		let res = null;
		try {
			res = await (
				await fetch( cfg.ajax, {
					method: 'POST',
					credentials: 'same-origin',
					body,
				} )
			).json();
		} catch {
			res = null;
		}
		if ( ! res || ! res.success ) {
			button.disabled = false;
			button.textContent =
				( res && res.data && res.data.message ) || cfg.i18n.failed;
			return;
		}
		// Collapse to the area, the to-do and "Done <date>".
		item.classList.add( 'is-done' );
		item.querySelectorAll(
			'.aisa-todo__detail, [data-aisa-todo-copy]'
		).forEach( ( el ) => el.remove() );
		const when = document.createElement( 'span' );
		when.className = 'aisa-todo__when';
		when.textContent = res.data.label;
		button.replaceWith( when );
		if ( res.data.line ) {
			box.querySelector( '[data-aisa-todo-line]' ).textContent =
				res.data.line;
		}
		if ( live ) {
			live.textContent = res.data.label;
		}
	} );
} )();
