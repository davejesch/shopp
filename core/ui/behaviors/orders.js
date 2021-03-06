/*!
 * orders.js - Behaviors for the order manager
 * Copyright © 2011 by Ingenesis Limited. All rights reserved.
 * Licensed under the GPLv3 {@see license.txt}
 */

jQuery(document).ready( function($) {

	$.template('shipment-ui',$('#shipment-ui'));
	$.template('shipnotice-ui',$('#shipnotice-ui'));
	$.template('refund-ui',$('#refund-ui'));
	$.template('address-ui',$('#address-editor'));
	$.template('customer-ui',$('#customer-editor'));
	$.template('customer-select',$('#customer-selector'));
	$.template('change-customer',$('#change-customer-ui'));

	var manager = $('#order-manage'),
		managerui = manager.find('div.manager-ui'),

		cmo = '',
		ctr = $.each(carriers,function (code,carrier) {
			cmo += '<option value="'+code+'">'+carrier[0]+'</option>';
		}),

		shipbtn = $('#shipnote-button').click(function (e) {
			e.preventDefault();
			var $this = $(this).hide(),
				shipments = [],
				ui = $.tmpl('shipnotice-ui',{shipmentnum:2}),
				addshipli = ui.find('ol li'),

				upaddshipnum = function () {
					addshipli.find('span.number').text(shipments.length+1+'.');
				},

				addbtn = ui.find('#addship-button').click(function (e) {
					e.preventDefault();

					var shipnum = shipments.length,
						shipment = $.tmpl('shipment-ui',{id:shipnum,num:shipnum+1}),

						carriermenu = shipment.find('select').html(cmo),

						tracking = shipment.find('.tracking').change(function () {
							var tracknum = $(this).val().toUpperCase().replace(/[^A-Z0-9]/,'');
							$.each(carriers,function (c,r) {
								if (tracknum.match( new RegExp(r[1].substr( 1,r[1].length-2 )) ) != null) {
									carriermenu.val(c);
									return false;
								}
							});
						}),

						deletebtn = shipment.find('button.delete').click(function (e) {
							e.preventDefault();
							shipment.remove();
							shipments.splice(shipnum,1);
							upaddshipnum();

						}).hide();

					if (shipnum > 0)
						shipment.hover(function() {deletebtn.show();},function () {deletebtn.fadeOut('fast');});
					shipments.push(shipment.insertBefore(addshipli));
					upaddshipnum();

				}).click(),

				cancel = ui.find('#cancel-ship').click(function (e) {
					e.preventDefault();
					ui.fadeRemove('fast',function () {
						$this.show();
					});
				});

				managerui.empty().append(ui);
		}),

		refundbtn = $('#cancel-order, #refund-button').click(function (e) {
			e.preventDefault();

			var $this = $(this).attr('disabled',true),
				data = ('refund-button' == $this.attr('id') ?
					{ action:'refund',title:$om.ro,cancel:$om.cancel,send:$om.stg,process:$om.pr,reason: $om.rr } :
					{ action:'cancel',title:$om.co,cancel:$om.dnc,send:$om.stg,process:$om.co,reason: $om.rc,disable_amount: ' disabled="disabled"' }),
				ui = $.tmpl('refund-ui',data),

				cancel = ui.find('#cancel-refund').click(function (e) {
					e.preventDefault();
					ui.fadeRemove('fast',function () {
						$this.show();
					});

				});

				managerui.empty().append(ui);
		}),

		printbtn = $('#print-button').click(function (e) {
			e.preventDefault();
			var frame = $('#print-receipt').get(0), fw = frame.contentWindow;
			fw.focus();
			fw.print();
			return false;
		}),

		editaddress = function (type) {
			var $this = $(this),
				data = address[ type ],
				ui = $.tmpl('address-ui',data),
				editorui = $('#'+type+'-address-editor'),
				display = $('#order-'+type+' .display'),

				cancel = ui.find('#cancel-edit-address').click(function (e) {
					e.preventDefault();
					ui.fadeRemove('fast',function () {
						$this.show();
						display.show();
					});

				});

			ui.find('#address-state-menu').html(data.statemenu);
			ui.find('#address-country').upstate().html(data.countrymenu);

			display.hide();
			editorui.hide().empty().append(ui).slideDown('fast');

		},

		billaddrctrls = $('#edit-billing-address, #order-billing address').click(function (e) {
			e.preventDefault();
			editaddress('billing');
			return false;
		}),

		billaddrctrls = $('#edit-shipping-address, #order-shipto address').click(function (e) {
			e.preventDefault();
			editaddress('shipping');
			return false;
		}),

		editcustomer = $('#edit-customer').click(function (e) {
			e.preventDefault();
			var $this = $(this),
				ui = $.tmpl('customer-ui',customer),
				editorui = $('#customer-editor-form'),
				display = $('#order-contact .display'),
				panel = $('#order-contact .inside'),
				cancel = function (e) {
					e.preventDefault();
					ui.fadeRemove('fast',function () {
						$this.show();
						display.show();
					});
				},
				change = ui.find('#change-customer').click(function (e) {
					e.preventDefault();
					editorui.hide();
					ui = $.tmpl('change-customer');
					var changeui = $('#change-customer-editor').empty().append(ui).slideDown('fast');

					var results = changeui.find('#customer-search-results').hide(),
						changebutton = changeui.find('.change-button').hide(),
						cancelchange = $('#cancel-change-customer').hide(),
						editcancel = ui.find('#cancel-edit-customer').click(cancel),
						searching = $('#customer-search').submit(function (e) {
							$('#change-customer').hide();
							cancelchange.show().click(cancel);
							results.show();
						});
				}),
				caneledit = ui.find('#cancel-edit-customer').click(cancel);

			display.hide();
			editorui.hide().empty().append(ui).slideDown('fast');
			return false;
		});
});