/**
 * Business Doctor owner app: sign in / register, organisation profile, the full (evidence-weighted)
 * assessment, and its scored result. Plain JavaScript, no build step, same conventions as
 * assessment.js (el() builder, textContent only, a small request() wrapper over fetch).
 */
( function () {
	'use strict';

	var CFG = window.BusinessDoctorOwner || {};
	var T = CFG.i18n || {};

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

	function alertBox( text ) { return el( 'div', { class: 'bd-alert', role: 'alert', text: text } ); }

	function field( label, input ) {
		return el( 'label', { class: 'bd-field' }, [ el( 'span', { text: label } ), input ] );
	}

	function App( root ) {
		this.root = root;
		this.fixed = root.getAttribute( 'data-platform' ) || '';
		this.platform = this.fixed;
		this.loggedIn = !! CFG.loggedIn;
	}

	App.prototype.render = function () {
		this.root.innerHTML = '';
		if ( ! this.loggedIn ) { return this.auth(); }
		if ( ! this.platform ) { return this.choosePlatform(); }
		this.loadOrg();
	};

	/* --- Sign in / register --- */

	App.prototype.auth = function ( mode ) {
		var self = this;
		mode = mode || 'login';
		this.root.innerHTML = '';
		var err = el( 'div', {} );
		var name = el( 'input', { type: 'text', autocomplete: 'name' } );
		var email = el( 'input', { type: 'email', autocomplete: 'email' } );
		var pass = el( 'input', { type: 'password', autocomplete: 'current-password' } );
		var fields = [ field( T.email, email ), field( T.password, pass ) ];
		if ( 'register' === mode ) { fields.unshift( field( T.fullName, name ) ); }
		var btn = el( 'button', { class: 'bd-btn bd-btn-primary', type: 'submit', text: T.continue } );
		var form = el( 'form', { class: 'bd-form', onsubmit: function ( e ) {
			e.preventDefault();
			err.innerHTML = '';
			btn.disabled = true;
			var body = { email: email.value, password: pass.value };
			if ( 'register' === mode ) { body.fullName = name.value; }
			request( 'POST', 'register' === mode ? '/owner/register' : '/owner/login', body ).then( function ( res ) {
				btn.disabled = false;
				if ( ! res.ok ) { err.appendChild( alertBox( res.message ) ); return; }
				self.loggedIn = true;
				self.render();
			} );
		} }, fields.concat( [ btn ] ) );
		var switchLink = el( 'button', { type: 'button', class: 'bd-link', text: 'register' === mode ? T.haveAccount : T.needAccount, onclick: function () {
			self.auth( 'register' === mode ? 'login' : 'register' );
		} } );
		this.root.appendChild( el( 'div', { class: 'bd-card' }, [
			el( 'h2', { text: 'register' === mode ? T.register : T.signIn } ),
			err,
			form,
			switchLink,
		] ) );
	};

	/* --- Platform choice --- */

	App.prototype.choosePlatform = function () {
		var self = this;
		this.root.innerHTML = '';
		var list = el( 'div', { class: 'bd-platform-list' } );
		( CFG.platforms || [] ).forEach( function ( p ) {
			list.appendChild( el( 'button', { class: 'bd-btn', type: 'button', text: p.label, onclick: function () {
				self.platform = p.code;
				self.render();
			} } ) );
		} );
		this.root.appendChild( el( 'div', { class: 'bd-card' }, [ el( 'h2', { text: T.choosePlatform } ), list ] ) );
	};

	/* --- Organisation profile --- */

	App.prototype.loadOrg = function () {
		var self = this;
		request( 'GET', '/owner/organisations/' + this.platform ).then( function ( res ) {
			self.org = ( res.ok && res.data && res.data.id ) ? res.data : null;
			self.orgForm();
		} );
	};

	App.prototype.orgForm = function () {
		var self = this;
		var o = this.org || {};
		this.root.innerHTML = '';
		var err = el( 'div', {} );
		var inputs = {
			name: el( 'input', { type: 'text', value: o.name || '' } ),
			sector: el( 'input', { type: 'text', value: o.sector || '' } ),
			region: el( 'input', { type: 'text', value: o.region || '' } ),
			district: el( 'input', { type: 'text', value: o.district || '' } ),
			size_band: el( 'input', { type: 'text', value: o.size_band || '' } ),
			registration_status: el( 'input', { type: 'text', value: o.registration_status || '' } ),
			phone: el( 'input', { type: 'text', value: o.phone || '' } ),
			website: el( 'input', { type: 'url', value: o.website || '' } ),
		};
		var btn = el( 'button', { class: 'bd-btn bd-btn-primary', type: 'submit', text: T.saveContinue } );
		var form = el( 'form', { class: 'bd-form', onsubmit: function ( e ) {
			e.preventDefault();
			err.innerHTML = '';
			btn.disabled = true;
			btn.textContent = T.saving;
			var body = {};
			Object.keys( inputs ).forEach( function ( k ) { body[ k ] = inputs[ k ].value; } );
			request( 'PUT', '/owner/organisations/' + self.platform, body ).then( function ( res ) {
				btn.disabled = false;
				btn.textContent = T.saveContinue;
				if ( ! res.ok ) { err.appendChild( alertBox( res.message ) ); return; }
				self.org = res.data;
				self.startAssessment();
			} );
		} }, [
			field( T.orgName, inputs.name ),
			field( T.sector, inputs.sector ),
			field( T.region, inputs.region ),
			field( T.district, inputs.district ),
			field( T.sizeBand, inputs.size_band ),
			field( T.regStatus, inputs.registration_status ),
			field( T.phone, inputs.phone ),
			field( T.website, inputs.website ),
			btn,
		] );
		this.root.appendChild( el( 'div', { class: 'bd-card' }, [ el( 'h2', { text: T.orgProfile } ), err, form ] ) );
	};

	/* --- Assessment --- */

	App.prototype.startAssessment = function () {
		var self = this;
		request( 'POST', '/owner/diagnostics/' + this.platform + '/start' ).then( function ( res ) {
			if ( ! res.ok ) { self.root.appendChild( alertBox( res.message ) ); return; }
			self.diagnostic = res.data;
			if ( 'submitted' === self.diagnostic.status ) { return self.showResult(); }
			self.assessmentForm();
		} );
	};

	App.prototype.assessmentForm = function () {
		var self = this;
		var d = this.diagnostic;
		this.root.innerHTML = '';
		var err = el( 'div', {} );
		var list = el( 'div', { class: 'bd-questions' } );
		var rows = {};
		d.questions.forEach( function ( q ) {
			var saved = d.answers && d.answers[ q.code ] ? d.answers[ q.code ] : {};
			var anchorGroup = el( 'div', { class: 'bd-anchors' } );
			var radios = [];
			( q.anchors || [] ).forEach( function ( text, i ) {
				var radio = el( 'input', { type: 'radio', name: 'bd-val-' + q.code, value: String( i ) } );
				if ( saved.value === i ) { radio.checked = true; }
				radios.push( radio );
				anchorGroup.appendChild( el( 'label', { class: 'bd-anchor' }, [ radio, el( 'span', { text: i + ' — ' + text } ) ] ) );
			} );
			var naBox = null;
			if ( q.applies && 'all' !== String( q.applies ).toLowerCase() ) {
				naBox = el( 'input', { type: 'checkbox' } );
				naBox.checked = !! saved.notApplicable;
			}
			var evSelect = el( 'select', {} );
			( CFG.evidenceClasses || [] ).forEach( function ( c ) {
				var opt = el( 'option', { value: c.value, text: c.label } );
				if ( saved.evidenceClass === c.value ) { opt.selected = true; }
				evSelect.appendChild( opt );
			} );
			var note = el( 'textarea', { rows: '2' } );
			note.value = saved.evidenceNote || '';
			var status = el( 'span', { class: 'bd-save-status' } );
			var saveBtn = el( 'button', { class: 'bd-btn', type: 'button', text: T.saveAnswer, onclick: function () {
				var value = null;
				radios.forEach( function ( r, i ) { if ( r.checked ) { value = i; } } );
				var body = {
					value: value,
					notApplicable: !! ( naBox && naBox.checked ),
					evidenceClass: evSelect.value,
					evidenceNote: note.value,
				};
				status.textContent = '…';
				request( 'PUT', '/owner/diagnostics/' + d.id + '/answers', Object.assign( { questionCode: q.code }, body ) ).then( function ( res ) {
					status.textContent = res.ok ? '✓' : ( res.message || '' );
				} );
			} } );
			var children = [ el( 'h3', { text: q.text } ), anchorGroup ];
			if ( naBox ) { children.push( el( 'label', { class: 'bd-na' }, [ naBox, el( 'span', { text: T.notApplicable } ) ] ) ); }
			children.push( field( T.evidence, evSelect ), field( T.evidenceNote, note ), el( 'div', {}, [ saveBtn, status ] ) );
			rows[ q.code ] = el( 'div', { class: 'bd-question', 'data-code': q.code }, children );
			list.appendChild( rows[ q.code ] );
		} );
		var submitBtn = el( 'button', { class: 'bd-btn bd-btn-primary', type: 'button', text: T.submit, onclick: function () {
			err.innerHTML = '';
			submitBtn.disabled = true;
			submitBtn.textContent = T.submitting;
			request( 'POST', '/owner/diagnostics/' + d.id + '/submit' ).then( function ( res ) {
				submitBtn.disabled = false;
				submitBtn.textContent = T.submit;
				if ( ! res.ok ) { err.appendChild( alertBox( res.message ) ); return; }
				self.diagnostic = res.data;
				self.showResult();
			} );
		} } );
		this.root.appendChild( el( 'div', { class: 'bd-card' }, [ el( 'h2', { text: T.resume } ), err, list, submitBtn ] ) );
	};

	/* --- Result --- */

	App.prototype.showResult = function () {
		var d = this.diagnostic;
		this.root.innerHTML = '';
		var dims = el( 'ul', { class: 'bd-dimension-scores' } );
		( d.dimensionScores || [] ).forEach( function ( row ) {
			dims.appendChild( el( 'li', {}, [ el( 'strong', { text: row.dimension } ), el( 'span', { text: ' ' + row.value } ) ] ) );
		} );
		this.root.appendChild( el( 'div', { class: 'bd-card bd-result' }, [
			el( 'h2', { text: d.platformName } ),
			el( 'p', { class: 'bd-score', text: T.overall + ': ' + d.overallScore } ),
			el( 'p', { text: T.maturity + ': ' + d.maturity } ),
			el( 'p', { text: T.confidence + ': ' + d.confidence } ),
			el( 'h3', { text: T.dimensions } ),
			dims,
		] ) );
	};

	document.addEventListener( 'DOMContentLoaded', function () {
		var roots = document.querySelectorAll( '.bd-owner-app' );
		for ( var i = 0; i < roots.length; i++ ) {
			new App( roots[ i ] ).render();
		}
	} );
} )();
