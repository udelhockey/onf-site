/**
 * ONF blocks — editor side. Plain JavaScript, no build step: WordPress provides the libraries (wp.*).
 * Every block is rendered by PHP (live totals); the editor shows that output and a settings panel.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var __ = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var be = wp.blockEditor;
	var c = wp.components;
	var useSelect = wp.data.useSelect;
	var ServerSideRender = wp.serverSideRender;

	/* ---------- Shared pickers ---------- */

	// Search-as-you-type picker for a player, event or fund. 0 = "this page".
	function PostPicker( props ) {
		var search = useState( '' );
		var records = useSelect(
			function ( select ) {
				var query = { per_page: 20, status: 'publish,draft', orderby: 'title', order: 'asc', _fields: 'id,title' };
				if ( search[ 0 ] ) {
					query.search = search[ 0 ];
				}
				return select( 'core' ).getEntityRecords( 'postType', props.postType, query ) || [];
			},
			[ search[ 0 ], props.postType ]
		);
		var current = useSelect(
			function ( select ) {
				return props.value ? select( 'core' ).getEntityRecord( 'postType', props.postType, props.value ) : null;
			},
			[ props.value, props.postType ]
		);
		var options = records.map( function ( r ) {
			return { value: String( r.id ), label: ( r.title && r.title.rendered ) || '#' + r.id };
		} );
		if ( current && ! options.some( function ( o ) { return o.value === String( current.id ); } ) ) {
			options.unshift( { value: String( current.id ), label: current.title.rendered || current.title.raw } );
		}
		return el( c.ComboboxControl, {
			label: props.label,
			help: props.help || __( 'Leave empty to use the page the block is on.', 'onf-core' ),
			value: props.value ? String( props.value ) : '',
			options: options,
			onFilterValueChange: function ( v ) { search[ 1 ]( v ); },
			onChange: function ( v ) { props.onChange( v ? parseInt( v, 10 ) : 0 ); },
			__nextHasNoMarginBottom: true,
		} );
	}

	function SeriesPicker( props ) {
		var terms = useSelect( function ( select ) {
			return select( 'core' ).getEntityRecords( 'taxonomy', 'onf_series', { per_page: 100, _fields: 'id,name' } ) || [];
		}, [] );
		return el( c.SelectControl, {
			label: props.label || __( 'Series', 'onf-core' ),
			value: String( props.value || 0 ),
			options: [ { value: '0', label: props.emptyLabel || __( '— This page’s series —', 'onf-core' ) } ].concat(
				terms.map( function ( t ) { return { value: String( t.id ), label: t.name }; } )
			),
			onChange: function ( v ) { props.onChange( parseInt( v, 10 ) || 0 ); },
			__nextHasNoMarginBottom: true,
		} );
	}

	function FundChecklist( props ) {
		var funds = useSelect( function ( select ) {
			return select( 'core' ).getEntityRecords( 'postType', 'onf_fund', { per_page: 100, status: 'publish,draft', orderby: 'title', order: 'asc', _fields: 'id,title' } ) || [];
		}, [] );
		var value = props.value || [];
		return el(
			c.BaseControl,
			{ label: __( 'Funds to add together', 'onf-core' ), __nextHasNoMarginBottom: true },
			funds.map( function ( f ) {
				return el( c.CheckboxControl, {
					key: f.id,
					label: f.title.rendered,
					checked: value.indexOf( f.id ) !== -1,
					onChange: function ( on ) {
						props.onChange( on ? value.concat( [ f.id ] ) : value.filter( function ( id ) { return id !== f.id; } ) );
					},
					__nextHasNoMarginBottom: true,
				} );
			} )
		);
	}

	// What a Progress or Donor board block is about.
	function TargetControls( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		var src = a.source;
		var kids = [
			el( c.SelectControl, {
				key: 'source',
				label: __( 'Show totals for', 'onf-core' ),
				value: src,
				options: [
					{ value: 'auto', label: __( 'This page (player, event, fund or series)', 'onf-core' ) },
					{ value: 'player', label: __( 'A player', 'onf-core' ) },
					{ value: 'event', label: __( 'An event', 'onf-core' ) },
					{ value: 'fund', label: __( 'A fund', 'onf-core' ) },
					{ value: 'series', label: __( 'An event series', 'onf-core' ) },
					{ value: 'funds', label: __( 'Several funds added together', 'onf-core' ) },
				],
				onChange: function ( v ) { set( { source: v } ); },
				__nextHasNoMarginBottom: true,
			} ),
		];
		if ( src === 'player' ) {
			kids.push( el( PostPicker, { key: 'p', postType: 'player', label: __( 'Player', 'onf-core' ), value: a.player, onChange: function ( v ) { set( { player: v } ); } } ) );
		}
		if ( src === 'event' ) {
			kids.push( el( PostPicker, { key: 'e', postType: 'onf_event', label: __( 'Event', 'onf-core' ), value: a.event, onChange: function ( v ) { set( { event: v } ); } } ) );
		}
		if ( src === 'fund' ) {
			kids.push( el( PostPicker, { key: 'f', postType: 'onf_fund', label: __( 'Fund', 'onf-core' ), value: a.fund, onChange: function ( v ) { set( { fund: v } ); } } ) );
		}
		if ( src === 'series' ) {
			kids.push( el( SeriesPicker, { key: 's', value: a.series, onChange: function ( v ) { set( { series: v } ); } } ) );
		}
		if ( src === 'funds' ) {
			kids.push( el( FundChecklist, { key: 'fl', value: a.funds, onChange: function ( v ) { set( { funds: v } ); } } ) );
		}
		if ( props.scopes ) {
			kids.push(
				el( c.SelectControl, {
					key: 'scope',
					label: __( 'Which gifts', 'onf-core' ),
					value: a.scope,
					options: props.scopes,
					help: __( 'Current = the event happening now (or the latest one).', 'onf-core' ),
					onChange: function ( v ) { set( { scope: v } ); },
					__nextHasNoMarginBottom: true,
				} )
			);
			if ( a.scope === 'year' ) {
				kids.push( el( c.TextControl, { key: 'y', type: 'number', label: __( 'Year (empty = this year)', 'onf-core' ), value: a.year || '', onChange: function ( v ) { set( { year: parseInt( v, 10 ) || 0 } ); }, __nextHasNoMarginBottom: true } ) );
			}
			if ( a.scope === 'event' || ( src === 'funds' && a.scope === 'current' ) ) {
				kids.push( el( PostPicker, { key: 'se', postType: 'onf_event', label: __( 'Limit to event', 'onf-core' ), help: src === 'funds' ? __( 'Empty = the open event of the funds’ series.', 'onf-core' ) : '', value: a.event, onChange: function ( v ) { set( { event: v } ); } } ) );
			}
		}
		return el( c.PanelBody, { title: __( 'What to show', 'onf-core' ) }, el( c.__experimentalVStack || c.Flex, { spacing: 4, direction: 'column' }, kids ) );
	}

	/* ---------- Preview ---------- */

	function Preview( props ) {
		var postId = useSelect( function ( select ) {
			var ed = select( 'core/editor' );
			var id = ed && ed.getCurrentPostId ? ed.getCurrentPostId() : 0;
			return typeof id === 'number' ? id : 0;
		}, [] );
		return el(
			c.Disabled,
			null,
			el( ServerSideRender, {
				block: props.name,
				attributes: props.attributes,
				urlQueryArgs: postId ? { post_id: postId } : {},
				EmptyResponsePlaceholder: function () {
					return el( 'div', { className: 'onf-block-note' }, props.empty || __( 'Nothing to show here yet.', 'onf-core' ) );
				},
			} )
		);
	}

	function edit( panels ) {
		return function ( props ) {
			var blockProps = be.useBlockProps();
			return el(
				'div',
				blockProps,
				el( be.InspectorControls, null, panels( props ) ),
				el( Preview, { name: props.name, attributes: props.attributes } )
			);
		};
	}

	function toggle( props, key, label, help ) {
		return el( c.ToggleControl, {
			key: key,
			label: label,
			help: help,
			checked: !! props.attributes[ key ],
			onChange: function ( v ) { var o = {}; o[ key ] = v; props.setAttributes( o ); },
			__nextHasNoMarginBottom: true,
		} );
	}
	function text( props, key, label, help ) {
		return el( c.TextControl, {
			key: key,
			label: label,
			help: help,
			value: props.attributes[ key ] || '',
			onChange: function ( v ) { var o = {}; o[ key ] = v; props.setAttributes( o ); },
			__nextHasNoMarginBottom: true,
		} );
	}
	function select( props, key, label, options, help ) {
		return el( c.SelectControl, {
			key: key,
			label: label,
			help: help,
			value: String( props.attributes[ key ] ),
			options: options,
			onChange: function ( v ) { var o = {}; o[ key ] = v; props.setAttributes( o ); },
			__nextHasNoMarginBottom: true,
		} );
	}
	function number( props, key, label, min, max, help ) {
		return el( c.RangeControl, {
			key: key,
			label: label,
			help: help,
			min: min,
			max: max,
			value: props.attributes[ key ],
			onChange: function ( v ) { var o = {}; o[ key ] = v || 0; props.setAttributes( o ); },
			__nextHasNoMarginBottom: true,
		} );
	}
	function panel( title, kids ) {
		return el( c.PanelBody, { title: title, initialOpen: true }, kids );
	}

	var noSave = function () { return null; };

	/* ---------- Blocks ---------- */

	registerBlockType( 'onf/donate', {
		edit: edit( function ( props ) {
			var a = props.attributes;
			var kids = [
				select( props, 'mode', __( 'Show as', 'onf-core' ), [
					{ value: 'button', label: __( 'Button', 'onf-core' ) },
					{ value: 'form', label: __( 'Donation form', 'onf-core' ) },
				], __( 'Button: on a player, event or fund page it jumps to the form; elsewhere it goes to the Donate page (active events and funds).', 'onf-core' ) ),
			];
			kids.push( a.mode === 'button' ? text( props, 'label', __( 'Button text', 'onf-core' ), __( 'Empty = “Donate”.', 'onf-core' ) ) : text( props, 'heading', __( 'Heading', 'onf-core' ), __( 'Empty = “Support {first name}” / “Give to the {event or fund}”.', 'onf-core' ) ) );
			kids.push( el( PostPicker, { key: 'p', postType: 'player', label: __( 'Give to player', 'onf-core' ), value: a.player, onChange: function ( v ) { props.setAttributes( { player: v } ); } } ) );
			kids.push( el( PostPicker, { key: 'e', postType: 'onf_event', label: __( 'Event', 'onf-core' ), help: __( 'For a player: empty = their current event.', 'onf-core' ), value: a.event, onChange: function ( v ) { props.setAttributes( { event: v } ); } } ) );
			kids.push( el( PostPicker, { key: 'f', postType: 'onf_fund', label: __( 'Give to fund', 'onf-core' ), value: a.fund, onChange: function ( v ) { props.setAttributes( { fund: v } ); } } ) );
			return panel( __( 'Donate', 'onf-core' ), kids );
		} ),
		save: noSave,
	} );

	registerBlockType( 'onf/progress', {
		edit: edit( function ( props ) {
			return [
				el( TargetControls, {
					key: 't',
					attributes: props.attributes,
					setAttributes: props.setAttributes,
					scopes: [
						{ value: 'current', label: __( 'Current event', 'onf-core' ) },
						{ value: 'event', label: __( 'A chosen event', 'onf-core' ) },
						{ value: 'year', label: __( 'One year', 'onf-core' ) },
						{ value: 'all', label: __( 'All-time', 'onf-core' ) },
					],
				} ),
				panel( __( 'Display', 'onf-core' ), [
					toggle( props, 'showGoal', __( 'Goal bar (when there is a goal)', 'onf-core' ) ),
					el( c.TextControl, { key: 'goal', type: 'number', label: __( 'Goal override ($)', 'onf-core' ), help: __( 'Empty = the player’s, event’s or fund’s own goal.', 'onf-core' ), value: props.attributes.goal || '', onChange: function ( v ) { props.setAttributes( { goal: parseFloat( v ) || 0 } ); }, __nextHasNoMarginBottom: true } ),
					toggle( props, 'breakdown', __( 'Per-fund breakdown', 'onf-core' ) ),
					toggle( props, 'showCounts', __( 'Gift and donor counts', 'onf-core' ) ),
					text( props, 'label', __( 'Label', 'onf-core' ), __( 'Empty = automatic, e.g. “Raised for the 2026 Herb Mitchell Cup”.', 'onf-core' ) ),
				] ),
			];
		} ),
		save: noSave,
	} );

	registerBlockType( 'onf/donor-board', {
		edit: edit( function ( props ) {
			return [
				el( TargetControls, {
					key: 't',
					attributes: props.attributes,
					setAttributes: props.setAttributes,
					scopes: [
						{ value: 'current', label: __( 'Current (latest) event', 'onf-core' ) },
						{ value: 'all', label: __( 'All-time', 'onf-core' ) },
					],
				} ),
				panel( __( 'Display', 'onf-core' ), [
					text( props, 'heading', __( 'Heading', 'onf-core' ) ),
					toggle( props, 'showAmounts', __( 'Show amounts', 'onf-core' ) ),
					toggle( props, 'showMessages', __( 'Show messages', 'onf-core' ) ),
					select( props, 'order', __( 'Order', 'onf-core' ), [
						{ value: 'newest', label: __( 'Newest first', 'onf-core' ) },
						{ value: 'largest', label: __( 'Largest first', 'onf-core' ) },
					] ),
					number( props, 'limit', __( 'Show before “Show all”', 'onf-core' ), 3, 60 ),
				] ),
			];
		} ),
		save: noSave,
	} );

	registerBlockType( 'onf/leaderboard', {
		edit: edit( function ( props ) {
			return panel( __( 'Leaderboard', 'onf-core' ), [
				el( PostPicker, { key: 'e', postType: 'onf_event', label: __( 'Event', 'onf-core' ), help: __( 'Empty = this event page, or the event fundraising now.', 'onf-core' ), value: props.attributes.event, onChange: function ( v ) { props.setAttributes( { event: v } ); } } ),
				number( props, 'limit', __( 'How many (0 = whole roster)', 'onf-core' ), 0, 50 ),
				toggle( props, 'more', __( '“Show all players” button below', 'onf-core' ), __( 'Off = a “See all” link to the event page instead. Rewards are set on the event (Top fundraiser rewards).', 'onf-core' ) ),
				select( props, 'layout', __( 'Layout', 'onf-core' ), [
					{ value: 'list', label: __( 'Ranked list', 'onf-core' ) },
					{ value: 'grid', label: __( 'Photo cards', 'onf-core' ) },
				] ),
				text( props, 'heading', __( 'Heading', 'onf-core' ) ),
			] );
		} ),
		save: noSave,
	} );

	registerBlockType( 'onf/foundation-total', {
		edit: edit( function ( props ) {
			return panel( __( 'Foundation total', 'onf-core' ), [
				select( props, 'layout', __( 'Layout', 'onf-core' ), [
					{ value: 'line', label: __( 'One line: “$X raised since 2015”', 'onf-core' ) },
					{ value: 'stats', label: __( 'Big numbers: raised, gifts, donors, events', 'onf-core' ) },
				] ),
				el( c.TextControl, { key: 'since', type: 'number', label: __( 'Since (year)', 'onf-core' ), help: __( 'Empty = the year of the first recorded gift.', 'onf-core' ), value: props.attributes.since || '', onChange: function ( v ) { props.setAttributes( { since: parseInt( v, 10 ) || 0 } ); }, __nextHasNoMarginBottom: true } ),
			] );
		} ),
		save: noSave,
	} );

	registerBlockType( 'onf/player-profile', {
		edit: edit( function ( props ) {
			return panel( __( 'Player profile', 'onf-core' ), [
				select( props, 'part', __( 'Part', 'onf-core' ), [
					{ value: 'photo', label: __( 'Photo', 'onf-core' ) },
					{ value: 'eyebrow', label: __( 'Number · position · current event', 'onf-core' ) },
					{ value: 'facts', label: __( 'Facts (shoots, hometown, size, teams, sponsor)', 'onf-core' ) },
					{ value: 'events', label: __( 'Events with totals', 'onf-core' ) },
					{ value: 'stats', label: __( 'EliteProspects stats', 'onf-core' ) },
					{ value: 'alltime', label: __( 'All-time total', 'onf-core' ) },
				] ),
			] );
		} ),
		save: noSave,
	} );

	registerBlockType( 'onf/event-details', {
		edit: edit( function ( props ) {
			return panel( __( 'Event details', 'onf-core' ), [
				el( PostPicker, { key: 'e', postType: 'onf_event', label: __( 'Event', 'onf-core' ), value: props.attributes.event, onChange: function ( v ) { props.setAttributes( { event: v } ); } } ),
				select( props, 'part', __( 'Part', 'onf-core' ), [
					{ value: 'all', label: __( 'Everything', 'onf-core' ) },
					{ value: 'eyebrow', label: __( 'Series · status', 'onf-core' ) },
					{ value: 'meta', label: __( 'Dates and venue', 'onf-core' ) },
					{ value: 'buttons', label: __( 'Register / Donate buttons', 'onf-core' ) },
				] ),
			] );
		} ),
		save: noSave,
	} );

	registerBlockType( 'onf/cards', {
		edit: edit( function ( props ) {
			return panel( __( 'Cards', 'onf-core' ), [
				select( props, 'source', __( 'Show', 'onf-core' ), [
					{ value: 'active', label: __( 'Events happening now', 'onf-core' ) },
					{ value: 'past', label: __( 'Past events, with what each raised', 'onf-core' ) },
					{ value: 'funds', label: __( 'Funds taking gifts', 'onf-core' ) },
				] ),
				props.attributes.source !== 'funds' ? el( SeriesPicker, { key: 's', value: props.attributes.series, emptyLabel: __( '— All series —', 'onf-core' ), onChange: function ( v ) { props.setAttributes( { series: v } ); } } ) : null,
				number( props, 'limit', __( 'How many (0 = all)', 'onf-core' ), 0, 24 ),
				text( props, 'heading', __( 'Heading', 'onf-core' ), __( 'Only shown when there are cards.', 'onf-core' ) ),
			] );
		} ),
		save: noSave,
	} );

	registerBlockType( 'onf/series-totals', {
		edit: edit( function ( props ) {
			return panel( __( 'Series totals', 'onf-core' ), [
				el( SeriesPicker, { key: 's', value: props.attributes.series, onChange: function ( v ) { props.setAttributes( { series: v } ); } } ),
			] );
		} ),
		save: noSave,
	} );
} )( window.wp );
