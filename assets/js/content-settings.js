( function () {
	var settings = window.PicotSubscriptionMembershipContentSettings || {};

	function purchaseAmounts() {
		return document.querySelectorAll( '.picot-subscription-membership-purchase-amount' );
	}

	function syncPurchaseAmounts() {
		var enabled = document.getElementById( 'picot-subscription-membership-purchase-enabled' );
		var amounts = purchaseAmounts();

		if ( ! enabled || ! amounts.length ) {
			return;
		}

		amounts.forEach(
			function ( amount ) {
				amount.disabled = ! enabled.checked;
				amount.setCustomValidity( '' );
			}
		);
	}

	function validatePurchasePrices( event ) {
		var enabled  = document.getElementById( 'picot-subscription-membership-purchase-enabled' );
		var amounts  = purchaseAmounts();
		var hasPrice = Array.prototype.some.call(
			amounts,
			function ( amount ) {
				return amount.value !== '' && amount.validity.valid;
			}
		);

		if ( enabled && enabled.checked && amounts.length && ! hasPrice ) {
			amounts[ 0 ].setCustomValidity( settings.priceRequiredMessage || '個別販売には、設定した販売通貨の価格を入力してください。' );
			event.preventDefault();
		} else if ( amounts.length ) {
			amounts[ 0 ].setCustomValidity( '' );
		}
	}

	document.addEventListener(
		'DOMContentLoaded',
		function () {
			var enabled = document.getElementById( 'picot-subscription-membership-purchase-enabled' );
			if ( ! enabled ) {
				return;
			}

			enabled.addEventListener( 'change', syncPurchaseAmounts );
			purchaseAmounts().forEach(
				function ( amount ) {
					amount.addEventListener(
						'input',
						function () {
							amount.setCustomValidity( '' ); }
					);
				}
			);
			enabled.closest( 'form' ).addEventListener( 'submit', validatePurchasePrices );
			syncPurchaseAmounts();
		}
	);
}() );
