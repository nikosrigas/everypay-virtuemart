<?php

defined ('_JEXEC') or die('Restricted access');
if (!class_exists ('vmPSPlugin')) {
	require(VMPATH_PLUGINLIBS . DS . 'vmpsplugin.php');
}

require_once __DIR__ . DS . 'autoload.php';

class plgVmpaymentEverypay extends vmPSPlugin
{
	public static $_cc_name           = '';
	public static $_cc_type           = '';
	public static $_cc_number         = '';
	public static $_cc_cvv            = '';
	public static $_cc_expire_month   = '';
	public static $_cc_expire_year    = '';
	public static $_cc_valid          = false;
	private $_errormessage      = array();
	static $iframeLoaded = false;

    private $paymentMethodId;

    public function __construct(& $subject, $config)
    {
        parent::__construct($subject, $config);

        $jlang = JFactory::getLanguage ();
		$jlang->load('plg_vmpayment_everypay', JPATH_ADMINISTRATOR, NULL, true);
		$this->_loggable = true;
		$this->_debug = true;
		$this->tableFields = array_keys ($this->getTableSQLFields ());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';

		$varsToPush = array(
            'sandbox' => array('0', 'int'),
            'sandbox_secret_key' => array('', 'char'),
            'secret_key' => array('', 'char'),
            'sandbox_public_key' => array('', 'char'),
            'public_key'  => array('', 'char'),
            'pay_to_email' => array('', 'char'),
            'product' => array('', 'char'),
            'hide_login' => array(0, 'int'),
            'logourl' => array('', 'char'),
            'secret_word' => array('', 'char'),
            'payment_currency' => array('', 'char'),
            'payment_logos' => array('', 'char'),
            'countries' => array('', 'char'),
            'cost_per_transaction' => array('', 'int'),
            'cost_percent_total' => array('', 'int'),
            'min_amount' => array('', 'int'),
            'max_amount' => array('', 'int'),
            'tax_id' => array(0, 'int'),
            'status_pending' => array('', 'char'),
            'status_success' => array('', 'char'),
            'status_canceled' => array('', 'char')
        );

		$this->setConfigParameterable ($this->_configTableFieldName, $varsToPush);
    }

    protected function getVmPluginCreateTableSQL()
    {
		return $this->createTableSQL('Payment Everypay Table');
    }

    public function getTableSQLFields()
    {
		$SQLfields = array(
			'id'                            => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'           => 'int(1) UNSIGNED',
			'order_number'                  => 'char(64)',
			'virtuemart_paymentmethod_id'   => 'mediumint(1) UNSIGNED',
			'payment_name'                  => 'varchar(5000)',
			'payment_order_total'           => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
			'payment_currency'              => 'char(3)',
			'cost_per_transaction'          => 'decimal(10,2)',
			'cost_percent_total'            => 'decimal(10,2)',
            'tax_id'                        => 'smallint(1)',
            'user_session'            => 'varchar(255)',
			'everypay_response_token'       => 'char(128)',
			'everypay_response_description' => 'char(255)',
			'everypay_response_status'      => 'char(128)',
			'everypay_response_card_type'   => 'char(10)',
			'everypay_response_last_four'   => 'char(4)',
			'everypay_response_holder_name' => 'char(255)',
		);

		return $SQLfields;
	}

    function _getInternalData ($virtuemart_order_id, $order_number = '') {

        $db = JFactory::getDBO ();
        $q = 'SELECT * FROM `' . $this->_tablename . '` WHERE ';
        if ($order_number) {
            $q .= " `order_number` = '" . $order_number . "'";
        } else {
            $q .= ' `virtuemart_order_id` = ' . $virtuemart_order_id;
        }

        $db->setQuery ($q);
        if (!($paymentTable = $db->loadObject ())) {
            // JError::raiseWarning(500, $db->getErrorMsg());
            return '';
        }

        return $paymentTable;
    }

    function plgVmConfirmedOrder(VirtueMartCart $cart, $order)
    {
        if (!($method = $this->getVmPluginMethod ($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return null;
		} // Another method was selected, do nothing

		if (!$this->selectedThisElement ($method->payment_element)) {
			return false;
		}

		$new_status = '';
        $session = JFactory::getSession ();
		$return_context = $session->getId ();
        $this->logInfo ('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');
        
        if (!class_exists ('VirtueMartModelOrders')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
		}
		if (!class_exists ('VirtueMartModelCurrency')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'currency.php');
        }

        $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total,$method->payment_currency);

        // Prepare data that should be stored in the database
        $dbValues['user_session'] = $return_context;
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['payment_name'] = $this->renderPluginName ($method, $order);
		$dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
		$dbValues['cost_per_transaction'] = $method->cost_per_transaction;
		$dbValues['cost_percent_total'] = $method->cost_percent_total;
		$dbValues['payment_currency'] = $method->payment_currency;
		$dbValues['payment_order_total'] = $totalInPaymentCurrency['value'];
		$dbValues['tax_id'] = $method->tax_id;

        if ($method->sandbox == '1') {
            Everypay\Everypay::$isTest = true;
        }
        Everypay\Everypay::setApiKey($this->getSecretKey($method));
        $token = $this->getToken();

        $response = Everypay\Payment::create(
                array(
                    'token' => $token,
                    'description' => 'Order #' . $order['details']['BT']->order_number,
                    'amount' => round($cart->cartPrices['billTotal'],2) * 100
                )
        );

        if (isset($response->error)) {
			$this->_handlePaymentCancel($order['details']['BT']->virtuemart_order_id, '' );

			return;
        }

        $new_status = $method->status_success;

        $dbValues['everypay_response_token'] = $response->token;
        $dbValues['everypay_response_description'] = $response->description;
        $dbValues['everypay_response_status'] = $response->status;
        $dbValues['everypay_response_last_four'] = $response->card->last_four;
        $dbValues['everypay_response_holder_name'] = $response->card->holder_name;
        $dbValues['everypay_response_card_type'] = $response->card->type;
        $dbValues['payment_order_total'] = number_format($response->amount / 100, 2);
		$this->storePSPluginInternalData($dbValues);


        $modelOrder = VmModel::getModel ('orders');
        $order['order_status'] = $new_status;
        $order['customer_notified'] = 1;
        $order['comments'] = '';
        $modelOrder->updateStatusForOneOrder ($order['details']['BT']->virtuemart_order_id, $order, TRUE);

        $orderlink='';
        $tracking = VmConfig::get('ordertracking','guests');
        if ($tracking !='none' and !($tracking =='registered' and empty($order['details']['BT']->virtuemart_user_id) )) {

            $orderlink = 'index.php?option=com_virtuemart&view=orders&layout=details&order_number=' . $order['details']['BT']->order_number;
            if ($tracking == 'guestlink' or ($tracking == 'guests' and empty($order['details']['BT']->virtuemart_user_id))) {
                $orderlink .= '&order_pass=' . $order['details']['BT']->order_pass;
            }
        }

        $html = $this->renderByLayout('post_payment', array(
            'order_number' => $order['details']['BT']->order_number,
            'order_pass' =>$order['details']['BT']->order_pass,
            'payment_name' => $dbValues['payment_name'],
            'displayTotalInPaymentCurrency' => $totalInPaymentCurrency['display'],
            'orderlink' => $orderlink
        ));

        //We delete the old stuff
        $cart->emptyCart ();
        vRequest::setVar ('html', $html);


		$session = JFactory::getSession();
		$session->clear('everypay_token', 'vm');

        return TRUE;
    }

    private function hasBillingAddress(VirtueMartCart $cart)
    {
        return is_array($cart->BT) && !empty($cart->BT) && !isset($cart->BT[0]);
    }

    private function displayForm(VirtueMartCart $cart, $isSandbox)
    {
        if ($this->getToken()) {
            return '';
        }

        $amount = (round($cart->cartPrices['billTotal'],2) * 100 );
        $publicKey = $this->getPublicKey();

        $payformUrl = 'https://js.everypay.gr/v3';

        if ($isSandbox == '1') {
            $payformUrl = 'https://sandbox-js.everypay.gr/v3';
        }
        JHtml::_('stylesheet', JUri::base() . '/plugins/vmpayment/everypay/everypay/assets/everypay_modal.css');
        vmJsApi::addJScript ( 'payform', $payformUrl);
        ?>

        <script type="text/javascript">
            window.everypayData = {
                pk: "<?php echo $publicKey ?>",
                amount: <?php echo $amount ?>,
                locale: "el",
                txnType: "tds",
                paymentMethodId: '<?php echo $this->_currentMethod->virtuemart_paymentmethod_id ?? '' ?>'
            }
            window.onload = function () {

                var checkoutForm = jQuery('#checkoutForm');
                var submitButton = jQuery('#checkoutFormSubmit');
                var terms = document.querySelector("input[type='checkbox']#tos");

                if (checkoutForm && submitButton) {

                    window.modal = new EverypayModal();

                    submitButton.on('click', function (e) {
                        if (terms && !terms.checked) {
                            Virtuemart.stopVmLoading();
                            submitButton.removeAttr('disabled');
                            submitButton.addClass('vm-button-correct');

                            return false;
                        }

                        if (isCheckout()) {
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            Virtuemart.stopVmLoading();
                            submitButton.removeAttr('disabled');
                            submitButton.addClass('vm-button-correct')
                            loadPayform();

                            return false;
                        }
                    })

                    checkoutForm.submit(function (e) {
                        if (isCheckout() && window.everypayPurchase != 1) {
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();

                            return false;
                        }
                    });

                }

                function isCheckout() {
                    var data = checkoutForm.serializeArray();

                    var isEverypayChecked = false;
                    var everypayId = everypayData.paymentMethodId;
                    var everypayCheckbox = document.querySelector('input[name="virtuemart_paymentmethod_id"][value="'+everypayId+'"]');
                    if (everypayCheckbox && everypayCheckbox.checked) {
                        isEverypayChecked = true;
                    }

                    return isEverypayChecked;
                }

                function loadPayform()
                {
                    if (typeof window.modal === "undefined" || typeof everypay === "undefined" || typeof everypayData == "undefined") {
                        alert('An error occurred. Please reload the page and try again.');
                        return;
                    }

                    everypay.payform(everypayData, function (response) {

                        if (response && response.response && response.response === 'success') {
                            window.modal.destroy();
                            window.modal.show_loading();

                            setTimeout(function () {
                                window.modal.hide_loading();
                                handleTokenResponse(response.token)
                            }, 3000);

                        }

                        if (response && response.onLoad === true) {
                            window.modal.hide_loading();
                            Virtuemart.stopVmLoading();
                            window.modal.open();
                        }

                    });

                }

                function handleTokenResponse(token) {
                    window.payformRequest = 1;
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'everypayToken';
                    input.value = token;
                    checkoutForm.append(input);
                    window.everypayPurchase = 1;
                    checkoutForm.submit();
                    window.everypayPurchase = 0;
                    modal.show_loading();
                }

                function EverypayModal(settings) {

                    this.locale = false;

                    this.init = function() {
                        try {
                            if (settings && settings.locale) {
                                this.locale = settings.locale;
                            }
                            this.setTexts();
                            this.createHtml();
                            this.setEvents();
                        } catch (e) {
                            console.log(e)
                        }
                    };

                    this.setTexts = function() {
                        if (this.locale && this.locale !== "el") {
                            this.loading_text = 'Processing your order. Please wait...';
                            return;
                        }
                        this.loading_text = 'Επεξεργασία παραγγελίας. Παρακαλούμε περιμένετε...';
                    };

                    this.hide_loading = function() {
                        var loader = document.getElementById('loader-everypay');
                        if (typeof loader == 'undefined' || !loader) {
                            return;
                        }
                        loader.remove();
                    };

                    this.show_loading = function(loading_text) {
                        try {
                            if (!loading_text) {
                                loading_text = this.loading_text;
                            }
                            if (document.getElementById('loader-everypay')) {
                                console.log('everypay-loader has been already loaded.')
                                return;
                            }
                            var everypayLoader = document.createElement('div');
                            everypayLoader.setAttribute('id', 'loader-everypay');
                            everypayLoader.style.cssText = 'position: fixed;height: 100%;width: 100%;background: #f2f2f2;z-index: 100000;top: 0;left: 0;opacity: 0.93;"';
                            var everypayLoaderCenter = document.createElement('center');
                            everypayLoaderCenter.style.cssText = 'width: 100%;position: fixed;clear: both;font-size: 1.3em;top: 40%;margin: 0 auto;';
                            everypayLoaderCenter.innerHTML = '<svg style="max-width: 64px; min-width: 64px; max-height: 64px; min-height: 64px;" id="Layer_1" data-name="Layer 1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 94.1 94.1"><defs><style>.cls-1{fill:url(#linear-gradient);}.cls-2{fill:#21409a;}.cls-3{fill:#39b54a;}</style><linearGradient id="linear-gradient" x1="47.05" y1="-260.26" x2="47.05" y2="-166.16" gradientTransform="matrix(1, 0, 0, -1, 0, -166.16)" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#39b54a"/><stop offset="1" stop-color="#21409a"/></linearGradient></defs><path class="cls-1" d="M94.1,47.05a47.05,47.05,0,1,1-47-47A47,47,0,0,1,94.1,47.05ZM47,8.45A38.69,38.69,0,1,0,85.73,47.14,38.69,38.69,0,0,0,47,8.45Z"><animateTransform attributeType="xml" attributeName="transform" type="rotate" from="0 47.05 47.10" to="360 47.05 47.10" dur="1s" additive="sum" repeatCount="indefinite" /></path><path class="cls-2" d="M66.62,24.83c7.73.84,6.38,8.53,6.38,8.53L66.14,51.3H30l-3.43,9.25C19,59.59,21,52.38,21,52.38L31.38,24.67ZM36.71,33.5l-3.57,9.29H60.65l2.51-6.45a2.52,2.52,0,0,0-1.93-2.84Z"/><path class="cls-3" d="M26.8,61S24.74,68,32.05,69.6H60.68s2.06-7.13-5.25-8.52Z"/></svg><br /><br />';
                            everypayLoaderCenter.innerHTML += loading_text;
                            everypayLoader.appendChild(everypayLoaderCenter);
                            document.body.appendChild(everypayLoader);
                        } catch (e) {}
                    };

                    this.open = function() {
                        if (document.getElementById('everypay-modal')) {
                            document.getElementById('everypay-modal').style.display = 'flex';
                        }
                    };

                    this.setEvents = function() {
                        this.setCloseEvent();
                    };

                    this.setCloseEvent = function() {
                        if (!document.querySelector('#everypay-modal-header span')) {
                            return;
                        }
                        document.querySelector('#everypay-modal-header span')
                            .addEventListener('click', this.close);
                    };

                    this.destroy = function() {
                        if (document.getElementById('everypay-modal')) {
                            document.getElementById('everypay-modal').remove();
                        }
                    };

                    this.close = function() {
                        var close_payment_window_text = 'Are you sure you want to close the payment window?';
                        var closeConfirmation = confirm(close_payment_window_text);

                        if (closeConfirmation && document.getElementById('everypay-modal')) {
                            document.getElementById('everypay-modal').style.display = 'none';
                        }
                    };

                    this.createHtml = function() {
                        var modalDiv = document.createElement('div');
                        modalDiv.setAttribute('id', 'everypay-modal');
                        var modalContentDiv = document.createElement('div');
                        modalContentDiv.setAttribute('id', 'everypay-modal-content');
                        var modalHeader = document.createElement('div');
                        modalHeader.setAttribute('id', 'everypay-modal-header');
                        var modalCloseBtn = document.createElement('span');
                        modalCloseBtn.innerHTML = '&times;';
                        modalHeader.appendChild(modalCloseBtn);
                        var payformDiv = document.createElement('div');
                        payformDiv.setAttribute('id', 'pay-form');
                        modalContentDiv.appendChild(modalHeader);
                        modalContentDiv.appendChild(payformDiv);
                        modalDiv.appendChild(modalContentDiv);
                        modalDiv.appendChild(this.createEverypayLogo());
                        modalDiv.appendChild(this.createSecureLogos());
                        document.body.appendChild(modalDiv);
                    };


                    this.createSecureLogos = function() {
                        var secureLogos = document.createElement('div');
                        secureLogos.setAttribute('id', 'everypay-secure-logos');
                        secureLogos.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" data-name="Layer 1" viewBox="0 0 198 171.13"><path fill="#939598" d="M78.28 27.68L59.64 72.14H47.48l-9.17-35.48c-.56-2.18-1-3-2.73-3.91A48.52 48.52 0 0024.23 29l.27-1.29h19.58a5.36 5.36 0 015.3 4.53l4.85 25.73 12-30.26zm47.63 30C126 45.89 109.69 45.24 109.8 40c0-1.59 1.56-3.29 4.88-3.72a21.55 21.55 0 0111.34 2l2-9.44a31.06 31.06 0 00-10.76-2c-11.37 0-19.37 6-19.43 14.7-.08 6.4 5.71 10 10.06 12.1s6 3.57 6 5.52c0 3-3.58 4.3-6.88 4.35A24 24 0 0195.2 60.7l-2.1 9.78a34.88 34.88 0 0012.79 2.36c12.08 0 20-6 20-15.21m30 14.51h10.64l-9.29-44.46h-9.81a5.22 5.22 0 00-4.9 3.26l-17.26 41.2h12.13l2.39-6.64h14.76zM143.1 56.39l6.06-16.7 3.48 16.7zM94.71 27.68L85.2 72.14H73.7l9.51-44.46zM0 99v99h198V99zm51.29 60.13a13.71 13.71 0 01-6.35-1.47l-.33-.2 1.21-4.39.56.33a10.75 10.75 0 005.18 1.36c2.23 0 3.56-1 3.56-2.56 0-1.23-.6-2.15-3.64-3.26-4.32-1.51-6.42-3.79-6.42-7 0-4.1 3.48-7 8.46-7a12.62 12.62 0 015.58 1.15l.38.18-1.25 4.33-.54-.29a9.29 9.29 0 00-4.27-1c-2.36 0-3.2 1.19-3.2 2.2 0 1.33.86 2 4 3.2 4.21 1.62 6.08 3.82 6.08 7.13s-2.37 7.29-9.01 7.29zm25.92-.34H62.65v-23.45h14.06v4.37h-9v4.79h8.46v4.33h-8.42v5.6h9.46zm13.72-4.09a11.93 11.93 0 004.27-.76l.56-.24.87 4.21-.35.17a15.33 15.33 0 01-6 1c-7.26 0-12-4.63-12-11.8 0-7.37 5-12.32 12.52-12.32a13.37 13.37 0 015.66 1l.36.19-1.12 4.21-.54-.23a10.36 10.36 0 00-4.19-.83c-4.61 0-7.36 2.91-7.36 7.76s2.74 7.64 7.32 7.64zm26.29-6c0 6.76-3.36 10.49-9.46 10.49-4.13 0-9.06-1.81-9.06-10.46v-13.39h5.1v13.59c0 2.71.71 5.93 4.09 5.93 2.81 0 4.23-2 4.23-5.93v-13.59h5.1zm15.22 10.12l-.14-.27a29.69 29.69 0 01-1.54-5.42c-.67-2.94-1.68-3.48-3.56-3.53h-1.55v9.22h-5.06v-23l.4-.08a36.94 36.94 0 016.29-.5c3.42 0 5.65.59 7.25 1.91a6.18 6.18 0 012.11 4.92 6.31 6.31 0 01-3.52 5.67 6.66 6.66 0 012.54 4.23c.17.63.33 1.26.48 1.86a33.82 33.82 0 001.25 4.3l.36.72zm21.33 0h-14.56v-23.48h14.06v4.37h-9v4.79h8.46v4.33h-8.46v5.6h9.46z" transform="translate(0 -26.87)"></path><path fill="#939598" d="M127.68 139.27a13.36 13.36 0 00-2 .12v6.18h1.93c2.45 0 4-1.23 4-3.2-.06-2.56-2.16-3.1-3.93-3.1z" transform="translate(0 -26.87)"></path></svg>';
                        secureLogos.innerHTML += '<svg xmlns="http://www.w3.org/2000/svg" data-name="Layer 1" viewBox="0 0 300 128.36"><path fill="#8e9193" d="M2 57.75L9.49 1.27H23l.6 33.6 9-33.6H47l-7 56.48h-9.45l4.86-42.19-11.7 42.19h-6.54L16.41 16l-5.63 41.75zM78.5 20.2c-5.07 19 10.94 16.88 10.76 24.2s-14 2.15-14 2.15l-.89 10.51a19.53 19.53 0 009 1.3c4.91-.5 15.07-.53 14.72-15.17S87.28 30.71 87.28 24.94s11.54-2.67 11.54-2.67l1.12-10.85s-18-4.14-21.44 8.78zM106 5l-4.91 37.81s-2.67 14 5.86 15.6c3.44.67 7.06-.53 7.92-1.48L116 47s-5.6 2.24-5.77-2.32 2.5-22 2.5-22h5.16L119.06 12l-4.82-.26L115.36 5zm13.09 27.73c-1.72 17.91 6.29 25.59 14.55 25.59s10.94-2.08 10.94-2.08L146 44.91s-2.15 3.1-10.5 3.1-7.41-9.9-7.41-9.9H147c4-21.79-4.31-27.47-11.28-27.47-4.17 0-14.94 4.22-16.66 22.13zm9.82-3.26s1.46-8.79 5.94-8.79 4.82 6.22 4.48 8.79zm6.2 73.13c-1.61 17.91 5.88 25.59 13.61 25.59s10.23-2.08 10.23-2.08l1.37-11.37s-2 3.1-9.83 3.1-6.92-9.9-6.92-9.9h17.64c3.78-21.79-4-27.47-10.56-27.47-3.89.04-13.96 4.26-15.57 22.17zm9.18-3.26s1.37-8.79 5.56-8.79 4.51 6.22 4.19 8.79zm113.48 3.26c-1.61 17.91 5.88 25.59 13.62 25.59s10.23-2.08 10.23-2.08l1.38-11.33s-2 3.1-9.83 3.1-6.93-9.9-6.93-9.9h17.64c3.79-21.79-4-27.47-10.55-27.47-3.91 0-13.98 4.22-15.59 22.13zm9.18-3.26s1.37-8.79 5.56-8.79 4.51 6.22 4.19 8.79zm-237 3.26c-1.61 17.91 5.88 25.59 13.62 25.59s10.23-2.08 10.23-2.08l1.37-11.37s-2 3.1-9.83 3.1-6.93-9.9-6.93-9.9H56c3.79-21.79-4-27.47-10.55-27.47-3.88.04-13.94 4.26-15.56 22.17zm9.19-3.26s1.37-8.79 5.56-8.79 4.51 6.22 4.18 8.79zm11.63-86.03l-1.2 10.25s17.48-8.1 16.1 4.82c0 0-19-3.7-20.75 14.73-1 14.64 7.49 16.54 12.65 14.64s5.69-4.91 5.69-4.91l-.17 4.91h7.83l3.36-30.49s2.07-16.62-9.9-16.62-13.61 2.67-13.61 2.67zm11.58 31.23c-2.32 4.84-6.51 5.51-8 .87a6.88 6.88 0 01.24-5c2.46-5.19 9.62-3.88 9.62-3.88S65 39 62.35 44.54zm143.13-31.23l-1.21 10.25s17.48-8.1 16.1 4.82c0 0-19-3.7-20.75 14.73-1 14.64 7.49 16.54 12.66 14.64s5.72-4.91 5.72-4.91l-.17 4.91h7.84L229 27.26s2.06-16.62-9.91-16.62-13.61 2.67-13.61 2.67zm11.58 31.23c-2.32 4.84-6.51 5.51-8 .87a6.84 6.84 0 01.25-5c2.45-5.19 9.62-3.88 9.62-3.88s.79 2.47-1.87 8.01zm-68.28 13h9.66s2.14-17 3.47-25.64 6.09-7.39 6.09-7.39c.15-3.39 3.77-13.28 3.77-13.28-6-.15-8.86 6.49-8.86 6.49l.52-5.68h-8.63zm-32.92 69.34h8s2-17.36 3.32-25.88 5.82-7.26 5.82-7.26c.14-3.33 3.63-13 3.63-13-5.76-.15-8.54 6.37-8.54 6.37l.5-5.57h-8.32zm113.26-69.39h9.67s2.14-17 3.46-25.64 6.05-7.34 6.05-7.34c.15-3.39 3.77-13.28 3.77-13.28-6-.15-8.86 6.49-8.86 6.49l.52-5.68h-8.63zM202.34 3s-13.63-8.8-23.46 3.8-10 36.38-3.61 45.08 16.6 7.08 20.36 4.87L197.18 44s-4.8 3.91-9.52 2.95c-6.43-1.31-10.11-14.76-4.79-27.52s15.42-6.2 17.92-4.28zm-7.81 69.18s-12.72-8.76-21.9 3.84-9.3 36.37-3.38 45.08a14.39 14.39 0 0019 4.87l1.45-12.77s-4.48 3.91-8.89 3c-6-1.3-9.43-14.75-4.47-27.52s14.39-6.19 16.74-4.28zM84.1 84.08s-8.45-9.49-19.16 2c-8.41 9-6.29 30.72-1.4 37.42s13 4.43 15.91 2.72l.68-10.54a9.66 9.66 0 01-6.45 1c-5-1-7.8-9.38-3.71-19.21s10.6-4.85 12.53-3.37zm189.2-67.19s-2.86-6.25-8.58-6.25-12.72 6.73-15.17 21.51 4 26.21 10.11 26.21 9.73-5.14 9.73-5.14l-.39 4.27h8.67l7.15-56h-9.25zm-2.81 18.23c-.8 6.68-3.93 11.81-7 11.44s-4.9-6.08-4.1-12.77 3.93-11.81 7-11.44 4.9 6.08 4.1 12.77zM247 85.43s-2.86-6.26-8.58-6.26-11 6.55-13.49 21.33 2.33 26.4 8.43 26.4 9.73-5.15 9.73-5.15l-.38 4.27h8.67l7.15-56h-9.24zm-2.19 18.22c-.8 6.69-3.93 11.81-7 11.45s-4.9-6.09-4.1-12.78 3.93-11.81 7-11.44 4.87 6.12 4.07 12.77zm-23.09 2.49c-1.58 13.17-9.55 23.06-17.81 22.07s-13.67-12.47-12.1-25.64 9.55-23.06 17.8-22.07 13.65 12.5 12.08 25.64zm-13.66-13.88c-3.07-.36-6.2 4.76-7 11.45s1 12.4 4.09 12.77 6.2-4.76 7-11.45-1.03-12.4-4.09-12.77zM85 111.52c-.5 6.1 2.23 16.71 8.49 16.71s9.89-8.55 9.89-8.55l-.58 7.15h8.58l5.36-45.65h-9.32l-2.88 23.19s-.66 10.75-6.76 10.11c-4.54-.47-3.14-8.32-3.14-8.32l3.14-25H88s-2.56 24.26-3 30.36zm-79.73-31c-6.78 23.17 16.51 21.2 12 31.43S2 113.54 2 113.54l-2 11.82c10.23 6 25.46 4.16 26.63-15.77S11.4 92.66 13.85 84.35s14-1.71 14-1.71l1.87-11.4s-18.85-10.01-24.5 9.27zm286.86 36.66h-5.51v1.49h2.05v7.48H290v-7.48h2.07zm.8 0v9h1.39v-2.45l-.14-4.23 1.83 6.68h1l1.83-6.69-.14 4.24v2.45h1.3v-9h-1.83l-1.73 6.51-1.74-6.51zm-3-63.48h1.21v-.06L290 51.2a1.71 1.71 0 00.74-.66 2 2 0 00.24-1 1.7 1.7 0 00-.53-1.33 2.14 2.14 0 00-1.49-.48h-2v6H288v-2.22h.9zm-1.93-5h.91a.86.86 0 01.67.24 1 1 0 01.22.67.82.82 0 01-.89.89H288zm6.72 2.21c0-4.06-2.67-7.36-6-7.36s-5.95 3.3-5.95 7.36 2.67 7.36 5.95 7.36 6-3.27 6-7.35zm-1.23 0c0 3.38-2.12 6.12-4.72 6.12s-4.72-2.74-4.72-6.12 2.12-6.12 4.72-6.12 4.72 2.75 4.72 6.13z"></path></svg>';
                        return secureLogos;
                    };

                    this.createEverypayLogo = function() {
                        var everypayLogo = document.createElement('div');
                        everypayLogo.setAttribute('id', 'everypay-logo');
                        var everypayLogoText = document.createElement('span');
                        everypayLogoText.innerHTML = 'Πληρωμή μέσω Ιδρύματος Πληρωμών';
                        everypayLogo.appendChild(everypayLogoText);
                        everypayLogo.innerHTML += '<svg xmlns="http://www.w3.org/2000/svg" data-name="Layer 1" viewBox="0 0 1250.31 297.48"><path fill="#ffffff" d="M486.11 88c.73-2.91.28-3.7-2.79-3.7-29.42.09-58.83 0-88.25.09-14.47.06-21.66 6.12-24.72 20.26-2.94 13.59-5.79 27.2-8.68 40.79-3.79 17.89-7.61 35.76-11.28 53.68-2.15 10.53 2.82 17.41 13.41 18.93a33 33 0 004.88.24h87.47c1.42 0 2.91.43 3.37-1.91 1.54-8 3.33-15.89 5.12-24.23h-69.33c-7 0-8.77-2.22-7.37-9.07 1.12-5.52 2.52-11 3.52-16.53.54-3 1.67-4 4.88-4 22.5.18 45 0 67.51.18 3.49 0 5.1-.67 5.61-4.43a135.92 135.92 0 013.58-16.5c1-3.52-.09-4.06-3.39-4-22.75.12-45.5 0-68.28.15-3 0-3.7-.82-3-3.61 1.06-4.37 1.94-8.8 2.85-13.22 1.88-9 3.79-10.62 12.92-10.65 21.23 0 42.49-.06 63.72.06 2.73 0 3.73-.91 4.19-3.4 1.27-6.41 2.49-12.81 4.06-19.13zM1027.52 114.64c-1-18.72-10.53-28.63-29.18-29.85-12-.79-24.11-.15-36.18-.15v-.24c-11.07 0-22.11-.09-33.18 0-14.29.18-21.38 5.6-24.42 19.6-8.09 37.09-15.95 74.22-23.93 111.34-.51 2.39.25 3.06 2.64 3 9.19-.12 18.35-.18 27.54 0 3.13.09 4-1.21 4.52-3.91 2.43-12 5.1-24 7.58-36 .49-2.46 1.61-3.13 4-3.06 7.55.18 15.07.18 22.62 0 13.8-.25 27.66.84 41.43-.7 14.26-1.61 24.78-8.43 30.18-22.11a90.59 90.59 0 006.38-37.92zm-35.37 9.85a42.79 42.79 0 01-2.76 13.2c-2.42 6.64-7.34 9.82-14.16 10.58-7.89.91-15.8.33-22.44.52h-20.51c-2 0-3-.31-2.48-2.73 2-8.92 3.73-17.9 5.79-26.78 1.4-6.07 3.91-8 10-8 11.31-.06 22.62-.09 33.91.06 9.56.11 12.99 3.78 12.65 13.15zM294.74 126.1c-5.31-35.3-21.66-64.69-48-88.59a135 135 0 00-35.58-23.38C168.05-5.1 125-4.8 82.5 15.64c-25.27 12.13-45.07 30.67-60 54.44a152 152 0 00-19.5 49c-3.27 17.66-4.15 35.4-1.21 53.11 6.07 36.67 23.11 67.36 51.86 91 33.88 27.9 73 38.73 116.38 32.6 38.51-5.43 70.06-24.08 94.38-54.35a141.81 141.81 0 0031.91-85.8 205.3 205.3 0 00-.73-25.17c-.31-1.47-.59-2.88-.85-4.37zM247.09 219c-20.08 28-47.46 44.89-81.55 49.71-58.69 8.31-111.49-25.72-131-78-8.47-22.68-9.77-45.89-4.64-69.48a13.28 13.28 0 01.81-2.09c5.86-23.75 18.2-43.65 36.19-60 19.1-17.41 41.8-27.48 67.43-30.67 47.49-5.91 95.69 18.47 119.4 60.3 7.4 13.07 12.71 26.9 14.65 41.91 4.46 32.18-2.3 61.84-21.29 88.32zM891.43 116.88c-8.95 0-17.86.12-26.75-.06a6.39 6.39 0 00-6.34 3.43c-13.52 21.44-27.2 42.79-40.85 64.14-.73 1.16-1.52 2.25-2.76 4.13-.36-1.43-.52-1.88-.61-2.34l-13.65-65.87c-.36-1.82-.51-3.52-3.3-3.52-15.83 0-31.66-.42-47.5.58a52.76 52.76 0 00-19.29 4.63c-12.19 5.76-18.22 16.28-21.14 28.66-5 21.59-9.46 43.31-14.22 65-.58 2.61.79 2.61 2.64 2.61 7.91 0 15.83-.09 23.78 0 2.18 0 3.12-.7 3.58-2.76 2.48-11.89 5-23.78 7.61-35.64 2-8.79 3.15-17.77 6.67-26.17 3.25-7.76 8.83-12.62 17.2-14.13 5.24-.94 10.52-.55 15.77-.82 2.58-.12 3.46 1 4 3.27q9 36.31 18.11 72.55a6.8 6.8 0 01-1.09 6.22c-6.28 8.79-12.38 17.71-18.47 26.6-.67 1-1.79 1.79-1.58 3.39 8.82 0 17.62-.12 26.42.06a5.84 5.84 0 005.76-3q42.12-63.78 84.46-127.47c.64-.91 1.7-1.73 1.55-3.49zM622.93 177.48c17.59.15 35.21.12 52.8.06 11.8-.06 17.68-4.67 20.44-15.95a79.34 79.34 0 002.19-14.11c.85-13.49-5.58-23.41-18.29-27.9a53.28 53.28 0 00-8.71-2.24c-13.49-2.34-27.05-2-40.58-.64s-25.14 6.43-31.24 19.62a122 122 0 00-11.64 54.32c.3 16.29 10.25 25.75 26.48 27.11 11.31.95 22.62.22 31.93.55 11.53 0 21.08-.06 30.66.06 2.31 0 3.34-.57 3.74-3 .75-4.7 2-9.31 2.94-14 .94-4.52.91-4.52-3.89-4.52-16.22-.09-32.45.06-48.64-.34-9.86-.24-13.53-5.18-12.68-15 .21-3.23 1.27-4.05 4.49-4.02zm1.55-24.72c2.76-9.19 8.64-14.25 18.31-15.29 6.37-.66 12.56-.54 18.5 1.88 5.28 2.16 7.4 6.31 6.22 11.41-1 4.34-2.85 5.94-7.34 6-5.64.12-11.31 0-16.95 0-5.28 0-10.56-.15-15.83.06-3.67.28-3.86-1.03-2.91-4.06zM1104.83 117.21c-10.16-.39-20.35-.06-30.51-.06v-.24c-10.56 0-21.11.06-31.67 0-2.39 0-3.55.52-4 3.19a122.65 122.65 0 01-3.06 14.31c-.88 3.15-.31 4.13 3.12 4.09 15.59-.15 31.18-.09 46.74-.06 9.22 0 12.43 4 10.73 13.11-.45 2.54-1.42 3.67-4.36 3.61-15-.19-29.91-.25-44.86 0-12 .18-21.84 4.61-26.72 16.49a53.57 53.57 0 00-3.76 24c.91 14.65 9 22.54 23.29 22.66q26.57.27 53.17 0c13-.15 19.38-5.34 22.47-17.86 1.76-7.16 3.19-14.44 4.67-21.66 2.31-11.28 5.1-22.5 6.7-33.91 1.98-14.14-5.45-27-21.95-27.67zm-14.38 61.12c-1.12 3.94-1.67 8.06-2.55 12.1-1.15 5.22-3.24 7.07-8.55 7.13q-12.42.18-24.84 0c-5.61-.1-7.73-2.43-7.4-8a29.2 29.2 0 011-6.28c1.55-5.34 4.49-7.61 10.13-7.76 5-.15 10 0 15 0 4.89 0 9.8.06 14.68 0 2.2-.1 3.29.08 2.53 2.81zM1250.28 116.91c-9.19 0-18.34.09-27.5-.06a5.25 5.25 0 00-5.13 2.85c-8.34 13.29-16.86 26.48-25.29 39.7-6.07 9.49-12.14 19-18.72 29.33-.51-2.21-.85-3.52-1.12-4.85-4.37-21.11-8.8-42.22-13.07-63.33-.58-2.91-1.73-3.82-4.7-3.73-7.28.24-14.59.24-21.87 0-3.31-.09-4 .67-3.15 4q11.91 47 23.59 94.05a6.54 6.54 0 01-1.06 5.95c-5.4 7.58-10.64 15.25-15.92 22.9-1.55 2.21-3 4.48-4.82 7.18 9.58 0 18.47-.12 27.38.06 2.61.07 4-.9 5.4-3q42.09-63.65 84.29-127.2c.69-1.06 1.94-2 1.69-3.85zM571.13 116.82c-2.76-.06-4.25.88-5.64 3.21q-18.21 30.81-36.61 61.42a51.75 51.75 0 01-3.07 4.73c-1.3 1.76-2.54 1.58-3.09-.6s-1-4.64-1.39-7c-3.37-19.38-6.8-38.76-10.07-58.14-.46-2.67-1.34-3.76-4.25-3.64-7.16.24-14.34.21-21.5 0-3-.09-3.95.7-3.28 3.82q9.11 43.41 18 86.83c1 4.83 3.64 8.28 8.22 10 9.94 3.73 26.26 2.49 34.21-10.43 14.19-23.08 29.14-45.71 43.76-68.54 4.46-6.95 8.92-13.93 13.83-21.6-10.32 0-19.72.12-29.12-.06z"></path><path fill="#ffffff" d="M207.88 77.63c-35.19-.06-70.4 0-105.58-.09-2.58 0-3.7.79-4.58 3.19-10.19 27.75-20.56 55.41-30.78 83.16-5.22 14.19 3 26.69 18 27.54 3-8.1 6.07-16.17 8.8-24.35 1.06-3.16 2.55-4.1 5.88-4.1 35.33.12 70.64.06 106 .12 2.31 0 3.88-.27 4.82-2.88 6.43-17.53 13.08-35 19.57-52.47a20.57 20.57 0 001.3-11.07c-2.23-11.92-10.79-19.05-23.43-19.05zm-9.62 38.92c-1.82 5.06-3.94 10-5.64 15.13-.76 2.34-1.88 3-4.28 3-13.19-.12-26.38-.06-39.61-.06-12.95 0-25.9-.06-38.85 0-2.82 0-4.15-.12-2.76-3.58 3.07-7.4 5.77-14.95 8.49-22.5a3.42 3.42 0 013.8-2.67c23.9.09 47.76 0 71.66.09 6.19.09 9.31 4.73 7.19 10.59zM85 191.46h-.06zM103.45 219.88c28.79.21 57.57.06 86.35.12 2 0 2.91-.64 3.31-2.61a20.84 20.84 0 00-4.74-18.59c-4.79-5.61-11.1-7.28-18.22-7.28q-42.59 0-85.17-.06c-5.24 14.41 5.58 28.33 18.47 28.42z"></path></svg>';
                        return everypayLogo;
                    };

                    this.init();
                }

            }
        </script>
        <?php

        return '';
    }

    private function getToken()
    {
        $paymentMethodId = $this->_currentMethod->virtuemart_paymentmethod_id ?? '';
        return vRequest::getVar('everypayToken' . $paymentMethodId, null)
            ?: JFactory::getSession()->get('everypay_token', null, 'vm');
    }

    function plgVmOnShowOrderBEPayment ($virtuemart_order_id, $payment_method_id) {

        if (!$this->selectedThisByMethodId ($payment_method_id)) {
            return NULL;
        } // Another method was selected, do nothing

        if (!($paymentTable = $this->_getInternalData ($virtuemart_order_id))) {
            // JError::raiseWarning(500, $db->getErrorMsg());
            return '';
        }

        $this->getPaymentCurrency ($paymentTable);
        $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' .
            $paymentTable->payment_currency . '" ';
        $db = JFactory::getDBO ();
        $db->setQuery ($q);
        $html = '<table class="adminlist table">' . "\n";
        $html .= $this->getHtmlHeaderBE ();
        $html .= $this->getHtmlRowBE ('PAYMENT_NAME', $paymentTable->payment_name);

        $code = "mb_";
        foreach ($paymentTable as $key => $value) {
            if (substr ($key, 0, strlen ($code)) == $code) {
                $html .= $this->getHtmlRowBE ($key, $value);
            }
        }
        $html .= '</table>' . "\n";
        return $html;
    }

    private function getPublicKey()
    {
        return $this->_currentMethod->sandbox
            ? $this->_currentMethod->sandbox_public_key
            : $this->_currentMethod->public_key;
    }

    private function getSecretKey($method)
    {
        if ($method->sandbox == '1') {
            return $method->sandbox_secret_key;
        }

        return $method->secret_key;
    }

    function _handlePaymentCancel($virtuemart_order_id, $html) {

		if (!class_exists('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		$modelOrder = VmModel::getModel('orders');
		$modelOrder->remove(array('virtuemart_order_id' => $virtuemart_order_id));
		// error while processing the payment
		$mainframe = JFactory::getApplication();
		$mainframe->enqueueMessage($html);
		$mainframe->redirect(JRoute::_('index.php?option=com_virtuemart&view=cart&task=editpayment', FALSE), vmText::_('COM_VIRTUEMART_CART_ORDERDONE_DATA_NOT_VALID'));
    }

	/**
	 * Create the table for this plugin if it does not yet exist.
	 * This functions checks if the called plugin is active one.
	 * When yes it is calling the standard method to create the tables
	 *
	 * @author Valérie Isaksen
	 *
	 */
	function plgVmOnStoreInstallPaymentPluginTable ($jplugin_id) {

		return $this->onStoreInstallPluginTable ($jplugin_id);
	}

	/**
	 * This event is fired after the payment method has been selected. It can be used to store
	 * additional payment info in the cart.
	 *
	 * @author Max Milbers
	 * @author Valérie isaksen
	 *
	 * @param VirtueMartCart $cart: the actual cart
	 * @return null if the payment was not selected, true if the data is valid, error message if the data is not valid
	 *
	 */
	public function plgVmOnSelectCheckPayment (VirtueMartCart $cart,  &$msg) {
		return $this->OnSelectCheck ($cart);
	}

    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the plugin methods in the cart (edit shipment/payment)
     *
     * @param VirtueMartCart $cart Cart object
     * @param integer $selected ID of the method selected
     * @param $htmlIn
     * @return boolean True on success, false on failures, null when this plugin was not selected.
     * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     *
     * @throws Exception
     * @author Max Milbers
     * @author Valerie Isaksen
     */
	public function plgVmDisplayListFEPayment (VirtueMartCart $cart, $selected = 0, &$htmlIn) {

        if ($this->getPluginMethods ($cart->vendorId) === 0) {
            if (empty($this->_name)) {
                vmAdminInfo ('displayListFE cartVendorId=' . $cart->vendorId);
                $app = JFactory::getApplication ();
                $app->enqueueMessage (vmText::_ ('COM_VIRTUEMART_CART_NO_' . strtoupper ($this->_psType)));
                return false;
            } else {
                return false;
            }
        }

        $method_name = $this->_psType . '_name';
        $idN = 'virtuemart_'.$this->_psType.'method_id';
        $ret = false;

        foreach ($this->methods as $method) {
            $this->_currentMethod = $method;

            if(!isset($htmlIn[$this->_psType][$method->$idN])) {
                if ($this->checkConditions ($cart, $method, $cart->cartPrices)) {

                    // the price must not be overwritten directly in the cart
                    $prices = $cart->cartPrices;
                    $methodSalesPrice = $this->setCartPrices ($cart, $prices ,$method);

                    $method->$method_name = $this->renderPluginName ($method);

                    $sandbox = $this->_currentMethod->sandbox;
                    $html = $this->getPluginHtml($this->_currentMethod, $selected, $methodSalesPrice);
                    if ($selected == $this->_currentMethod->virtuemart_paymentmethod_id
                        && $this->hasBillingAddress($cart)
                    ) {
                       $this->displayForm($cart, $sandbox);
                    }

                    $htmlIn[$this->_psType][$method->$idN] = $html;

                    $ret = TRUE;
                }
            } else {
                $ret = TRUE;
            }
        }

        return $ret;
	}


	public function plgVmonSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {

		return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
	}

	/**
	 * plgVmOnCheckAutomaticSelectedPayment
	 * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
	 * The plugin must check first if it is the correct type
	 *
	 * @author Valerie Isaksen
	 * @param VirtueMartCart cart: the cart object
	 * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
	 *
	 */
	function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) {

		return $this->onCheckAutomaticSelected ($cart, $cart_prices, $paymentCounter);
	}

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param $virtuemart_order_id
     * @param $virtuemart_paymentmethod_id
     * @param $payment_name
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     * @author Max Milbers
     * @author Valerie Isaksen
     */
	public function plgVmOnShowOrderFEPayment ($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {

		$this->onShowOrderFE ($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}

	/**
	 * This event is fired during the checkout process. It can be used to validate the
	 * method data as entered by the user.
	 *
	 * @return boolean True when the data was valid, false otherwise. If the plugin is not activated, it should return null.
	 * @author Max Milbers

	public function plgVmOnCheckoutCheckDataPayment($psType, VirtueMartCart $cart) {
	return null;
	}
	 */

    /**
     * This method is fired when showing when printing an Order
     * It displays the payment method-specific data.
     *
     * @param $order_number
     * @param integer $method_id method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     */
	function plgVmonShowOrderPrintPayment ($order_number, $method_id) {

		return $this->onShowOrderPrint ($order_number, $method_id);
	}

	/**
	 * Save updated order data to the method specific table
	 *
	 * @param array $_formData Form data
	 * @return mixed, True on success, false on failures (the rest of the save-process will be
	 * skipped!), or null when this method is not activated.

	public function plgVmOnUpdateOrderPayment(  $_formData) {
	return null;
	}
	 */
	/**
	 * Save updated orderline data to the method specific table
	 *
	 * @param array $_formData Form data
	 * @return mixed, True on success, false on failures (the rest of the save-process will be
	 * skipped!), or null when this method is not actived.

	public function plgVmOnUpdateOrderLine(  $_formData) {
	return null;
	}
	 */
	/**
	 * plgVmOnEditOrderLineBE
	 * This method is fired when editing the order line details in the backend.
	 * It can be used to add line specific package codes
	 *
	 * @param integer $_orderId The order ID
	 * @param integer $_lineId
	 * @return mixed Null for method that aren't active, text (HTML) otherwise

	public function plgVmOnEditOrderLineBE(  $_orderId, $_lineId) {
	return null;
	}
	 */

	/**
	 * This method is fired when showing the order details in the frontend, for every orderline.
	 * It can be used to display line specific package codes, e.g. with a link to external tracking and
	 * tracing systems
	 *
	 * @param integer $_orderId The order ID
	 * @param integer $_lineId
	 * @return mixed Null for method that aren't active, text (HTML) otherwise

	public function plgVmOnShowOrderLineFE(  $_orderId, $_lineId) {
	return null;
	}
	 */
	function plgVmDeclarePluginParamsPaymentVM3( &$data) {
		return $this->declarePluginParams('payment', $data);
	}

	function plgVmSetOnTablePluginParamsPayment ($name, $id, &$table) {

		return $this->setOnTablePluginParams ($name, $id, $table);
	}
}
