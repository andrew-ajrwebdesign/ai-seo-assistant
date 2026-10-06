/**
 * A just-enough DOM for the script tests (no dependencies).
 */

/** A just-enough DOM element: attributes, children, listeners, classList, closest, querySelector(All). */
export class El {
	constructor( tag, attrs = {}, children = [] ) {
		this.tag = tag;
		this.attrs = attrs;
		this.children = children;
		this.parent = null;
		this.listeners = {};
		this.dataset = {};
		this.textContent = attrs.text || '';
		this.checked = false;
		this.disabled = false;
		this.value = attrs.value || '';
		this.classes = new Set();
		this.classList = {
			toggle: ( c, on ) => ( on ? this.classes.add( c ) : this.classes.delete( c ) ),
			add: ( c ) => this.classes.add( c ),
			remove: ( c ) => this.classes.delete( c ),
		};
		children.forEach( ( c ) => ( c.parent = this ) );
	}
	matches( sel ) {
		const attr = sel.match( /^\[([a-z-]+)\]$/ );
		return attr ? attr[ 1 ] in this.attrs : this.tag === sel;
	}
	all() {
		return this.children.flatMap( ( c ) => [ c, ...c.all() ] );
	}
	querySelector( sel ) {
		return this.all().find( ( e ) => e.matches( sel ) ) || null;
	}
	querySelectorAll( sel ) {
		return this.all().filter( ( e ) => e.matches( sel ) );
	}
	closest( sel ) {
		let e = this;
		while ( e && ! e.matches( sel ) ) {
			e = e.parent;
		}
		return e;
	}
	addEventListener( type, fn ) {
		( this.listeners[ type ] ||= [] ).push( fn );
	}
	fire( type ) {
		( this.listeners[ type ] || [] ).forEach( ( fn ) => fn( { currentTarget: this, preventDefault() {} } ) );
	}
}
