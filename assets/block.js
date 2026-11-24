/**
 * Editor view of the "Livestreams grid" block.
 *
 * The block is rendered on the server, so the editor shows a placeholder
 * explaining what appears on the page and linking to the admin page.
 */
( function ( wp, settings ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var Placeholder = wp.components.Placeholder;
	var Button = wp.components.Button;

	wp.blocks.registerBlockType( 'ccd-livestreams/grid', {
		edit: function () {
			return el(
				'div',
				useBlockProps(),
				el(
					Placeholder,
					{
						icon: 'video-alt3',
						label: __( 'Livestreams grid', 'ccd-livestreams' ),
						instructions: __( 'Shows the livestreams dated in the last two weeks, newest first, two per row. The streams appear on the published page.', 'ccd-livestreams' ),
					},
					el(
						Button,
						{ variant: 'secondary', href: settings.manageUrl, target: '_blank' },
						__( 'Manage livestreams', 'ccd-livestreams' )
					)
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp, window.ccdLivestreams || { manageUrl: '' } );
