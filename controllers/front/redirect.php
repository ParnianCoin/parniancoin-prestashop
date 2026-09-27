<?php
/**
 * Checkout -> order in "Awaiting ParnianCoin payment" -> invoice at Parnian Pay -> hosted payment page.
 */
class ParnianPayRedirectModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        /** @var ParnianPay $m */
        $m = $this->module;
        $cart = $this->context->cart;

        if (!$cart->id || $cart->id_customer == 0 || $cart->id_address_invoice == 0 || !$m->active) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1]));
        }
        $authorized = false;
        foreach (Module::getPaymentModules() as $pm) {
            if ($pm['name'] === $m->name) {
                $authorized = true;
                break;
            }
        }
        $customer = new Customer((int) $cart->id_customer);
        if (!$authorized || !Validate::isLoadedObject($customer)) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1]));
        }

        $currency = new Currency((int) $cart->id_currency);
        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);

        // The same cart can only become one order; reuse it if the shopper comes back here.
        $id_order = (int) Order::getIdByCartId((int) $cart->id);
        if (!$id_order) {
            $m->validateOrder((int) $cart->id, (int) Configuration::get('PARNIANPAY_OS_AWAITING'), $total, $m->displayName,
                null, [], (int) $currency->id, false, $customer->secure_key);
            $id_order = (int) $m->currentOrder;
        }
        $order = new Order($id_order);

        $precision = isset($currency->precision) ? (int) $currency->precision : 2;
        $fiat = number_format((float) $order->total_paid, max(0, $precision), '.', '');
        $fiat_key = $fiat . ' ' . $currency->iso_code;
        $client = $m->client();

        // Reuse a still-open invoice for the same total.
        $row = $m->getInvoiceRow($id_order);
        if ($row && $row['fiat'] === $fiat_key && in_array($row['state'], ['pending', 'confirming'], true)) {
            $inv = $client->getInvoice($row['invoice_id']);
            if ($inv !== null && in_array($inv['status'], ['pending', 'confirming'], true)) {
                Tools::redirect($inv['pay_url']);
            }
        }

        $body = [
            'fiat_amount' => $fiat,
            'fiat_currency' => $currency->iso_code,
            'order_id' => (string) $id_order,
            'description' => Tools::substr(sprintf($m->t('description'), $order->reference, Configuration::get('PS_SHOP_NAME')), 0, 200),
            'return_url' => $this->context->link->getModuleLink($m->name, 'callback', ['id_order' => $id_order, 'key' => $customer->secure_key], true),
            'expires_in' => max(5, min(1440, (int) Configuration::get('PARNIANPAY_EXPIRES') ?: 30)) * 60,
        ];
        $idem = 'ps-' . $id_order . '-' . Tools::substr(md5($fiat_key . '|' . ($row ? $row['invoice_id'] : '')), 0, 16);
        $inv = $client->createInvoice($body, $idem);

        if ($inv === null) {
            $m->log('create invoice failed for order ' . $id_order . ': ' . $client->last_code . ' ' . $client->last_error, true);
            $unavailable = in_array($client->last_code, ['merchant_not_approved', 'rate_not_set'], true);
            if ($unavailable) {
                $m->clearStatus();
            }
            $order->setCurrentState((int) Configuration::get('PS_OS_ERROR'));
            $this->errors[] = $m->t($unavailable ? 'unavailable' : 'start_failed');
            $this->redirectWithNotifications($this->context->link->getPageLink('order', true));
        }

        $m->saveInvoiceRow($id_order, (int) $cart->id, $inv, $fiat_key);
        $m->addPrivateNote($order, sprintf($m->t('c_created'), $inv['amount'], $inv['id']));
        $m->log('invoice ' . $inv['id'] . ' for order ' . $id_order . ' amount ' . $inv['amount']);
        Tools::redirect($inv['pay_url']);
    }
}
