( function ( blocks, blockEditor, components, element, i18n ) {
	'use strict';

	var el                = element.createElement;
	var __                = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var InnerBlocks       = blockEditor.InnerBlocks;
	var PanelBody         = components.PanelBody;
	var SelectControl     = components.SelectControl;
	var CheckboxControl   = components.CheckboxControl;
	var settings          = window.PicotSubscriptionMembershipRestrictedContentBlock || {};
	var labels            = settings.labels || {};

	function label( key, fallback ) {
		return labels[ key ] || __( fallback, 'picot-subscription-membership' );
	}

	blocks.registerBlockType(
		'picot-subscription-membership/restricted-content',
		{
			title: label( 'title', 'Membership 限定コンテンツ' ),
			icon: 'lock',
			category: 'widgets',
			attributes: {
				mode: { type: 'string', default: 'paid' },
				plans: { type: 'array', default: [] }
			},
			edit: function ( props ) {
				var attributes     = props.attributes;
				var availablePlans = ( window.PicotSubscriptionMembershipRestrictedContentBlock && window.PicotSubscriptionMembershipRestrictedContentBlock.plans ) || [];
				var selectedPlans  = attributes.plans || [];
				var planControls   = availablePlans.map(
					function ( plan ) {
						return el(
							CheckboxControl,
							{
								key: plan.slug,
								label: plan.name,
								checked: selectedPlans.indexOf( plan.slug ) !== -1,
								onChange: function ( checked ) {
									var nextPlans = selectedPlans.filter(
										function ( slug ) {
											return slug !== plan.slug; }
									);
									if ( checked ) {
										nextPlans.push( plan.slug ); }
									props.setAttributes( { plans: nextPlans } );
								}
							}
						);
					}
				);
				return el(
					'div',
					{ className: 'psm-restricted-block-editor' },
					el(
						InspectorControls,
						{},
						el(
							PanelBody,
							{ title: label( 'access', '閲覧制限' ), initialOpen: true },
							el(
								SelectControl,
								{
									label: label( 'target', '対象' ),
									value: attributes.mode || 'paid',
									options: [
										{ label: label( 'all_paid_members', 'すべての有料会員' ), value: 'paid' },
										{ label: label( 'specific_plans', '指定プランの会員' ), value: 'plans' }
									],
									onChange: function ( mode ) {
										props.setAttributes( { mode: mode } ); }
								}
							),
							'plans' === attributes.mode && el(
								'div',
								{},
								el( 'p', {}, label( 'eligible_plans', '対象プラン' ) ),
								planControls.length ? planControls : el( 'p', { className : 'description' }, label( 'no_plans', '有効なプランがありません。先にMembershipのプランを作成してください。' ) )
							)
						)
					),
					el(
						'div',
						{ className: 'components-placeholder' },
						el( 'strong', {}, label( 'member_content', '会員限定コンテンツ' ) ),
						el( 'p', {}, 'plans' === attributes.mode ? label( 'plans_message', '指定プランの会員だけに表示されます。' ) : label( 'paid_message', '有料会員だけに表示されます。' ) ),
						el( InnerBlocks, { templateLock: false } )
					)
				);
			},
			save: function () {
				return el( InnerBlocks.Content ); }
		}
	);
}( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n ) );
