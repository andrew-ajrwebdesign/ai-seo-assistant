/**
 * AI SEO Assistant — the editor's "SEO to-do for this page" box: the "Done" tick.
 *
 * Posts the tick to admin-ajax (its own nonce; the server checks the tools capability and edit_post),
 * then collapses the item to "Done <date>" and updates the count. Nodes only, never an HTML string.
 * Vanilla JS, no dependencies; without it the box still links to the full review.
 */
( function () {
	'use strict';

	const cfg = window.aisaEditor;
	const box = document.querySelector( '[data-aisa-todo-box]' );
	if ( ! cfg || ! box ) {
		return;
	}
	const live = box.querySelector( '[data-aisa-todo-live]' );

	box.addEventListener( 'click', async ( event ) => {
		const button = event.target.closest( '[data-aisa-todo-done]' );
		if ( ! button ) {
			return;
		}
		const item = button.closest( '[data-key]' );
		button.disabled = true;
		button.textContent = cfg.i18n.saving;
		const body = new URLSearchParams( { action: 'aisa_todo_done', nonce: cfg.nonce, post: box.dataset.post, key: item.dataset.key } );
		let res = null;
		try {
			res = await ( await fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body } ) ).json();
		} catch ( e ) {
			res = null;
		}
		if ( ! res || ! res.success ) {
			button.disabled = false;
			button.textContent = ( res && res.data && res.data.message ) || cfg.i18n.failed;
			return;
		}
		// Collapse to the area, the to-do and "Done <date>".
		item.classList.add( 'is-done' );
		item.querySelectorAll( '.aisa-todo__detail' ).forEach( ( el ) => el.remove() );
		const when = document.createElement( 'span' );
		when.className = 'aisa-todo__when';
		when.textContent = res.data.label;
		button.replaceWith( when );
		if ( res.data.line ) {
			box.querySelector( '[data-aisa-todo-line]' ).textContent = res.data.line;
		}
		if ( live ) {
			live.textContent = res.data.label;
		}
	} );
}() );
