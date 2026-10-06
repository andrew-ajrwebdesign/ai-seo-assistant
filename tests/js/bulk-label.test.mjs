/**
 * The bulk bar's "Generate for selected (3), about $0.09" goes into the button's LABEL span, never the
 * icon span before it (Ui::icon() prints an aria-hidden dashicons <span> first: text written there spilled
 * over the button in the icon font and was never read out). And a lint: no querySelector( 'span' ) in
 * assets/js, which is how that bug was written.
 *
 * Run: node --test tests/js/
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { El } from './fake-dom.mjs';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '../..' );

test( 'ticking 3 rows writes the label span, and the icon span stays empty', () => {
	const icon = new El( 'span', { class: 'aisa-icon dashicons', 'aria-hidden': 'true' } );
	const text = new El( 'span', { 'data-aisa-label': '', text: 'Generate for selected' } );
	const button = new El( 'button', { 'data-aisa-generate': '' }, [ icon, text ] );
	const status = new El( 'span', { 'data-aisa-bulk-status': '' } );
	const bulk = new El( 'div', { 'data-aisa-bulk': '' }, [ new El( 'span', { 'data-aisa-selected': '' } ), button, status ] );
	bulk.dataset = { perPage: '0.03', left: '10' };
	const boxes = [ 1, 2, 3, 4 ].map( ( n ) => new El( 'input', { 'data-aisa-select': '', value: String( n ) } ) );
	const table = new El( 'table', {}, boxes.map( ( b ) => new El( 'tr', {}, [ b ] ) ) );
	const body = new El( 'body', {}, [ bulk, table ] );
	const document = {
		querySelector: ( s ) => body.querySelector( s ),
		querySelectorAll: ( s ) => body.querySelectorAll( s ),
		createElement: () => ( { getContext: () => null } ),
	};
	const window = {
		aisaTools: {
			ajax: '/wp-admin/admin-ajax.php',
			nonces: {},
			i18n: { generateN: 'Generate for selected (%1$d), about %2$s', selected: '%d pages selected', selectHint: 'Select pages' },
		},
		location: { href: 'https://x.test/wp-admin/admin.php?page=ai-seo-assistant-scan' },
	};
	vm.runInNewContext( fs.readFileSync( path.join( root, 'assets/js/aisa-tools.js' ), 'utf8' ), { window, document, URLSearchParams, fetch: () => {}, setTimeout, console } );

	boxes.slice( 0, 3 ).forEach( ( b ) => {
		b.checked = true;
		b.fire( 'change' );
	} );
	assert.equal( text.textContent, 'Generate for selected (3), about $0.09' );
	assert.equal( icon.textContent, '', 'the icon span is never written' );
	assert.equal( status.textContent, 'Generate for selected (3), about $0.09', 'mirrored to the live region' );
	assert.ok( status.classes.has( 'screen-reader-text' ), 'for screen readers only: not shown twice' );
	assert.equal( button.disabled, false );
} );

test( 'lint: no querySelector( \'span\' ) in assets/js (the first span of a button is its icon)', () => {
	const dir = path.join( root, 'assets/js' );
	for ( const file of fs.readdirSync( dir ).filter( ( f ) => f.endsWith( '.js' ) && ! f.endsWith( '.min.js' ) ) ) {
		const src = fs.readFileSync( path.join( dir, file ), 'utf8' );
		assert.ok( ! /querySelector(All)?\(\s*['"]span['"]\s*\)/.test( src ), file + ' selects a bare span: give the label a data-aisa-label hook' );
	}
} );
