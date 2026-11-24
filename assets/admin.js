/**
 * Livestreams admin page: the Add streams table (extra rows, ticking a row
 * when it is filled in, select all) and the image picker on the edit form.
 */
( function ( $, wp, l10n ) {
	var $rows = $( '.ccd-ls-rows' );
	var next = $rows.children( 'tr' ).length;

	// Typing in a row ticks it.
	$rows.on( 'input change', 'input:not(.ccd-ls-check)', function () {
		var $row = $( this ).closest( 'tr' );
		var filled = $row.find( 'input:not(.ccd-ls-check)' ).filter( function () {
			return '' !== this.value;
		} ).length;
		$row.find( '.ccd-ls-check' ).prop( 'checked', filled > 0 );
	} );

	$( '.ccd-ls-add-row' ).on( 'click', function () {
		var html = $( '#ccd-ls-row-template' ).html().replace( /__i__/g, String( next++ ) );
		var $row = $( html );
		$row.find( '.ccd-ls-hint' ).remove();
		$rows.append( $row );
		$row.find( 'input[type=date]' ).trigger( 'focus' );
	} );

	$( '.ccd-ls-check-all' ).on( 'change', function () {
		$rows.find( '.ccd-ls-check' ).prop( 'checked', this.checked );
	} );

	// Image picker on the edit form.
	var frame;
	var $image = $( '#ccd-ls-image' );
	var $preview = $( '#ccd-ls-image-preview' );
	var $remove = $( '#ccd-ls-image-remove' );

	$( '#ccd-ls-image-choose' ).on( 'click', function ( event ) {
		event.preventDefault();
		if ( ! frame ) {
			frame = wp.media( {
				title: l10n.title,
				button: { text: l10n.button },
				library: { type: 'image' },
				multiple: false,
			} );
			frame.on( 'select', function () {
				var file = frame.state().get( 'selection' ).first().toJSON();
				var size = file.sizes && ( file.sizes.medium_large || file.sizes.large || file.sizes.full );
				$image.val( file.id );
				$preview.attr( 'src', size ? size.url : file.url );
				$remove.prop( 'hidden', false );
			} );
		}
		frame.open();
	} );

	$remove.on( 'click', function () {
		$image.val( '0' );
		$preview.attr( 'src', $preview.data( 'youtube' ) );
		$remove.prop( 'hidden', true );
	} );
} )( jQuery, window.wp, window.ccdLivestreamsAdmin || {} );
