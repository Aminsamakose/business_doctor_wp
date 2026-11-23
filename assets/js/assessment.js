/**
 * Business Doctor free assessment: register, 25 questions, result.
 * Plain JavaScript, no build step. All text is inserted with textContent, never innerHTML.
 */
( function () {
	'use strict';

	var CFG = window.BusinessDoctor || {};
	var T = CFG.i18n || {};

	function fmt( s ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return String( s ).replace( /%(\d)\$d/g, function ( m, n ) { return String( args[ Number( n ) - 1 ] ); } );
	}

	/** Builds an element: el('div', {class: 'x'}, [children or text]). */
	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			var v = attrs[ k ];
			if ( v === null || v === undefined || v === false ) { return; }
			if ( k === 'class' ) { node.className = v; } else if ( k === 'text' ) { node.textContent = v; } else if ( k.slice( 0, 2 ) === 'on' ) { node.addEventListener( k.slice( 2 ), v ); } else { node.setAttribute( k, v === true ? '' : v ); }
		} );
		( children || [] ).forEach( function ( c ) {
			if ( c === null || c === undefined || c === false ) { return; }
			node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
		return node;
	}

	function request( method, path, body ) {
		var opts = { method: method, headers: { 'X-WP-Nonce': CFG.nonce || '' }, credentials: 'same-origin' };
		if ( body !== undefined ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}
		return fetch( CFG.api + path, opts ).then( function ( res ) {
			return res.json().catch( function () { return null; } ).then( function ( j ) {
				if ( ! res.ok ) {
					return { ok: false, status: res.status, message: ( j && j.message ) || T.generic, fields: ( j && j.data && j.data.fields ) || {} };
				}
				return { ok: true, data: j };
			} );
		}, function () {
			return { ok: false, status: 0, message: T.network, fields: {} };
		} );
	}

	function storageKey( platform ) { return 'bd_result_' + platform; }
	function remember( platform, token ) { try { window.sessionStorage.setItem( storageKey( platform ), token ); } catch ( e ) {} }
	function recall( platform ) { try { return window.sessionStorage.getItem( storageKey( platform ) ); } catch ( e ) { return null; } }
	function forget( platform ) { try { window.sessionStorage.removeItem( storageKey( platform ) ); } catch ( e ) {} }

	function alertBox( text ) { return el( 'div', { class: 'bd-alert', role: 'alert', text: text } ); }

	function App( root ) {
		this.root = root;
		this.fixed = root.getAttribute( 'data-platform' ) || '';
		this.platform = this.fixed;
	}

	App.prototype.show = function ( node ) {
		this.root.innerHTML = '';
		this.root.appendChild( node );
		var focus = node.querySelector( 'h2, h3, [data-focus]' );
		if ( focus ) { focus.setAttribute( 'tabindex', '-1' ); focus.focus( { preventScroll: false } ); }
	};

	App.prototype.start = function () {
		var self = this;
		if ( this.platform ) {
			var saved = recall( this.platform );
			if ( saved ) {
				request( 'GET', '/results/' + saved ).then( function ( r ) {
					if ( r.ok ) { self.result( r.data ); } else { forget( self.platform ); self.register(); }
				} );
				return;
			}
			this.register();
			return;
		}
		request( 'GET', '/platforms' ).then( function ( r ) {
			if ( ! r.ok || ! r.data.length ) { self.show( el( 'div', { class: 'bd-card' }, [ alertBox( r.ok ? T.unavailable : r.message ) ] ) ); return; }
			if ( r.data.length === 1 ) { self.platform = r.data[ 0 ].code; self.start(); return; }
			self.chooser( r.data );
		} );
	};

	App.prototype.chooser = function ( platforms ) {
		var self = this;
		var list = el( 'div', { class: 'bd-choices' }, platforms.map( function ( p ) {
			return el( 'button', { type: 'button', class: 'bd-choice', onclick: function () { self.platform = p.code; self.start(); } }, [
				el( 'strong', { text: p.label } ),
				el( 'span', { text: fmt( T.questionCount, p.questions ) } )
			] );
		} ) );
		this.show( el( 'div', { class: 'bd-card' }, [ el( 'h2', { text: T.choose } ), list ] ) );
	};

	App.prototype.register = function () {
		var self = this;
		var errors = {};
		var top = el( 'div', {} );
		function fieldError( name ) { return el( 'p', { class: 'bd-field-error', role: 'alert', 'data-error-for': name } ); }
		var nameIn = el( 'input', { id: 'bd-name', name: 'fullName', autocomplete: 'name', required: true, maxlength: '120' } );
		var emailIn = el( 'input', { id: 'bd-email', name: 'email', type: 'email', autocomplete: 'email', required: true, maxlength: '150' } );
		var privacy = el( 'input', { type: 'checkbox', id: 'bd-privacy', name: 'privacy', required: true } );
		var honey = el( 'input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' } );
		var privacyText = [ T.privacyBefore + ' ' ];
		privacyText.push( CFG.privacyUrl ? el( 'a', { href: CFG.privacyUrl, target: '_blank', rel: 'noopener', text: T.privacyLink } ) : T.privacyLink );
		privacyText.push( '. *' );
		var btn = el( 'button', { type: 'submit', class: 'bd-btn bd-btn-primary', text: T.start } );

		var form = el( 'form', { class: 'bd-card bd-form', novalidate: true, 'aria-label': T.start }, [
			top,
			el( 'div', { class: 'bd-grid' }, [
				el( 'div', {}, [ el( 'label', { for: 'bd-name', text: T.name + ' *' } ), nameIn, fieldError( 'fullName' ) ] ),
				el( 'div', {}, [ el( 'label', { for: 'bd-email', text: T.email + ' *' } ), emailIn, fieldError( 'email' ) ] )
			] ),
			el( 'label', { class: 'bd-check' }, [ privacy, el( 'span', {}, privacyText ) ] ),
			fieldError( 'privacy' ),
			el( 'div', { class: 'bd-hp' }, [ el( 'label', { text: 'Website' } ), honey ] ),
			btn
		] );

		function showErrors( fields, message ) {
			top.innerHTML = '';
			if ( message ) { top.appendChild( alertBox( message ) ); }
			form.querySelectorAll( '[data-error-for]' ).forEach( function ( p ) {
				var k = p.getAttribute( 'data-error-for' );
				p.textContent = fields[ k ] || '';
			} );
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			errors = {};
			var name = nameIn.value.trim();
			var email = emailIn.value.trim();
			if ( name.length < 2 ) { errors.fullName = T.needName; }
			if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email ) ) { errors.email = T.needEmail; }
			if ( ! privacy.checked ) { errors.privacy = T.needPrivacy; }
			if ( Object.keys( errors ).length ) { showErrors( errors, T.checkFields ); return; }
			btn.disabled = true; btn.textContent = T.starting;
			request( 'POST', '/eligibility', { email: email, platform: self.platform } ).then( function ( r ) {
				if ( ! r.ok ) { btn.disabled = false; btn.textContent = T.start; showErrors( r.fields, r.message ); return null; }
				if ( ! r.data.eligible ) { self.alreadyUsed( r.data ); return null; }
				return request( 'GET', '/questions/' + self.platform );
			} ).then( function ( q ) {
				if ( ! q ) { return; }
				btn.disabled = false; btn.textContent = T.start;
				if ( ! q.ok ) { showErrors( {}, q.message ); return; }
				self.applicant = { fullName: name, email: email, website: honey.value };
				self.questions( q.data );
			} );
		} );
		this.show( form );
		nameIn.focus();
	};

	App.prototype.alreadyUsed = function ( r ) {
		var actions = [];
		if ( CFG.fullUrl ) { actions.push( el( 'a', { class: 'bd-btn bd-btn-primary', href: CFG.fullUrl, text: T.explore } ) ); }
		actions.push( el( 'a', { class: 'bd-btn', href: CFG.homeUrl, text: T.home } ) );
		this.show( el( 'div', { class: 'bd-card', role: 'alert' }, [ el( 'h3', { text: r.title } ), el( 'p', { class: 'bd-muted', text: r.body } ), el( 'div', { class: 'bd-actions' }, actions ) ] ) );
	};

	App.prototype.questions = function ( set ) {
		var self = this;
		var qs = set.questions;
		var answers = {};
		var i = 0;
		var busy = false;
		var error = null;

		function render() {
			var q = qs[ i ];
			var picked = answers[ q.code ];
			var answered = Object.keys( answers ).length;
			var all = answered === qs.length;
			var last = i === qs.length - 1;
			var options = el( 'div', { class: 'bd-options', role: 'radiogroup', 'aria-label': q.text }, [ 'A', 'B', 'C', 'D' ].map( function ( L ) {
				return el( 'button', { type: 'button', role: 'radio', 'aria-checked': picked === L ? 'true' : 'false', class: 'bd-option' + ( picked === L ? ' is-picked' : '' ), onclick: function () { pick( L ); } }, [
					el( 'span', { class: 'bd-letter', text: L } ), el( 'span', { text: q.options[ L ] } )
				] );
			} ) );
			var nav = el( 'div', { class: 'bd-nav' }, [
				el( 'button', { type: 'button', class: 'bd-btn', disabled: i === 0, onclick: function () { i--; render(); }, text: T.back } ),
				last
					? el( 'button', { type: 'button', class: 'bd-btn bd-btn-primary', disabled: ! all || busy, onclick: submit, text: busy ? T.scoring : T.see } )
					: el( 'button', { type: 'button', class: 'bd-btn bd-btn-primary', disabled: ! picked, onclick: function () { i++; render(); }, text: T.next } )
			] );
			var card = el( 'div', { class: 'bd-card' }, [
				el( 'div', { class: 'bd-progress-head' }, [ el( 'span', { text: q.dimension } ), el( 'span', { text: fmt( T.question, i + 1, qs.length ) } ) ] ),
				el( 'div', { class: 'bd-progress' }, [ el( 'div', { style: 'width:' + ( ( i + 1 ) / qs.length * 100 ) + '%' } ) ] ),
				el( 'h3', { class: 'bd-question', text: q.text } ),
				options,
				error ? alertBox( error ) : null,
				nav,
				last && ! all ? el( 'p', { class: 'bd-muted', text: fmt( T.answerAll, qs.length, answered ) } ) : null
			] );
			self.show( card );
		}

		function pick( L ) {
			answers[ qs[ i ].code ] = L;
			error = null;
			if ( i < qs.length - 1 ) { render(); window.setTimeout( function () { i++; render(); }, 150 ); } else { render(); }
		}

		function submit() {
			busy = true; error = null; render();
			request( 'POST', '/complete', {
				platform: set.platform, fullName: self.applicant.fullName, email: self.applicant.email,
				privacy: true, website: self.applicant.website, answers: answers
			} ).then( function ( r ) {
				busy = false;
				if ( ! r.ok ) { error = r.message; render(); return; }
				if ( ! r.data.eligible ) { self.alreadyUsed( r.data ); return; }
				remember( set.platform, r.data.token );
				self.result( r.data );
			} );
		}
		render();
	};

	App.prototype.result = function ( r ) {
		var self = this;
		var tone = 'bd-band-' + r.classification.toLowerCase().replace( /\s+/g, '-' );
		var status = el( 'p', { class: 'bd-muted', role: 'status' } );
		var mailBtn = el( 'button', { type: 'button', class: 'bd-btn', text: T.emailMe } );
		mailBtn.addEventListener( 'click', function () {
			mailBtn.disabled = true; mailBtn.textContent = T.sending; status.textContent = '';
			request( 'POST', '/results/' + r.token + '/email', {} ).then( function ( res ) {
				if ( res.ok ) { mailBtn.textContent = T.emailed; } else { mailBtn.disabled = false; mailBtn.textContent = T.emailMe; status.textContent = res.message; }
			} );
		} );
		var dims = el( 'ul', { class: 'bd-dims' }, r.dimensionScores.map( function ( d ) {
			return el( 'li', {}, [
				el( 'div', { class: 'bd-dim-head' }, [ el( 'span', { class: 'bd-dim-name', text: d.dimension } ), el( 'span', { class: 'bd-muted', text: d.score + '/100' } ) ] ),
				el( 'div', { class: 'bd-progress' }, [ el( 'div', { style: 'width:' + Math.max( 0, Math.min( 100, d.score ) ) + '%' } ) ] )
			] );
		} ) );
		var actions = [ mailBtn, el( 'a', { class: 'bd-btn', href: r.reportUrl, target: '_blank', rel: 'noopener', text: T.report } ) ];
		var after = [];
		if ( CFG.fullUrl ) { after.push( el( 'a', { class: 'bd-btn bd-btn-primary', href: CFG.fullUrl, text: T.explore } ) ); }
		if ( ! self.fixed ) {
			after.push( el( 'button', { type: 'button', class: 'bd-btn', text: T.startOver, onclick: function () { self.platform = ''; self.start(); } } ) );
		}
		this.show( el( 'div', { class: 'bd-card bd-result' }, [
			el( 'h2', { text: T.resultTitle } ),
			el( 'p', { class: 'bd-muted', text: r.platformName + '  |  ' + r.reference } ),
			el( 'p', { class: 'bd-score ' + tone }, [ el( 'span', { class: 'bd-num', text: String( r.overallScore ) } ), el( 'span', { class: 'bd-of', text: '/100' } ) ] ),
			el( 'p', { class: 'bd-band-label ' + tone, text: r.classification } ),
			el( 'h3', { text: T.dimensions } ),
			dims,
			el( 'p', {}, [ el( 'strong', { text: T.strengths + ': ' } ), r.strengths.join( ', ' ) ] ),
			el( 'p', {}, [ el( 'strong', { text: T.priorities + ': ' } ), r.priorities.join( ', ' ) ] ),
			el( 'p', { class: 'bd-note', text: CFG.disclaimer } ),
			el( 'div', { class: 'bd-actions' }, actions ),
			status,
			el( 'p', { class: 'bd-muted bd-small', text: T.keepCopy } ),
			after.length ? el( 'div', { class: 'bd-actions bd-after' }, after ) : null
		] ) );
	};

	function boot() {
		document.querySelectorAll( '[data-bd-assessment]' ).forEach( function ( root ) {
			if ( root.getAttribute( 'data-bd-ready' ) ) { return; }
			root.setAttribute( 'data-bd-ready', '1' );
			new App( root ).start();
		} );
	}
	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', boot ); } else { boot(); }
}() );
