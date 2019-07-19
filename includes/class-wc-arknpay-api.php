<?php
/*
ArknPay
Copyright (C) 2017-2018 Milan Semen

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/
//////////////////////////////////////////////////////////////////////////////////////////
// START OF ARKCOMMERCE UTILITIES														//
//////////////////////////////////////////////////////////////////////////////////////////
// Prohibit direct access
if( !defined( 'ABSPATH' ) ) exit;

class WP_ArknPay_API {
    const MAINNET_EXPLORER      = 'https://explorer.ark.io/tx/';
    const TESTNET_EXPLORER      = 'https://dexplorer.ark.io/tx/';
    
    public $arkgatewaysettings;
    private $api = null;

    /* public function get_transaction_url( $order ) {
		if ( $this->testmode ) {
			$this->view_transaction_url = 'https://dashboard.stripe.com/test/payments/%s';
		} else {
			$this->view_transaction_url = 'https://dashboard.stripe.com/payments/%s';
		}

		return parent::get_transaction_url( $order );
    } */

    public function constructor() {
        $this->arkgatewaysettings = get_option( 'woocommerce_ark_gateway_settings' );
    }

    public function request($endpoint) {
        $arknodetxresponse = wp_remote_get( $ark_txquery );
	
        // API response
        if( !is_wp_error($arknodetxresponse) ) {
            $arktxresponse = json_decode( $arknodetxresponse['body'], true );

            // Validate response
            
            if ( count($arktxresponse['data']) > 0 ) {
                $arktxblockheight = arknpay_get_block_height($arktxresponse['data']['blockId']);
            }
            //if( $arktxresponse['success'] === true ) $arktxblockheight = $arktxresponse['transaction']['height'];
        } 
    }
}

//////////////////////////////////////////////////////////////////////////////////////////
// Get ARK Blockchain Current Block Height												//
// @return int $arklastblock															//
// @record int arkservice																//
//////////////////////////////////////////////////////////////////////////////////////////
function arknpay_get_block_height($block_id = null) {
	// Gather and/or set variables
	$arkgatewaysettings = get_option( 'woocommerce_ark_gateway_settings' );
	
	// Encryption flag and URI prefix
	if( $arkgatewaysettings['nodeencryption'] = 'yes' ) $arknodeprefix = 'https://';
	else $arknodeprefix = 'http://';
	
	// DARK Mode settings
	if( $arkgatewaysettings['darkmode'] == 'yes' ) $arknode = ( $arknodeprefix . $arkgatewaysettings['darknode'] );
	else $arknode = ( $arknodeprefix . $arkgatewaysettings['arknode'] );
	
	// Construct a block height query URI for ARK/DARK Node
	if (is_null($block_id)) {
        $ark_blockquery = "{$arknode}/api/v2/blocks?limit=1";
    } else {
        $ark_blockquery = "{$arknode}/api/v2/blocks/{$block_id}";
    }
	// Query ARK/DARK Node API for current block height
	$arkblockresponse = wp_remote_get( $ark_blockquery );
    
	// API response
	if( !is_wp_error($arkblockresponse) ) {
        $arkblockheight = json_decode( $arkblockresponse['body'], true );

		// Validate response
		if( !empty($arkblockheight['data'][0]['height']) )
		{
			// Get current block height
			$arklastblock = $arkblockheight['data'][0]['height'];
            $arkgatewaysettings['arkservice'] = 0;
		}
		else
		{
			// Response invalid
			$arkgatewaysettings['arkservice'] = 1;
			$arklastblock = 0;
		}
	}
	else
	{
		// Node unreachable
		$arkgatewaysettings['arkservice'] = 1;
		$arklastblock = 0;
	}
	// Record ARK/DARK Node status
	update_option( 'woocommerce_ark_gateway_settings', $arkgatewaysettings );
	
	// Return ARK blockchain last block
	return $arklastblock;
}

//////////////////////////////////////////////////////////////////////////////////////////
// Function Checking for Order Payment Fulfillment, Handling and Notification			//
// @param int $order_id																	//
// @record int arkservice																//
//////////////////////////////////////////////////////////////////////////////////////////
function arkcommerce_ark_transaction_validation( $order_id ) 
{
	// Gather and/or set variables
	$arkgatewaysettings = get_option( 'woocommerce_ark_gateway_settings' );
	$ark_transaction_found = false;
	$order = wc_get_order( $order_id );
	$ark_order_data = $order->get_data();
	$ark_order_id = $ark_order_data['id'];
	$arkblockheight = arknpay_get_block_height();
	$ark_arktoshi_total = $order->get_meta( $key = 'ark_arktoshi_total' );
	$arkorderblock = $order->get_meta( $key = 'ark_order_block' );
	$arkorderexpiryblock = $order->get_meta( $key = 'ark_expiration_block' );
	$storewalletaddress = $order->get_meta( $key = 'ark_store_wallet_address' );
	
	// Encryption flag and URI prefix
	if( $arkgatewaysettings['nodeencryption'] = 'yes' ) $arknodeprefix = 'https://';
	else $arknodeprefix = 'http://';
	
	// DARK Mode settings
	if( $arkgatewaysettings['darkmode'] == 'yes' ) 
	{
		$arknode = ( $arknodeprefix . $arkgatewaysettings['darknode'] );
		$explorerurl = 'https://dexplorer.ark.io/tx/';
	}
	else
	{
		$arknode = ( $arknodeprefix . $arkgatewaysettings['arknode'] );
		$explorerurl = 'https://explorer.ark.io/tx/';
	}
	// Validate the order is on hold and the chosen payment method is ArknPay
	if( $order->has_status( 'on-hold' ) && 'ark_gateway' === $order->get_payment_method() ) 
	{
		// Fetch last 50 transactions into store wallet
		$arktxrecordarray = arknpay_fetch_transactions( 50 );
		
		if( is_array( $arktxrecordarray ) && $arkblockheight !=0 ) 
		{
			// ARK/DARK node responsive
			$arkgatewaysettings['arkservice'] = 0;
			
			// Process each result in array to establish the correct transaction filling this particular order
			foreach( $arktxrecordarray as $arktxrecord ):setup_postdata( $arktxrecord );
			
				// Validate TX as correct payment tx for the order
				if( $arktxrecord['vendorField'] == $ark_order_id && intval( $arktxrecord['amount'] ) == intval( $ark_arktoshi_total ) ) 
				{
					// Extract the potential transaction ID
					$arktxrecordtxid = $arktxrecord['id'];
					
					// Construct query for the specific potential transaction URI for ARK/DARK Node
					$ark_txquery = "$arknode/api/v2/transactions/$arktxrecordtxid";
					
					// Query ARK/DARK Node API for the specific transaction
					$arknodetxresponse = wp_remote_get( $ark_txquery );
	
					// API response
					if( !is_wp_error($arknodetxresponse) ) 
					{
						$arktxresponse = json_decode( $arknodetxresponse['body'], true );
		
                        // Validate response
                        
                        if ( count($arktxresponse['data']) > 0 ) {
                            $arktxblockheight = arknpay_get_block_height($arktxresponse['data']['blockId']);
                        }
						//if( $arktxresponse['success'] === true ) $arktxblockheight = $arktxresponse['transaction']['height'];
					}
					// Validate found TX block height is higher or equal to blockchain block height at the time order was made and the order has not expired at that time
					if( $arkorderexpiryblock != 'never' && intval( $arktxblockheight ) <= intval( $arkorderexpiryblock ) && intval( $arktxblockheight ) >= intval( $arkorderblock ) ) 
					{
						// Correct payment TX found
						$ark_transaction_found = true;
						$ark_transaction_identifier = $arktxrecordtxid;
						$ark_transaction_block = $arktxblockheight;
					}
					// Alternatively, validate found TX block height is higher or equal to blockchain block height at the time order was made
					elseif( $arkorderexpiryblock == 'never' && intval( $arktxblockheight ) >= intval( $arkorderblock ) ) 
					{
						// Correct payment TX found
						$ark_transaction_found = true;
						$ark_transaction_identifier = $arktxrecordtxid;
						$ark_transaction_block = $arktxblockheight;
					}
				}
			endforeach;
			
			// Payment TX found and it occurred after the order had been committed
			if( $ark_transaction_found === true ) 
			{
				// Mark as complete (the payment has been made), add transaction id plus payment block number to order metadata
				$ark_order_completed_note = ( __( 'ARK order filled at block:', 'arkcommerce' ) . ' ' . $ark_transaction_block . ' TX: <a href="' . $explorerurl . $ark_transaction_identifier . '">' . $ark_transaction_identifier . '</a>.' );
				$order->update_meta_data( 'ark_transaction_id', $ark_transaction_identifier );
				$order->update_meta_data( 'ark_payment_block', $ark_transaction_block );
				$order->save();
				$order->update_status( 'completed', $ark_order_completed_note );
				
				// Notify admin if enabled
				if( $arkgatewaysettings['arkorderfillednotify'] == 'on' ) arkcommerce_admin_notification( $order_id, 'orderfilled' );
			}
			// No payment TX for the order number found at this time
			elseif( $arkorderexpiryblock != 'never' && $ark_transaction_found === false && intval( $arkblockheight ) <= intval( $arkorderexpiryblock ) ) 
			{
				// Make note of unsuccessful check (the payment has not yet been made)
				$order->add_order_note( ( __( 'ARK transaction check yields no matches. Current block height', 'arkcommerce' ) . ': ' . $arkblockheight . '.' ), false, true );
			}
			// No payment TX for the order number found at this time (for orders that do not expire)
			elseif( $arkorderexpiryblock == 'never' && $ark_transaction_found === false ) 
			{
				// Make note of unsuccessful check (the payment has not yet been made)
				$order->add_order_note( ( __( 'ARK transaction check yields no matches. Current block height', 'arkcommerce' ) . ': ' . $arkblockheight . '.' ), false, true );
			}
			// The order has expired - the timeout since order commit exceeds set limit
			elseif( $arkorderexpiryblock != 'never' && $ark_transaction_found === false && intval( $arkblockheight ) > intval( $arkorderexpiryblock ) ) 
			{
				// Make note of order timeout reached (the payment has not been made within the set window) and mark as cancelled
				$ark_order_cancelled_note = ( __( 'ARK order expired at block height:', 'arkcommerce' ) . ' ' . $arkorderexpiryblock . '. ' . __( 'Current block height', 'arkcommerce' ) . ': ' . $arkblockheight . '.' );
				$order->update_status( 'cancelled', $ark_order_cancelled_note );
				
				// Notify admin if enabled
				if( $arkgatewaysettings['arkorderexpirednotify'] == 'on' ) arkcommerce_admin_notification( $order_id, 'orderexpired' );
			}
		}
		// ARK/DARK API response invalid or node unreachable
		else 
		{
			// Make note of unsuccessful check (the payment has not yet been made)
			$order->add_order_note( __( 'ARK network unresponsive or unreachable.', 'arkcommerce' ), false, true );
			$arkgatewaysettings['arkservice'] = 1;
		}
		// Record ARK/DARK Node status
		update_option( 'woocommerce_ark_gateway_settings', $arkgatewaysettings );
	}
}
//////////////////////////////////////////////////////////////////////////////////////////
// Update Currency Market Exchange Rate Between Chosen Store Fiat and ARK Pairs 		//
// @record float arkexchangerate														//
//////////////////////////////////////////////////////////////////////////////////////////
function arkcommerce_update_exchange_rate() 
{
	// Gather and/or set variables
	$arkgatewaysettings = get_option( 'woocommerce_ark_gateway_settings' );
	$store_currency = get_woocommerce_currency();
	
	// Check for supported currency
	$currency_supported = arkcommerce_check_currency_support();
	
	// Currency supported
	if( $currency_supported === true ) 
	{
		// Check if ARK already chosen as main currency
		if( $store_currency == 'ARK' ) $arkexchangerate = 1;
		
		// Query coinmarketcap.com APIv2
		else 
		{
			// Construct a query URI for Coinmarketcap v2 API; expected number of results: 1
			$cmc_query = "https://api.coinmarketcap.com/v2/ticker/1586/?convert=$store_currency";
			
			// Query CoinMarketCap API for ARK market price in supported chosen currency
			$cmcresponse = wp_remote_get( $cmc_query );
			
			// CMC API response validation
			if( is_array( $cmcresponse) ) 
			{
				$arkmarketprice = json_decode( $cmcresponse['body'], true );
				
				// Construct a suitable key identifier for in-array lookup
				$chosen_currency_var = strtoupper( $store_currency );
				
				// Determine the exchange rate
				$arkexchangerate = $arkmarketprice[data][quotes][$chosen_currency_var][price];
			}
		}
	}
	// Currency not supported
	else $arkexchangerate = 0;
	
	// Update the gateway settings containing the variable 'arkexchangerate'
	$arkgatewaysettings['arkexchangerate'] = $arkexchangerate;
	update_option( 'woocommerce_ark_gateway_settings', $arkgatewaysettings );
}
add_action ( 'arkcommerce_refresh_exchange_rate', 'arkcommerce_update_exchange_rate' );

function arkcommerce_get_wallet_balance()
{
	// Gather and/or set variables
	$arkgatewaysettings = get_option( 'woocommerce_ark_gateway_settings' );
	
	// Encryption flag and URI prefix
	if( $arkgatewaysettings['nodeencryption'] = 'yes' ) $arknodeprefix = 'https://';
	else $arknodeprefix = 'http://';
	
	// DARK Mode settings
	if( $arkgatewaysettings['darkmode'] == 'yes' )
	{
		$storewalletaddress = $arkgatewaysettings['darkaddress'];
		$arknode = ( $arknodeprefix . $arkgatewaysettings['darknode'] );
	}
	else
	{
		$storewalletaddress = $arkgatewaysettings['arkaddress'];
		$arknode = ( $arknodeprefix . $arkgatewaysettings['arknode'] );
	}
	// Construct a store ARK wallet address balance query URI for ARK/DARK Node
	$ark_balancequery = "$arknode/api/v2/wallets/{$storewalletaddress}";
	
	// Query ARK/DARK Node API for store ARK wallet address balance
	$arkbalanceresponse = wp_remote_get( $ark_balancequery );
	
	// API response
	if( !is_wp_error($arkbalanceresponse) )
	{
		$arkbaresponse = json_decode( $arkbalanceresponse['body'], true );
		
        // Validate response
		if( count($arkbaresponse['data']) > 0 )
		{
			// Get wallet balance
			$result = number_format( ( float ) $arkbaresponse['data']['balance'] / 100000000, 8, '.', '' );
			$arkgatewaysettings['arkservice'] = 0;
		}
		else
		{
			// Response invalid
			$arkgatewaysettings['arkservice'] = 1;
			$result = 0;
		}
	}
	else
	{
		// Node unreachable
		$arkgatewaysettings['arkservice'] = 1;
		$result = 0;
	}
	// Record ARK/DARK Node status
	update_option( 'woocommerce_ark_gateway_settings', $arkgatewaysettings );

	// Return result
	return $result;
}

/*
* ArknPay Fetch Last x Incoming Transactions	
* 
* @param int $limit
* @return arr $transactions
*/
function arknpay_fetch_transactions( $limit = 10 ) {
	// Gather and/or set variables
    $arkgatewaysettings = get_option( 'woocommerce_ark_gateway_settings' );
    $transactions = array();
	
	// Encryption flag and URI prefix
	if( $arkgatewaysettings['nodeencryption'] = 'yes' ) $arknodeprefix = 'https://';
	else $arknodeprefix = 'http://';
	
	// DARK Mode settings
	if( $arkgatewaysettings['darkmode'] == 'yes' ) {
		$storewalletaddress = $arkgatewaysettings['darkaddress'];
		$arknode = ( $arknodeprefix . $arkgatewaysettings['darknode'] );
	} else {
		$storewalletaddress = $arkgatewaysettings['arkaddress'];
		$arknode = ( $arknodeprefix . $arkgatewaysettings['arknode'] );
    }
    
    /**/
    
    $ark_txquery = "$arknode/api/transactions/search?limit=$limit";
    // Query ARK/DARK Node API for last x store ARK wallet address transactions

    $arkbridgeresponsetx = wp_remote_post( $ark_txquery, array(
        'method' => 'POST',
        'headers'  => array(
            'Content-Type: application/json'
        ),
        'body' => array(
            'recipientId' => $storewalletaddress
        )
    ) );

    // API response
    if( !is_wp_error($arkbridgeresponsetx) )  {
        $arktxresponse = json_decode( $arkbridgeresponsetx['body'], true );

        if( count($arktxresponse['data']) > 0 ) {
            $transactions = $arktxresponse['data'];
        }
    }

	return $transactions;
}