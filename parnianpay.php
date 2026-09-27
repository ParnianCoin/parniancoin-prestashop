<?php
/**
 * Parnian Pay — ParnianCoin (PARC) payments for PrestaShop 1.7.7 – 9.x
 *
 * Non-custodial: the buyer pays from their own wallet straight into the merchant's PARC account.
 * Flow: checkout -> order created in "Awaiting ParnianCoin payment" -> invoice created server-to-server
 *       -> buyer redirected to the hosted payment page -> signed webhook (re-checked with the gateway)
 *       moves the order to "Payment accepted".
 */
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/lib/parnianpay_client.php';

class ParnianPay extends PaymentModule
{
    const CFG = [
        'API_KEY' => '', 'WEBHOOK_SECRET' => '', 'GATEWAY_URL' => 'https://pay.parniancoin.com',
        'EXPIRES' => 30, 'DEBUG' => 0, 'OS_PAID' => 0, 'OS_EXPIRED' => 0,
    ];

    public function __construct()
    {
        $this->name = 'parnianpay';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'ParnianCoin';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.7.0', 'max' => '9.99.99'];
        $this->bootstrap = true;
        $this->controllers = ['redirect', 'callback', 'webhook'];
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        parent::__construct();
        $this->displayName = $this->l('Parnian Pay (ParnianCoin)');
        $this->description = $this->l('Accept ParnianCoin (PARC). Payments go directly from the buyer’s wallet to your own PARC account.');
        $this->confirmUninstall = $this->l('Uninstall Parnian Pay? Past orders keep their history.');
    }

    // ------------------------------------------------------------------ install

    public function install()
    {
        return parent::install()
            && $this->installDb()
            && $this->installStates()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayPaymentReturn')
            && $this->registerHook('displayAdminOrderMainBottom')
            && Configuration::updateValue('PARNIANPAY_GATEWAY_URL', self::CFG['GATEWAY_URL'])
            && Configuration::updateValue('PARNIANPAY_EXPIRES', self::CFG['EXPIRES'])
            && Configuration::updateValue('PARNIANPAY_OS_PAID', (int) Configuration::get('PS_OS_PAYMENT'))
            && Configuration::updateValue('PARNIANPAY_OS_EXPIRED', (int) Configuration::get('PS_OS_CANCELED'));
    }

    public function uninstall()
    {
        // The invoice table and the order states are kept: past orders still refer to them.
        foreach (array_keys(self::CFG) as $k) {
            Configuration::deleteByName('PARNIANPAY_' . $k);
        }
        Configuration::deleteByName('PARNIANPAY_STATUS_CACHE');
        return parent::uninstall();
    }

    private function installDb()
    {
        return Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'parnianpay_invoice` (
            `id_order` INT UNSIGNED NOT NULL,
            `id_cart` INT UNSIGNED NOT NULL,
            `invoice_id` VARCHAR(64) NOT NULL,
            `amount` VARCHAR(32) NOT NULL,
            `fiat` VARCHAR(64) NOT NULL,
            `rate` VARCHAR(40) NOT NULL DEFAULT \'\',
            `pay_url` VARCHAR(255) NOT NULL,
            `state` VARCHAR(16) NOT NULL DEFAULT \'pending\',
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_order`),
            KEY `invoice_id` (`invoice_id`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    /** Two own order states: awaiting payment, and needs review (partial / late / mismatched payment). */
    private function installStates()
    {
        $defs = [
            'PARNIANPAY_OS_AWAITING' => ['#2F9EFF', [
                'en' => 'Awaiting ParnianCoin payment', 'fa' => 'در انتظار پرداخت پرنیان‌کوین', 'ar' => 'بانتظار دفع برنيان كوين', ]],
            'PARNIANPAY_OS_REVIEW' => ['#E7B65B', [
                'en' => 'ParnianCoin payment needs review', 'fa' => 'پرداخت پرنیان‌کوین نیاز به بررسی دارد', 'ar' => 'دفع برنيان كوين يحتاج مراجعة', ]],
        ];
        foreach ($defs as $key => list($color, $names)) {
            $id = (int) Configuration::get($key);
            if ($id && Validate::isLoadedObject(new OrderState($id))) {
                continue;
            }
            $state = new OrderState();
            foreach (Language::getLanguages(false) as $lang) {
                $state->name[$lang['id_lang']] = isset($names[$lang['iso_code']]) ? $names[$lang['iso_code']] : $names['en'];
            }
            $state->module_name = $this->name;
            $state->color = $color;
            $state->send_email = false;
            $state->logable = false;
            $state->invoice = false;
            $state->paid = false;
            $state->hidden = false;
            $state->unremovable = true;
            if (!$state->add()) {
                return false;
            }
            Configuration::updateValue($key, (int) $state->id);
        }
        return true;
    }

    // ------------------------------------------------------------------ helpers

    public function client()
    {
        return new ParnianPayClient(Configuration::get('PARNIANPAY_GATEWAY_URL') ?: self::CFG['GATEWAY_URL'], (string) Configuration::get('PARNIANPAY_API_KEY'));
    }

    public function log($message, $error = false)
    {
        if ($error || Configuration::get('PARNIANPAY_DEBUG')) {
            PrestaShopLogger::addLog('Parnian Pay: ' . $message, $error ? 3 : 1, null, 'ParnianPay', null, true);
        }
    }

    /** Gateway status and rates, cached 10 minutes (2 minutes after a failure). Null when unreachable. */
    public function remoteStatus($refresh = false)
    {
        $c = $refresh ? null : json_decode((string) Configuration::get('PARNIANPAY_STATUS_CACHE'), true);
        if (is_array($c) && isset($c['at']) && time() - (int) $c['at'] < (empty($c['error']) ? 600 : 120)) {
            if (!empty($c['error'])) {
                return null;
            }
            if (isset($c['data']) && is_array($c['data'])) {
                return $c['data'];
            }
        }
        $me = $this->client()->me();
        if ($me === null) {
            $this->log('status check failed', true);
            Configuration::updateValue('PARNIANPAY_STATUS_CACHE', json_encode(['at' => time(), 'error' => true]));
            return null;
        }
        $data = ['active' => !empty($me['active']), 'rates' => (isset($me['rates']) && is_array($me['rates'])) ? $me['rates'] : [],
            'name' => isset($me['name']) ? (string) $me['name'] : '', 'status' => isset($me['status']) ? (string) $me['status'] : ''];
        Configuration::updateValue('PARNIANPAY_STATUS_CACHE', json_encode(['at' => time(), 'data' => $data]));
        return $data;
    }

    public function clearStatus()
    {
        Configuration::updateValue('PARNIANPAY_STATUS_CACHE', '');
    }

    /** All shopper/merchant-facing texts in one place (so the translation files cover them). */
    public function t($key)
    {
        $t = [
            'option' => $this->l('Pay with ParnianCoin (PARC)'),
            'intro' => $this->l('You will be taken to the secure Parnian Pay page to pay from your ParnianCoin wallet. Never enter your private key or wallet password on this store.'),
            'unavailable' => $this->l('ParnianCoin payments are temporarily unavailable in this store.'),
            'start_failed' => $this->l('Could not start the ParnianCoin payment. Please try again in a moment.'),
            'ret_paid' => $this->l('Your ParnianCoin payment has been confirmed. Thank you!'),
            'ret_confirming' => $this->l('Your ParnianCoin payment was received and is being confirmed on the blockchain. Your order will be updated automatically.'),
            'ret_pending' => $this->l('We have not received your ParnianCoin payment yet.'),
            'ret_expired' => $this->l('The ParnianCoin payment window has expired without payment.'),
            'ret_review' => $this->l('Your ParnianCoin payment is being reviewed by the store.'),
            'open_pay' => $this->l('Open the payment page'),
            'c_created' => $this->l('ParnianCoin invoice created: %s PARC (%s).'),
            'c_paid' => $this->l('ParnianCoin payment confirmed: %s PARC. %s'),
            'c_mismatch' => $this->l('ParnianCoin invoice %s is marked paid but the amount does not match the order. Please check. %s'),
            'c_late' => $this->l('ParnianCoin payment arrived after the invoice expired (%s PARC). Decide manually: accept the order or refund. %s'),
            'c_underpaid' => $this->l('ParnianCoin invoice expired with a partial payment (%s PARC). Contact the customer. %s'),
            'c_expired' => $this->l('ParnianCoin invoice expired without payment.'),
            'description' => $this->l('Order %s — %s'),
            'adm_invoice' => $this->l('ParnianCoin invoice'),
            'adm_rate' => $this->l('Rate: 1 PARC = %s %s'),
            'adm_state' => $this->l('State'),
        ];
        return isset($t[$key]) ? $t[$key] : $key;
    }

    public function getInvoiceRow($id_order)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'parnianpay_invoice` WHERE `id_order` = ' . (int) $id_order);
        return $row ?: null;
    }

    public function saveInvoiceRow($id_order, $id_cart, array $inv, $fiat_key)
    {
        return Db::getInstance()->execute('REPLACE INTO `' . _DB_PREFIX_ . 'parnianpay_invoice` SET
            `id_order` = ' . (int) $id_order . ', `id_cart` = ' . (int) $id_cart . ',
            `invoice_id` = \'' . pSQL($inv['id']) . '\', `amount` = \'' . pSQL($inv['amount']) . '\',
            `fiat` = \'' . pSQL($fiat_key) . '\', `rate` = \'' . pSQL(isset($inv['rate']) ? (string) $inv['rate'] : '') . '\',
            `pay_url` = \'' . pSQL($inv['pay_url']) . '\', `state` = \'pending\', `date_add` = NOW(), `date_upd` = NOW()');
    }

    /** Applies the gateway's invoice state to the order (idempotent). Returns the new local state. */
    public function sync($id_order, array $inv)
    {
        $row = $this->getInvoiceRow($id_order);
        if (!$row) {
            return null;
        }
        $d = ParnianPayClient::decide($inv, (string) $id_order, $row['invoice_id'], $row['amount'], $row['state']);
        if ($d === null) {
            $this->log('invoice ' . (isset($inv['id']) ? $inv['id'] : '?') . ' does not belong to order ' . $id_order, true);
            return $row['state'];
        }
        if ($d['action'] === 'none') {
            return $d['state'];
        }
        $order = new Order((int) $id_order);
        if (!Validate::isLoadedObject($order)) {
            return $row['state'];
        }
        $link = rtrim(Configuration::get('PARNIANPAY_GATEWAY_URL') ?: self::CFG['GATEWAY_URL'], '/') . '/pay/' . $inv['id'];
        $paid = isset($inv['paid']) ? $inv['paid'] : '0';
        $note = '';
        $new_state = 0;

        switch ($d['action']) {
            case 'paid':
                $new_state = (int) Configuration::get('PARNIANPAY_OS_PAID') ?: (int) Configuration::get('PS_OS_PAYMENT');
                $note = sprintf($this->t('c_paid'), $paid, $link);
                break;
            case 'confirming':
                $note = $this->t('ret_confirming');
                break;
            case 'mismatch':
                $new_state = (int) Configuration::get('PARNIANPAY_OS_REVIEW');
                $note = sprintf($this->t('c_mismatch'), $inv['id'], $link);
                break;
            case 'review':
                $new_state = (int) Configuration::get('PARNIANPAY_OS_REVIEW');
                $note = sprintf($this->t($inv['status'] === 'late' ? 'c_late' : 'c_underpaid'), $paid, $link);
                break;
            case 'expired':
                $new_state = (int) Configuration::get('PARNIANPAY_OS_EXPIRED') ?: (int) Configuration::get('PS_OS_CANCELED');
                $note = $this->t('c_expired');
                break;
        }

        if ($new_state && (int) $order->getCurrentState() !== $new_state) {
            $order->setCurrentState($new_state);
            if ($d['action'] === 'paid') {
                // Record the invoice id as the payment's transaction id.
                foreach ($order->getOrderPaymentCollection() as $payment) {
                    if (!$payment->transaction_id) {
                        $payment->transaction_id = $inv['id'];
                        $payment->update();
                    }
                }
            }
        }
        $this->addPrivateNote($order, $note);
        Db::getInstance()->update('parnianpay_invoice', ['state' => pSQL($d['state']), 'date_upd' => date('Y-m-d H:i:s')], '`id_order` = ' . (int) $id_order);
        $this->log('order ' . $id_order . ' -> ' . $d['state'] . ' (invoice ' . $inv['id'] . ')');
        return $d['state'];
    }

    /** Private note on the order (visible to the merchant only). */
    public function addPrivateNote(Order $order, $text)
    {
        if ($text === '') {
            return;
        }
        $msg = new Message();
        $msg->message = Tools::substr(strip_tags($text), 0, 1600);
        $msg->id_order = (int) $order->id;
        $msg->id_cart = (int) $order->id_cart;
        $msg->id_customer = (int) $order->id_customer;
        $msg->private = 1;
        $msg->add();
    }

    // ------------------------------------------------------------------ hooks

    public function hookPaymentOptions($params)
    {
        if (!$this->active || !Configuration::get('PARNIANPAY_API_KEY')) {
            return [];
        }
        $cart = $params['cart'];
        $currency = new Currency((int) $cart->id_currency);
        // Offer it only when the gateway is active and has a rate for the cart currency.
        // If the gateway cannot be reached right now, keep offering it rather than lose the sale.
        $status = $this->remoteStatus();
        if ($status !== null && (empty($status['active']) || empty($status['rates'][$currency->iso_code]))) {
            return [];
        }
        $this->context->smarty->assign(['parnianpay_intro' => $this->t('intro')]);
        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->t('option'))
            ->setAction($this->context->link->getModuleLink($this->name, 'redirect', [], true))
            ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/parnianpay.svg'))
            ->setAdditionalInformation($this->fetch('module:parnianpay/views/templates/hook/payment_info.tpl'));
        return [$option];
    }

    public function hookDisplayPaymentReturn($params)
    {
        if (!$this->active) {
            return '';
        }
        $order = isset($params['order']) ? $params['order'] : null;
        if (!$order || $order->module !== $this->name) {
            return '';
        }
        $row = $this->getInvoiceRow((int) $order->id);
        if (!$row) {
            return '';
        }
        $state = $row['state'];
        $this->context->smarty->assign([
            'parnianpay_message' => $this->t('ret_' . (in_array($state, ['paid', 'confirming', 'pending', 'expired', 'review'], true) ? $state : 'pending')),
            'parnianpay_ok' => in_array($state, ['paid', 'confirming'], true),
            'parnianpay_pay_url' => $state === 'pending' ? $row['pay_url'] : '',
            'parnianpay_open_pay' => $this->t('open_pay'),
            'parnianpay_amount' => $row['amount'],
        ]);
        return $this->fetch('module:parnianpay/views/templates/hook/payment_return.tpl');
    }

    public function hookDisplayAdminOrderMainBottom($params)
    {
        $row = $this->getInvoiceRow((int) $params['id_order']);
        if (!$row) {
            return '';
        }
        $url = rtrim(Configuration::get('PARNIANPAY_GATEWAY_URL') ?: self::CFG['GATEWAY_URL'], '/') . '/pay/' . $row['invoice_id'];
        $fiat = explode(' ', $row['fiat']);
        return '<div class="card mt-2"><div class="card-header"><h3 class="card-header-title">' . htmlspecialchars($this->t('adm_invoice')) . '</h3></div><div class="card-body">'
            . '<p><a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">' . htmlspecialchars($row['invoice_id']) . '</a> — <b>' . htmlspecialchars($row['amount']) . ' PARC</b></p>'
            . ($row['rate'] ? '<p>' . htmlspecialchars(sprintf($this->t('adm_rate'), $row['rate'], isset($fiat[1]) ? $fiat[1] : '')) . '</p>' : '')
            . '<p>' . htmlspecialchars($this->t('adm_state')) . ': <code>' . htmlspecialchars($row['state']) . '</code></p></div></div>';
    }

    // ------------------------------------------------------------------ configuration page

    public function getContent()
    {
        $out = '';
        if (Tools::isSubmit('submitParnianPay')) {
            $key = trim((string) Tools::getValue('PARNIANPAY_API_KEY'));
            if ($key !== '' && !preg_match('/^pk_live_[A-Za-z0-9_-]{20,}$/', $key)) {
                $out .= $this->displayError($this->l('Enter a valid API key (pk_live_...).'));
            } else {
                Configuration::updateValue('PARNIANPAY_API_KEY', $key);
                Configuration::updateValue('PARNIANPAY_WEBHOOK_SECRET', trim((string) Tools::getValue('PARNIANPAY_WEBHOOK_SECRET')));
                Configuration::updateValue('PARNIANPAY_GATEWAY_URL', rtrim(trim((string) Tools::getValue('PARNIANPAY_GATEWAY_URL')), '/') ?: self::CFG['GATEWAY_URL']);
                Configuration::updateValue('PARNIANPAY_EXPIRES', max(5, min(1440, (int) Tools::getValue('PARNIANPAY_EXPIRES'))));
                Configuration::updateValue('PARNIANPAY_OS_PAID', (int) Tools::getValue('PARNIANPAY_OS_PAID'));
                Configuration::updateValue('PARNIANPAY_OS_EXPIRED', (int) Tools::getValue('PARNIANPAY_OS_EXPIRED'));
                Configuration::updateValue('PARNIANPAY_DEBUG', (int) Tools::getValue('PARNIANPAY_DEBUG'));
                $out .= $this->displayConfirmation($this->l('Settings saved.'));
                $out .= $this->connectionReport();
            }
        }
        return $out . $this->renderForm();
    }

    private function connectionReport()
    {
        if (!Configuration::get('PARNIANPAY_API_KEY')) {
            return '';
        }
        $this->clearStatus();
        $client = $this->client();
        $me = $client->me();
        if ($me === null) {
            return $this->displayError(sprintf($this->l('Parnian Pay: connection failed — %s'), $client->last_error));
        }
        $this->remoteStatus(true);
        $out = empty($me['active'])
            ? $this->displayWarning(sprintf($this->l('Parnian Pay: connected as "%s", but the gateway is not active yet (status: %s). Payments are refused until an administrator approves it.'), $me['name'], $me['status']))
            : $this->displayConfirmation(sprintf($this->l('Parnian Pay: connected as "%s" — gateway active.'), $me['name']));
        $missing = [];
        foreach (Currency::getCurrencies(false, true) as $c) {
            $iso = is_array($c) ? $c['iso_code'] : $c->iso_code;
            if (empty($me['rates'][$iso])) {
                $missing[] = $iso;
            } else {
                $out .= $this->displayInformation(sprintf($this->l('Parnian Pay: current rate 1 PARC = %s %s.'), $me['rates'][$iso], $iso));
            }
        }
        if ($missing) {
            $out .= $this->displayWarning(sprintf($this->l('Parnian Pay: no conversion rate for %s in your Parnian Pay dashboard — ParnianCoin is not offered for carts in that currency.'), implode(', ', $missing)));
        }
        if (!Configuration::get('PARNIANPAY_WEBHOOK_SECRET')) {
            $out .= $this->displayWarning($this->l('Parnian Pay: the webhook signing secret is empty — payments will only be confirmed when buyers return to your store.'));
        }
        return $out;
    }

    private function renderForm()
    {
        $states = OrderState::getOrderStates((int) $this->context->language->id);
        $webhook = $this->context->link->getModuleLink($this->name, 'webhook', [], true);
        $form = ['form' => [
            'legend' => ['title' => $this->displayName, 'icon' => 'icon-cogs'],
            'description' => $this->l('The PARC price is not set here: add a rate for each store currency in your Parnian Pay dashboard → Conversion rates.'),
            'input' => [
                ['type' => 'text', 'name' => 'PARNIANPAY_API_KEY', 'label' => $this->l('API key'), 'required' => true,
                    'desc' => $this->l('Starts with pk_live_. Create it in your Parnian Pay dashboard.'), ],
                ['type' => 'text', 'name' => 'PARNIANPAY_WEBHOOK_SECRET', 'label' => $this->l('Webhook signing secret'),
                    'desc' => sprintf($this->l('Starts with whsec_. Set this Webhook URL in your Parnian Pay dashboard: %s'), $webhook), ],
                ['type' => 'text', 'name' => 'PARNIANPAY_GATEWAY_URL', 'label' => $this->l('Gateway address')],
                ['type' => 'text', 'name' => 'PARNIANPAY_EXPIRES', 'label' => $this->l('Payment window (minutes)'), 'class' => 'fixed-width-sm'],
                ['type' => 'select', 'name' => 'PARNIANPAY_OS_PAID', 'label' => $this->l('Order state when paid'),
                    'options' => ['query' => $states, 'id' => 'id_order_state', 'name' => 'name'], ],
                ['type' => 'select', 'name' => 'PARNIANPAY_OS_EXPIRED', 'label' => $this->l('Order state when expired unpaid'),
                    'options' => ['query' => $states, 'id' => 'id_order_state', 'name' => 'name'], ],
                ['type' => 'switch', 'name' => 'PARNIANPAY_DEBUG', 'label' => $this->l('Debug log'), 'is_bool' => true,
                    'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Yes')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]], ],
            ],
            'submit' => ['title' => $this->l('Save'), 'name' => 'submitParnianPay'],
        ]];
        $h = new HelperForm();
        $h->module = $this;
        $h->name_controller = $this->name;
        $h->token = Tools::getAdminTokenLite('AdminModules');
        $h->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $h->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $h->submit_action = 'submitParnianPay';
        foreach (array_keys(self::CFG) as $k) {
            $h->fields_value['PARNIANPAY_' . $k] = Tools::getValue('PARNIANPAY_' . $k, Configuration::get('PARNIANPAY_' . $k));
        }
        return $h->generateForm([$form]);
    }
}
