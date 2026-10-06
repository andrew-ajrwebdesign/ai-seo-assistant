/**
 * The scan's progress bar: a scan already running (cron, another screen, a screen left mid-scan) resumes
 * from the queue's stored position, the bar's ARIA values follow each step, and the finish line counts the
 * issues against the scan before.
 *
 * Run: node --test tests/js/*.test.mjs
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { El } from './fake-dom.mjs';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '../..' );

test( 'a running scan resumes at its stored position and finishes with the issue difference', async () => {
	const button = new El( 'button', {} );
	const form = new El( 'form', { 'data-aisa-scan': '' }, [ button ] );
	form.dataset = { running: '1', done: '47', total: '153' };
	const track = new El( 'div', { role: 'progressbar' } );
	track.setAttribute = ( k, v ) => ( track.attrs[ k ] = v );
	const fill = new El( 'span', { class: 'aisa-progress__fill' } );
	fill.style = {};
	track.children.push( fill );
	fill.parent = track;
	const text = new El( 'p', { 'data-aisa-progress-text': '' } );
	const live = new El( 'p', { 'data-aisa-progress-live': '' } );
	const cancel = new El( 'button', { 'data-aisa-cancel': '' } );
	const progress = new El( 'div', { 'data-aisa-progress': '' }, [ track, text, cancel, live ] );
	progress.querySelector = ( sel ) => ( '[role="progressbar"]' === sel ? track : '.aisa-progress__fill' === sel ? fill : El.prototype.querySelector.call( progress, sel ) );
	const status = new El( 'span', { 'data-aisa-scan-status': '' } );
	const body = new El( 'body', {}, [ form, status, progress ] );

	const steps = [
		{ success: true, data: { state: 'working', done: 100, total: 153 } },
		{ success: true, data: { state: 'working', done: 153, total: 153 } },
		{ success: true, data: { state: 'done', done: 153, total: 153, result: { issues: 80 }, before: 95 } },
	];
	const posted = [];
	const seen = [];
	let finished;
	const done = new Promise( ( resolve ) => ( finished = resolve ) );
	const fetch = async ( url, opts ) => {
		if ( opts && opts.body ) {
			const action = new URLSearchParams( opts.body ).get( 'action' );
			posted.push( action );
			seen.push( track.attrs[ 'aria-valuenow' ] );
			return { json: async () => steps.shift() };
		}
		finished();
		return { text: async () => '<div data-aisa-refresh></div>' }; // The in-place refresh.
	};
	const window = {
		aisaTools: { ajax: '/ajax', nonces: {}, i18n: { scanning: 'Scanning %1$d of %2$d pages', finishing: 'Checking…', leftMin: ' · about %d min left', leftSoon: ' · less than a minute left', finished: '%d pages checked just now', fewer: ' · %d fewer issues than before', more: ' · %d more issues than before', same: ' · as many', failed: 'failed' } },
		location: { href: 'https://x.test/wp-admin/admin.php?page=ai-seo-assistant-scan&aisa=rescan', reload() {} },
	};
	const document = {
		querySelector: ( s ) => body.querySelector( s ),
		querySelectorAll: ( s ) => body.querySelectorAll( s ),
		createElement: () => ( { getContext: () => null } ),
	};
	class DOMParser {
		parseFromString() {
			return { querySelector: () => null };
		}
	}
	vm.runInNewContext( fs.readFileSync( path.join( root, 'assets/js/aisa-tools.js' ), 'utf8' ), { window, document, URLSearchParams, URL, DOMParser, fetch, setTimeout, console } );

	assert.equal( track.attrs[ 'aria-valuenow' ], '47', 'resumed at the stored position, before any step' );
	assert.equal( form.hidden, true, 'the Rescan button gives way to the bar' );
	assert.equal( progress.hidden, false );
	await done;
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	assert.deepEqual( posted, [ 'aisa_scan_step', 'aisa_scan_step', 'aisa_scan_step' ], 'no new scan is started: the queue is resumed' );
	assert.deepEqual( seen, [ '47', '100', '153' ] );
	assert.equal( track.attrs[ 'aria-valuemax' ], '153' );
	assert.equal( text.textContent, '153 pages checked just now · 15 fewer issues than before' );
	assert.equal( live.textContent, text.textContent, 'the end is announced' );
	assert.equal( fill.style.inlineSize, '100%' );
} );
