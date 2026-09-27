<?php
/**
 * POST — signed events from Parnian Pay.
 * Signature: "Parnian-Signature: t=<unix>,v1=HMAC-SHA256(secret, t + '.' + raw_body)".
 * The order is updated from the gateway's current invoice state (re-fetched), not from the event body.
 */
class ParnianPayWebhookModuleFrontController extends ModuleFrontController
{
    public $ssl = false;      // the gateway calls whatever URL the merchant registered
    public $ajax = true;

    public function postProcess()
    {
        /** @var ParnianPay $m */
        $m = $this->module;
        $raw = (string) file_get_contents('php://input');
        $sig = isset($_SERVER['HTTP_PARNIAN_SIGNATURE']) ? (string) $_SERVER['HTTP_PARNIAN_SIGNATURE'] : '';

        if (!ParnianPayClient::verifySignature($raw, $sig, (string) Configuration::get('PARNIANPAY_WEBHOOK_SECRET'))) {
            $m->log('webhook rejected: bad signature', true);
            $this->reply(400, 'bad signature');
        }

        $event = json_decode($raw, true);
        $data = (is_array($event) && isset($event['data']['invoice']) && is_array($event['data']['invoice'])) ? $event['data']['invoice'] : null;
        $id_order = $data && !empty($data['order_id']) ? (int) $data['order_id'] : 0;
        $row = $id_order ? $m->getInvoiceRow($id_order) : null;
        $m->log('webhook ' . (isset($event['type']) ? $event['type'] : '?') . ' invoice ' . (isset($data['id']) ? $data['id'] : '?'));

        if (!$row || $row['invoice_id'] !== (isset($data['id']) ? $data['id'] : '')) {
            $this->reply(200, 'ignored'); // not ours, or a superseded invoice
        }
        $inv = $m->client()->getInvoice($row['invoice_id']);
        if ($inv === null) {
            $m->log('webhook re-check failed', true);
            $this->reply(503, 'retry');
        }
        $m->sync($id_order, $inv);
        $this->reply(200, 'ok');
    }

    private function reply($code, $text)
    {
        http_response_code($code);
        header('Content-Type: text/plain');
        exit($text);
    }
}
