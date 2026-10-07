/**
 * Event edit screen: pick or remove a reward picture from the Media Library.
 */
jQuery( function ( $ ) {
	'use strict';
	$( '#onf_event_rewards' ).on( 'click', '.onf-reward-pick', function () {
		var cell = $( this ).closest( '.onf-reward-image' );
		var frame = wp.media( { title: 'Reward picture', library: { type: 'image' }, multiple: false } );
		frame.on( 'select', function () {
			var img = frame.state().get( 'selection' ).first().toJSON();
			var src = ( img.sizes && img.sizes.thumbnail ? img.sizes.thumbnail.url : img.url );
			cell.find( 'input[type=hidden]' ).val( img.id );
			cell.find( '.onf-reward-thumb' ).empty().append( $( '<img width="40" height="40" alt="">' ).attr( 'src', src ) );
			cell.find( '.onf-reward-clear' ).prop( 'hidden', false );
		} );
		frame.open();
	} );
	$( '#onf_event_rewards' ).on( 'click', '.onf-reward-clear', function () {
		var cell = $( this ).closest( '.onf-reward-image' );
		cell.find( 'input[type=hidden]' ).val( 0 );
		cell.find( '.onf-reward-thumb' ).empty();
		$( this ).prop( 'hidden', true );
	} );
} );
