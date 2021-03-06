<?php
/**
 * Order
 *
 * Order controller that manages the relevant order objects
 *
 * @author Jonathan Davis
 * @version 1.3
 * @copyright Ingenesis Limited, April 2013
 * @license GNU GPL version 3 (or later) {@see license.txt}
 * @package shopp
 * @subpackage order
 **/

defined( 'WPINC' ) || header( 'HTTP/1.1 403' ) & exit; // Prevent direct access

/**
 * Order controller
 *
 * @author Jonathan Davis
 * @since 1.1
 * @version 1.2
 * @package order
 **/
class ShoppOrder {

	public $Customer = false;			// The current customer
	public $Shipping = false;			// The shipping address
	public $Billing = false;			// The billing address
	public $Cart = false;				// The shopping cart
	public $Tax = false;				// The tax calculator
	public $Shiprates = false;			// The shipping service rates calculator
	public $Discounts = false;			// The discount manager

	public $Promotions = false;			// The promotions loader
	public $Payments = false;			// The payments manager
	public $Checkout = false;			// The checkout processor

	public $data = array();				// Extra/custom order data
	public $sameaddress = false;		// Toggle for copying a primary address to the secondary address

	// Post processing properties
	public $inprogress = false;			// Generated purchase ID
	public $purchase = false;			// Purchase ID of the finalized sale
	public $txnid = false;				// The transaction ID reported by the gateway

	public $state;						// Checksum state of the items in the cart

	/**
	 * Order constructor
	 *
	 * @author Jonathan Davis
	 *
	 * @return void
	 **/
	public function __construct () {

		$this->init();

		// Set locking timeout for concurrency operation protection
		if ( ! defined('SHOPP_TXNLOCK_TIMEOUT')) define('SHOPP_TXNLOCK_TIMEOUT',10);

		add_action('parse_request', array($this, 'request'));
		add_action('parse_request', array($this->Discounts, 'requests'));

		// Order processing
		add_action('shopp_process_order', array($this, 'validate'), 7);
		add_action('shopp_process_order', array($this, 'submit'), 100);

		add_action('shopp_process_free_order', array($this, 'freebie'));

		add_action('shopp_update_destination', array($this->Shipping, 'locate'));
		add_action('shopp_update_destination', array($this->Billing, 'fromshipping'));
		add_action('shopp_update_destination', array($this, 'taxaddress'));

		add_action('shopp_purchase_order_event', array($this, 'purchase'));
		add_action('shopp_purchase_order_created', array($this, 'invoice'));
		add_action('shopp_purchase_order_created', array($this, 'process'));

		add_action('shopp_authed_order_event', array($this, 'unstock'));
		add_action('shopp_authed_order_event', array($this, 'captured'));

		// Ensure payment card PAN is truncated after successful processing
		add_action('shopp_authed_order_event', array($this, 'securecard'));

		add_action('shopp_resession', array($this, 'init'));
		add_action('shopp_pre_resession', array($this, 'clear'), 100);

		// Collect available payment methods from active gateways
		// Schedule for after the gateways are loaded (priority 20)
		add_action('shopp_init', array($this->Payments, 'options'), 20);

		// Process customer selected payment methods after gateways are loaded (priority 20)
		add_action('shopp_init', array($this->Payments, 'request'), 20);

		// Select the default gateway processor
		// Schedule for after the gateways are loaded (priority 20)
		add_action('shopp_init', array($this->Payments, 'selected'), 20);

		// Handle remote transaction processing (priority 20)
		// Needs to happen after the processor is selected in the session,
		// but before gateway-order specific handlers are established
		add_action('shopp_init', array($this, 'txnupdates'), 20);

	}

	function init () {

		$this->Cart = Shopping::restart( 'ShoppCart', $this->Cart );
		$this->Customer = Shopping::restart( 'ShoppCustomer', $this->Customer );

		$this->Billing = Shopping::restart( 'BillingAddress', $this->Billing );
		$this->Billing->locate();

		$this->Shipping = Shopping::restart( 'ShippingAddress', $this->Shipping );
		$this->Shipping->locate();

		$this->Tax = Shopping::restart( 'ShoppTax', $this->Tax );
		$this->taxaddress();

		$this->Shiprates = Shopping::restart( 'ShoppShiprates', $this->Shiprates );
		$this->Discounts = Shopping::restart( 'ShoppDiscounts', $this->Discounts );

		// Store order custom data and post processing data
		Shopping::restore('data', $this->data);
		Shopping::restore('inprogress', $this->inprogress);
		Shopping::restore('purchase', $this->purchase);
		Shopping::restore('txnid', $this->txnid);
		Shopping::restore('orderstate', $this->state);

		$this->Promotions = new ShoppPromotions;
		$this->Payments = new ShoppPayments;
		$this->Checkout = new ShoppCheckout;

	}

	/**
	 * Handles checkout request flow control
	 *
	 * @author Jonathan Davis
	 * @since 1.2.3
	 *
	 * @return void
	 **/
	public function request () {

		if ( ! empty($_REQUEST['rmtpay']) )
			return do_action('shopp_remote_payment');

		if ( array_key_exists('checkout', $_POST) ) {

			$checkout = strtolower($_POST['checkout']);
			switch ( $checkout ) {
				case 'process':		do_action('shopp_process_checkout'); break;
				case 'confirmed':	do_action('shopp_confirm_order'); break;
			}

		} elseif ( array_key_exists('shipmethod', $_POST) ) {

			do_action('shopp_process_shipmethod');

		} elseif ( isset($_REQUEST['shipping']) ) {

			do_action_ref_array( 'shopp_update_destination', array($_REQUEST['shipping']) );

		}

	}

	/**
	 * Handles remote transaction update request flow control
	 *
	 * Moved from the Flow class in 1.2.3
	 *
	 * @author Jonathan Davis
	 * @since 1.2.3
	 *
	 * @return void
	 **/
	public function txnupdates () {

		add_action('shopp_txn_update', create_function('',"status_header('200'); exit();"), 101); // Default shopp_txn_update requests to HTTP status 200

		if ( ! empty($_REQUEST['_txnupdate']) )
			return do_action('shopp_txn_update');

	}

	/**
	 * Set the taxable address
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @return void
	 **/
	public function taxaddress () {
		$this->Tax->address($this->Billing, $this->Shipping, $this->Cart->shipped());
	}

	/**
	 * Submits the order to create a Purchase record
	 *
	 * @author Jonathan Davis
	 * @since 1.2
	 *
	 * @return void
	 **/
	public function submit () {
		if ( ! $this->Payments->processor() ) return; // Don't do anything if there is no payment processor

		shopp_add_order_event(false, 'purchase', array(
			'gateway' => $this->Payments->processor()
		));
	}

	/**
	 * Creates an invoice transaction event to setup the payment balance
	 *
	 * @author Jonathan Davis
	 * @since 1.2
	 *
	 * @return void
	 **/
	public function invoice ($Purchase) {
		shopp_add_order_event($Purchase->id, 'invoiced', array(
			'gateway' => $Purchase->gateway,			// Gateway handler name (module name from @subpackage)
			'amount' => $Purchase->total				// Capture of entire order amount
		));
	}


	/**
	 * Fires an unstock order event for a purchase to deduct stock from inventory
	 *
	 * @author Jonathan Davis
	 * @since 1.2.1
	 *
	 * @return void
	 **/
	function unstock ( AuthedOrderEvent $Event ) {
		if ( ! shopp_setting_enabled('inventory') ) return false;

		$Purchase = ShoppPurchase();
		if (!isset($Purchase->id) || empty($Purchase->id) || $Event->order != $Purchase->id)
			$Purchase = new ShoppPurchase($Event->order);

		if ( ! isset($Purchase->events) || empty($Purchase->events) ) $Purchase->load_events(); // Load purchased
		if ( in_array('unstock', array_keys($Purchase->events)) ) return true; // Unstock already occurred, do nothing
		if ( empty($Purchase->purchased) ) $Purchase->load_purchased();
		if ( ! $Purchase->stocked ) return false;

		shopp_add_order_event($Purchase->id,'unstock');
	}

	/**
	 * Marks an order as captured
	 *
	 * @author Jonathan Davis
	 * @since 1.2
	 *
	 * @return void
	 **/
	public function captured ( $Event ) {

		if ( 'authed' == $Event->name ) {
			if ( ! isset($Event->capture) ) return;
			if ( ! $Event->capture ) return;
			$Event->fees = 0;
		}

		shopp_add_order_event($Event->order, 'captured', array(
			'txnid' => $Event->txnid,				// Can be either the original transaction ID or an ID for this transaction
			'amount' => $Event->amount,				// Capture of entire order amount
			'fees' => $Event->fees,					// Transaction fees taken by the gateway net revenue = amount-fees
			'gateway' => $Event->gateway			// Gateway class name
		));

	}

	/**
	 * Order processing decides the type of transaction processing request to make
	 *
	 * Decides which processing operation to perform:
	 * Authorization - Get authorization to charge the order amount with the payment processor
	 * Sale - Get authorization and immediate capture (charge) of the payment
	 *
	 * @author Jonathan Davis
	 * @since 1.2
	 * @param $Purchase
	 * @return void
	 */
	public function process ( $Purchase ) {

		$processing = 'sale'; 							// By default, process as a sale event

		if ( $this->Cart->shipped() ) {					// If there are shipped items
			$processing = 'auth';						// Use authorize payment processing don't charge

			if ( shopp_setting_enabled('inventory') )	// If inventory tracking enabled, set items to unstock after successful authed event
				add_action('shopp_authed_order_event', array($this, 'unstock'));

		}
		$default = array($this, $processing);

		// Gateway modules can use 'shopp_purchase_order_gatewaymodule_processing' filter hook to override order processing
		// Return a string of 'auth' for auth processing, or 'sale' for sale processing
		// For advanced overrides, gateways can provide custom callbacks as a standard PHP object callback array: array($this,'customhandler')
		if ( ! empty($Purchase->gateway) ) {
			$processing = apply_filters('shopp_purchase_order_' . sanitize_key($Purchase->gateway) . '_processing', $processing, $Purchase);
			$processing = apply_filters('shopp_purchase_order_' . GatewayModules::hookname($Purchase->gateway) . '_processing', $processing, $Purchase);
		}

		// General order processing filter override
		$processing = apply_filters('shopp_purchase_order_processing', $processing, $Purchase);

		if ( is_string($processing) ) $callback = array($this, $processing);
		elseif ( is_array($processing) ) $callback = $processing;

		if ( ! is_callable($callback) ) $callback = $default;

		call_user_func($callback, $Purchase);

	}

	/**
	 * Sets up order events for Auth-only transaction processing
	 *
	 * @author Jonathan Davis
	 * @since 1.2
	 *
	 * @return void
	 **/
	public function auth ( $Purchase ) {

		add_action('shopp_authed_order_event', array($this, 'notify'));
		add_action('shopp_authed_order_event', array($this, 'accounts'));
		add_action('shopp_authed_order_event', array($this, 'success'));

		shopp_add_order_event($Purchase->id,'auth',array(
			'gateway' => $Purchase->gateway,
			'amount' => $Purchase->total
		));

	}

	/**
	 * Sets up order events for Auth-Capture "Sale" transaction processing
	 *
	 * @author Jonathan Davis
	 * @since 1.2
	 *
	 * @return void
	 **/
	public function sale ( $Purchase ) {

		add_action('shopp_captured_order_event', array($this, 'notify'));
		add_action('shopp_captured_order_event', array($this, 'accounts'));
		add_action('shopp_captured_order_event', array($this, 'success'));

		shopp_add_order_event($Purchase->id,'sale',array(
			'gateway' => $Purchase->gateway,
			'amount' => $Purchase->total
		));

	}

	/**
	 * Handles processing free orders, overriding any configured gateways
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	public function freebie () {

		$this->Payments->processor('ShoppFreeOrder');
		$this->Billing->cardtype = __('Free Order','Shopp');

		return true;
	}

	/**
	 * Converts a shopping session order to a Purchase record
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	public function purchase ( PurchaseOrderEvent $Event ) {
		$Shopping = ShoppShopping();
		$changed = $this->changed();

		// No auth message, bail
		if ( empty($Event) ) {
			shopp_debug('Order failure: An empty order event message was received by the order processor.');
			return;
		}

		// Copy details from Auth message
		$this->txnstatus = $Event->name;
		$this->gateway = $Event->gateway;

		$paycard = Lookup::paycard($this->Billing->cardtype);
		$this->Billing->cardtype = ! $paycard ? $this->Billing->cardtype : $paycard->name;

		$promos = array();
		foreach ( $this->Discounts as $Discount )
			$promos[ $Discount->id() ] = $Discount->name();

		if ( empty($this->inprogress) ) {
			// Create a new order
			$Purchase = new ShoppPurchase();
		} elseif ( empty(ShoppPurchase()->id) ) {
			// Handle updates to an existing order from checkout reprocessing
			$Purchase = new ShoppPurchase($this->inprogress);
		} else {
			// Update existing order
			$Purchase = ShoppPurchase();
		}

		// Capture early event transaction IDs
		if ( isset($Event->txnid) ) $Purchase->txnid = $this->txnid = $Event->txnid;

		$Purchase->order = 0;
		$Purchase->copydata($this);
		$Purchase->copydata($this->Customer);
		$Purchase->copydata($this->Billing);
		$Purchase->copydata($this->Shipping, 'ship');
		$Purchase->copydata($this->Cart->Totals->data());
		$Purchase->subtotal = $Purchase->order; // Remap order to subtotal
		$Purchase->customer = $this->Customer->id;
		$Purchase->taxing = shopp_setting_enabled('tax_inclusive') ? 'inclusive' : 'exclusive';
		$Purchase->promos = $promos;
		$Purchase->freight = $this->Cart->Totals->total('shipping');
		$Purchase->ip = $Shopping->ip;
		$Purchase->created = current_time('mysql');
		unset($Purchase->order);
		$Purchase->save();

		// Catch Purchase record save errors
		if ( empty($Purchase->id) ) {
			shopp_add_error( Shopp::__('The order could not be created because of a technical problem on the server. Please try again, or contact the website adminstrator.') );
			return;
		}

		ShoppPromo::used(array_keys($promos));
		ShoppPurchase($Purchase);

		// Process the order events if updating an existing order
		if ( ! empty($this->inprogress) ) {

			if ( $changed ) { // The order has changed since the last order attempt

				// Rebuild purchased records from cart items
				$Purchase->delete_purchased();

				remove_action( 'shopp_order_event', array($Purchase, 'notifications') );

				// Void prior invoiced balance
				shopp_add_order_event($Purchase->id, 'voided', array(
					'txnorigin' => '', 'txnid' => '',
					'gateway' => $Purchase->gateway
				));

				// Recreate purchased records from the cart and re-invoice for the new order total
				$this->items($Purchase->id);
				$this->invoice($Purchase);

				add_action( 'shopp_order_event', array($Purchase, 'notifications') );
			} elseif ( 'voided' == $Purchase->txnstatus )
				$this->invoice($Purchase); // Re-invoice cancelled orders that are still in-progress @see #1930

			$this->process($Purchase);
			return;
		}

		$this->items($Purchase->id);		// Create purchased records from the cart items

		$this->purchase = false; 			// Clear last purchase in prep for new purchase
		$this->inprogress = $Purchase->id;	// Keep track of the purchase record in progress for transaction updates

		shopp_debug('Purchase '.$Purchase->id.' was successfully saved to the database.');

		// Start the transaction processing events
		do_action('shopp_purchase_order_created',$Purchase);

	}

	/**
	 * Builds purchased records from cart items attached to the given Purchase ID
	 *
	 * @author Jonathan Davis
	 * @since 1.2.2
	 *
	 * @param int $purchaseid The Purchase id to attach the purchased records to
	 * @return void
	 **/
	public function items ( $purchaseid ) {
		foreach( $this->Cart as $Item ) {	// Build purchased records from cart items
			$Purchased = new ShoppPurchased();
			$Purchased->purchase = $purchaseid;
			$Purchased->copydata($Item);
			$Purchased->save();
		}
		$this->checksum = $this->Cart->checksum;	// Track the cart contents checksum to detect changes.
	}

	/**
	 * Detect changes to the order (in the cart)
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @return boolean True if the order changed from the last time
	 **/
	public function changed () {
		$before = $this->state;
		$this->state = $this->Cart->state( $before );
		return ( $before != $this->state );
	}

	/**
	 * Creates a customer record (and WordPress user) and attaches the order to it
	 *
	 * @author Jonathan Davis
	 * @since 1.2
	 *
	 * @return void
	 **/
	public function accounts ($Event) {

		$this->Checkout->registration();

		// Update Purchase with link to created customer record
		if ( ! empty($this->Customer->id) ) {
			$Purchase = ShoppPurchase();

			if ($Purchase->id != $Event->order)
				$Purchase = new ShoppPurchase($Event->order);

			$Purchase->customer = $this->Customer->id;
			$Purchase->billing = $this->Billing->id;
			$Purchase->shipping = $this->Shipping->id;
			$Purchase->save();
		}

	}

	/**
	 * Recalculates sales stats for products
	 *
	 * Updates the sales stats for products affected by purchase transaction status changes.
	 *
	 * @author Jonathan Davis
	 * @since 1.2
	 *
	 * @param string $status New transaction status being set
	 * @param ShoppPurchase $Purchase The affected Purchase object
	 * @return void
	 **/
	// public function salestats ($status, &$Purchase) {
	// 	if (empty($Purchase->id)) return;
	//
	// 	$products = ShoppDatabaseObject::tablename(ShoppProduct::$table);
	// 	$purchased = ShoppDatabaseObject::tablename(ShoppPurchased::$table);
	//
	// 	// Transaction status changed
	// 	if ('CHARGED' == $status) // Now CHARGED, add quantity ordered to product 'sold' stat
	// 		$query = "UPDATE $products AS p LEFT JOIN $purchased AS s ON p.id=s.product SET p.sold=p.sold+s.quantity WHERE s.purchase=$Purchase->id";
	// 	elseif ($Purchase->txnstatus == 'CHARGED') // Changed from CHARGED, remove quantity ordered from product 'sold' stat
	// 		$query = "UPDATE $products AS p LEFT JOIN $purchased AS s ON p.id=s.product SET p.sold=p.sold-s.quantity WHERE s.purchase=$Purchase->id";
	//
	// 	sDB::query($query);
	//
	// }

	/**
	 * Send out new order notifications
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	public function notify ( $Event ) {

		$Purchase = $Event->order();
		if ( ! $Purchase ) return;

		do_action('shopp_order_notifications', $Purchase);

	}

	/**
	 * Resets the session and redirects to the thank you page
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	public function success () {

		$this->purchase = $this->inprogress;
		$this->inprogress = false;

		do_action('shopp_order_success', ShoppPurchase());

		Shopping::resession();

		if ( false !== $this->purchase )
			shopp_redirect( Shopp::url(false, 'thanks') );

	}

	/**
	 * Validates an order prior to submitting for payment processing
	 *
	 * @author Jonathan Davis
	 * @since 1.2
	 *
	 * @return boolean True if the order is ready for processing, or void with redirect back to checkout
	 **/
	public function validate () {
		if ( apply_filters('shopp_valid_order', $this->isvalid()) ) return true;
		shopp_redirect( Shopp::url(false, 'checkout', $this->security()), true );
	}

	/**
	 * Validate order data before transaction processing
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return boolean Validity of the order
	 **/
	public function isvalid ( $report = true ) {

		$Customer = $this->Customer;
		$Shipping = $this->Shipping;
		$Shiprates = $this->Shiprates;
		$Cart = $this->Cart;

		$valid = true;
		$errlevel = $report ? SHOPP_TRXN_ERR : SHOPP_DEBUG_ERR;

		shopp_debug('Validating order data for processing');

		if ( 0 == $Cart->count() ) {
			$valid = apply_filters('shopp_ordering_empty_cart',false);
			shopp_add_error(__('There are no items in the cart.', 'Shopp'), $errlevel);
		}

		$stock = true;
		foreach ( $Cart as $item ) {
			if ( ! $item->instock() ){
				$valid = apply_filters('shopp_ordering_items_outofstock',false);
				shopp_add_error( sprintf(__('%s does not have sufficient stock to process order.', 'Shopp'),
					$item->name . ( empty($item->option->label) ? '' : '(' . $item->option->label . ')' )
				), $errlevel);
				$stock = false;
			}
		}

		$valid_customer = true;
		if ( ! $Customer ) $valid_customer = apply_filters('shopp_ordering_empty_customer', false); // No Customer

		// Always require name and email
		if ( empty($Customer->firstname) ) $valid_customer = apply_filters('shopp_ordering_empty_firstname', false);
		if ( empty($Customer->lastname) ) $valid_customer = apply_filters('shopp_ordering_empty_lastname', false);
		if ( empty($Customer->email) ) $valid_customer = apply_filters('shopp_ordering_empty_email', false);

		if ( ! $valid_customer ) {
			$valid = false;
			shopp_add_error(__('There is not enough customer information to process the order.','Shopp'), $errlevel);
		}

		// Check for shipped items but no Shipping information
		$valid_shipping = true;
		if ( $Cart->shipped() && shopp_setting_enabled('shipping') ) {
			if ( empty($Shipping->address) )
				$valid_shipping = apply_filters('shopp_ordering_empty_shipping_address', false);
			if ( empty($Shipping->country) )
				$valid_shipping = apply_filters('shopp_ordering_empty_shipping_country', false);
			if ( empty($Shipping->postcode) )
				$valid_shipping = apply_filters('shopp_ordering_empty_shipping_postcode', false);

			if ( 0 === $Shiprates->count() && ! $Shiprates->free() ) {
				$valid = apply_filters('shopp_ordering_no_shipping_costs',false);

				$message = __('The order cannot be processed. No shipping is available to the address you provided. Please return to %scheckout%s and try again.', 'Shopp');

				if ( $Shiprates->realtime() )
					$message = __('The order cannot be processed. The shipping rate service did not provide rates because of a problem and no other shipping is available to the address you provided. Please return to %scheckout%s and try again or contact the store administrator.', 'Shopp');

				if ( ! $valid ) shopp_add_error( sprintf($message, '<a href="'.Shopp::url(false,'checkout',$this->security()).'">', '</a>'), $errlevel );
			}

		}

		if ( ! $valid_shipping ) {
			$valid = false;
			shopp_add_error(__('The shipping address information is incomplete. The order cannot be processed.','Shopp'), $errlevel);
		}

		return $valid;
	}

	/**
	 * Evaluates if checkout process needs to be secured
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return boolean Whether the checkout form should be secured
	 **/
	public function security () {
		return $this->Payments->secure() || is_ssl();
	}

	/**
	 * Secures the payment card by truncating it to the last four digits
	 *
	 * @author Jonathan Davis
	 * @since 1.2
	 *
	 * @return void
	 **/
	public function securecard () {
		if ( ! empty($this->Billing->card) && strlen($this->Billing->card) > 4 ) {
			$this->Billing->card = substr($this->Billing->card, -4);

			// Card data is truncated, switch the cart to normal mode
			ShoppShopping()->secured(false);
		}
	}

	public function paymethod () {
		return $this->Payments->selected();
	}

	/**
	 * Clear order-specific information to prepare for a new order
	 *
	 * @author Jonathan Davis
	 * @since 1.1.5
	 *
	 * @return void
	 **/
	public function clear () {

		$this->Cart->clear();
		$this->Discounts->clear();
		$this->Promotions->clear();
		// $this->Shiprates->clear();

		$this->data = array();
		$this->inprogress = false;
		$this->txnid = false;

	}

}