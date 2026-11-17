/* global jQuery, wp, rmfaFileAccess */
/**
 * File access meta box: user chips and the AJAX user search.
 *
 * @since   1.6.0
 * @version 1.6.0
 */
( function ( $ ) {
	'use strict';

	const CHIPS_SELECTOR = '.rmfa-access-user-chips';
	const SEARCH_SELECTOR = '.rmfa-access-user-search';
	const RESULTS_SELECTOR = '.rmfa-access-user-results';
	const REMOVE_SELECTOR = '.rmfa-access-user-remove';
	const RESULT_SELECTOR = '.rmfa-access-user-result';
	const CHIP_SELECTOR = '.rmfa-access-user-chip';
	const CONTAINER_SELECTOR = '.rmfa-access-users';
	const DEBOUNCE_MS = 300;
	const MIN_TERM_LENGTH = 2;

	let searchTimer = null;

	/**
	 * Read the settings printed by wp_localize_script().
	 *
	 * @return {Object} The settings.
	 */
	function settings() {
		return window.rmfaFileAccess || { ajaxUrl: '', nonce: '', i18n: {} };
	}

	/**
	 * Collect the IDs of the users already listed.
	 *
	 * @param {jQuery} $container The meta box container.
	 * @return {Array} The selected user IDs.
	 */
	function selectedIds( $container ) {
		return $container
			.find( CHIPS_SELECTOR + ' input[name="rmfa_access_users[]"]' )
			.map( function () {
				return parseInt( $( this ).val(), 10 );
			} )
			.get();
	}

	/**
	 * Append a user chip and its hidden input.
	 *
	 * @param {jQuery} $container The meta box container.
	 * @param {Object} user       The user, with id and name.
	 */
	function addUser( $container, user ) {
		const id = parseInt( user.id, 10 );

		if ( ! id || -1 !== selectedIds( $container ).indexOf( id ) ) {
			return;
		}

		const $chip = $( '<li></li>', {
			class: 'rmfa-access-user-chip',
		} ).attr( 'data-user-id', id );

		$( '<input />', {
			type: 'hidden',
			name: 'rmfa_access_users[]',
			value: id,
		} ).appendTo( $chip );

		$( '<span></span>', {
			class: 'rmfa-access-user-name',
		} )
			.text( user.name )
			.appendTo( $chip );

		$( '<button></button>', {
			type: 'button',
			class: 'rmfa-access-user-remove',
			'aria-label': ( settings().i18n.remove || 'Remove' ) + ' ' + user.name,
			html: '&times;',
		} ).appendTo( $chip );

		$chip.appendTo( $container.find( CHIPS_SELECTOR ) );
	}

	/**
	 * Render the search results.
	 *
	 * @param {jQuery} $container The meta box container.
	 * @param {Array}  users      The users returned by the search.
	 */
	function renderResults( $container, users ) {
		const $results = $container.find( RESULTS_SELECTOR ).empty();

		$container.find( SEARCH_SELECTOR ).attr( 'aria-expanded', users.length ? 'true' : 'false' );

		if ( ! users.length ) {
			$( '<li></li>', { class: 'rmfa-access-user-empty' } )
				.text( settings().i18n.noResults || 'No users found.' )
				.appendTo( $results );

			return;
		}

		users.forEach( function ( user ) {
			$( '<li></li>' )
				.append(
					$( '<button></button>', {
						type: 'button',
						class: 'rmfa-access-user-result',
					} )
						.attr( 'data-user-id', user.id )
						.attr( 'data-user-name', user.name )
						.text( user.name + ' (' + user.login + ')' )
				)
				.appendTo( $results );
		} );
	}

	/**
	 * Ask the server for users matching the term.
	 *
	 * @param {jQuery} $container The meta box container.
	 * @param {string} term       The search term.
	 */
	function searchUsers( $container, term ) {
		wp.ajax
			.post( 'rmfa_search_users', {
				nonce: settings().nonce,
				term: term,
			} )
			.done( function ( response ) {
				renderResults( $container, response || [] );
			} )
			.fail( function () {
				renderResults( $container, [] );
			} );
	}

	// wp.ajax.post() needs the global, which is not printed on every screen.
	window.ajaxurl = window.ajaxurl || settings().ajaxUrl;

	$( document ).on( 'click', REMOVE_SELECTOR, function ( event ) {
		event.preventDefault();
		$( this ).closest( CHIP_SELECTOR ).remove();
	} );

	// Enter in the search field would submit the attachment form.
	$( document ).on( 'keydown', SEARCH_SELECTOR, function ( event ) {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
		}
	} );

	$( document ).on( 'input', SEARCH_SELECTOR, function () {
		const $input = $( this );
		const $container = $input.closest( CONTAINER_SELECTOR );
		const term = String( $input.val() ).trim();

		window.clearTimeout( searchTimer );

		if ( term.length < MIN_TERM_LENGTH ) {
			$container.find( RESULTS_SELECTOR ).empty();
			$input.attr( 'aria-expanded', 'false' );
			return;
		}

		searchTimer = window.setTimeout( function () {
			searchUsers( $container, term );
		}, DEBOUNCE_MS );
	} );

	$( document ).on( 'click', RESULT_SELECTOR, function ( event ) {
		event.preventDefault();

		const $button = $( this );
		const $container = $button.closest( CONTAINER_SELECTOR );

		addUser( $container, {
			id: $button.attr( 'data-user-id' ),
			name: $button.attr( 'data-user-name' ),
		} );

		$container.find( SEARCH_SELECTOR ).val( '' ).attr( 'aria-expanded', 'false' );
		$container.find( RESULTS_SELECTOR ).empty();
	} );
} )( jQuery );
