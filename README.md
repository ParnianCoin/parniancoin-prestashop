# Parnian Pay for PrestaShop

Accept **ParnianCoin (PARC)** payments in your PrestaShop store. Payments go **directly from the buyer's wallet to your own PARC account**. The gateway never holds your funds or keys.

Supports **PrestaShop 1.7.7 – 8.x / 9.x**. Module name: `parnianpay`.

[فارسی](README.fa.md) · [Website](https://pay.parniancoin.com) · [API docs](https://pay.parniancoin.com/docs) · [Download](../../releases/latest)

---

## Features

- **Non-custodial**: funds go straight to the merchant's PARC account; no third party holds your money.
- **Price in your own currency**: the order total is converted to PARC with the rate you set in your merchant dashboard (IRT, IRR, USD and more), per cart currency.
- **Hosted payment page**: buyers pay on `pay.parniancoin.com` with a QR code or one-click web wallet payment. Private keys and passwords are never entered on your store.
- **Server-side verification**: signed webhooks (HMAC-SHA256), plus a re-check of the invoice when the buyer returns.
- **Own order states**: *Awaiting ParnianCoin payment* and *ParnianCoin payment needs review* (partial / late payments).
- Transaction ID saved on the order payment; invoice details shown on the admin order page.
- Payment option is **hidden automatically** when the gateway is inactive or the cart currency has no rate.
- **Multilingual**: English, Persian and Arabic.

## How it works

```
Buyer ──► Your store ──(create invoice, API key)──► pay.parniancoin.com
                                                        │
Buyer ◄──────────── redirected to payment page ◄────────┘
  │
  └──► pays from own wallet ──► ParnianCoin blockchain ──► your PARC account
                                                        │
Your store ◄──── signed webhook + verify ◄──────────────┘  → order: Payment accepted
```

## Requirements

- PrestaShop 1.7.7 – 8.x / 9.x
- PHP 7.2 or later with the `curl` and `json` extensions
- HTTPS on your store (`http://localhost` is allowed for testing)
- An **approved** merchant account on [pay.parniancoin.com](https://pay.parniancoin.com)

## 1. Get your merchant account

1. Go to [pay.parniancoin.com](https://pay.parniancoin.com) and sign in with **Google** or **Microsoft**.
2. Enter your store details: store name, **website domain**, and your **PARC account number** (where payments will be received).
3. Add a **conversion rate** for each store currency (for example, how many IRT equal 1 PARC).
4. Wait for administrator approval. Your account cannot take payments until it is approved.
5. After approval, copy your **API key** and **Webhook signing secret** from the dashboard.

> **Note:** Any change to your profile, PARC account or website sends your account back for review. Payments are paused until it is approved again.

## 2. Install

1. Download `parnian_pay-PrestaShop.zip` from the [latest release](../../releases/latest).
2. In the back office go to **Modules → Module Manager → Upload a module** and drop the zip file.
3. Click **Configure** when the installation finishes.

> ⚠️ Do **not** use GitHub's green **Code → Download ZIP** button. That archive contains a folder named `parniancoin-prestashop-main`, and PrestaShop requires the module folder to be named exactly `parnianpay`. Always use the zip from Releases.

**Manually:** copy the repository contents into `modules/parnianpay/` on your server (the folder name must be `parnianpay`), then install it from **Modules → Module Manager**.

## 3. Configure

In **Modules → Module Manager**, find **Parnian Pay** and click **Configure**:

| Setting | Description |
|---|---|
| API key | From your merchant dashboard |
| Webhook signing secret | From your merchant dashboard |

Save the settings. The page shows a **connection report**. Then copy the **Webhook URL** shown on the configuration page into your Parnian Pay dashboard.

Finally, check **Payment → Preferences** to make sure the module is allowed for your store's currencies, countries and customer groups.

> The conversion rate is **not** set in the module. It is managed per currency in your merchant dashboard, so all your stores and plugins use the same rate.

## Payment page and your domain

For your buyers' safety, the payment page only opens when the buyer arrives from the **website domain approved in your merchant account**. If your store moves to a new domain, update it in the dashboard first (this triggers a new review).

## Troubleshooting

- **Payment method does not appear at checkout**: check **Payment → Preferences** (currency, country and group restrictions), make sure the cart currency has a rate in the dashboard, and your merchant account is approved.
- **"Module folder name is invalid" on upload**: you used GitHub's Download ZIP. Use `parnian_pay-PrestaShop.zip` from Releases.
- **Changes not visible after an update**: clear the cache in **Advanced Parameters → Performance**.
- **Payment page shows an access error**: the buyer did not come from your approved domain, or your account is under review.
- **Order not confirmed after paying**: check that your site is reachable over HTTPS, that the webhook URL and secret match the dashboard, and that no firewall blocks incoming requests from the gateway.

## Repository layout

```
parnianpay.php     Main module class
controllers/       Front controllers (redirect, callback, webhook)
lib/               Gateway API client
views/             Templates and assets
translations/      fa, ar (English is built in)
logo.png           Module icon
```

## Security

- Never share your API key or webhook signing secret, and never commit them to a repository.
- Buyers must never be asked for a private key or wallet password on your store.
- To report a vulnerability, see [SECURITY.md](SECURITY.md). Please do not open a public issue.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

Released under the [MIT License](LICENSE).
