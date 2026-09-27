<?php
/**
 * The buyer is back from the payment page. The return itself proves nothing: the invoice is re-checked
 * with the gateway, then the buyer sees the order confirmation page (with the payment state).
 */
class ParnianPayCallbackModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        /** @var ParnianPay $m */
        $m = $this->module;
        $id_order = (int) Tools::getValue('id_order');
        $key = (string) Tools::getValue('key');
        $order = new Order($id_order);
        $row = $id_order ? $m->getInvoiceRow($id_order) : null;

        if (!$row || !Validate::isLoadedObject($order) || $order->module !== $m->name || !hash_equals((string) $order->secure_key, $key)) {
            Tools::redirect($this->context->link->getPageLink('index', true));
        }

        $inv = $m->client()->getInvoice($row['invoice_id']);
        if ($inv !== null) {
            $m->sync($id_order, $inv);
        }

        Tools::redirect($this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int) $order->id_cart,
            'id_module' => (int) $m->id,
            'id_order' => $id_order,
            'key' => $order->secure_key,
        ]));
    }
}
