/**
 * Player name search on an event's Top fundraisers list: type part of a name and the list narrows down,
 * including the players under "Show all". Without JavaScript the box stays hidden and the list works as normal.
 */
( function () {
	'use strict';
	var fold = function ( s ) {
		return s.toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
	};
	document.querySelectorAll( '.onf-roster-filter' ).forEach( function ( box ) {
		var block = box.closest( '.onf-leaderboard' );
		var input = box.querySelector( 'input' );
		if ( ! block || ! input ) {
			return;
		}
		var items = Array.prototype.slice.call( block.querySelectorAll( '.onf-leader' ) );
		var more = block.querySelector( '.onf-leaderboard__more' );
		var openedByUs = false;
		box.hidden = false;
		input.addEventListener( 'input', function () {
			var q = fold( input.value.trim() );
			items.forEach( function ( li ) {
				var name = li.querySelector( '.onf-leader__name' );
				li.hidden = q !== '' && fold( name ? name.textContent : '' ).indexOf( q ) === -1;
			} );
			if ( more ) {
				if ( q !== '' && ! more.open ) {
					more.open = true;
					openedByUs = true;
				} else if ( q === '' && openedByUs ) {
					more.open = false;
					openedByUs = false;
				}
			}
		} );
	} );
} )();
