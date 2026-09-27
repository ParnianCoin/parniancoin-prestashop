# ParnianCoin Payment Gateway for PrestaShop

Accept **ParnianCoin (PARC)** payments in your PrestaShop store. Payments go **directly from the buyer's wallet to your own PARC account**. The gateway never holds your funds.

Supports **PrestaShop 8.x**. Module name: `parnianpay`.

[فارسی](README.fa.md) · [Website](https://pay.parniancoin.com) · [Releases](../../releases)

---

## Features

- **Non-custodial**: funds are sent straight to the merchant's PARC account; no third party holds your money.
- **Price in your own currency**: products stay priced in your store currency (IRT, IRR, USD and 20+ more). The gateway converts to PARC using the rate you set in your merchant dashboard.
- **Secure hosted payment page**: buyers pay on `pay.parniancoin.com`. Private keys and passwords are never entered on your store and never reach any server.
- **Two ways to pay**: the ParnianCoin web wallet, or manual payment by QR code / account number from any PARC wallet.
- **Server-to-server verification**: an order is created as paid only after the gateway verifies the transaction on-chain, never on the basis of a browser redirect alone.
- **Signed webhooks** (HMAC + timestamp) for instant order status updates.
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

- PrestaShop 8.0 or later
- PHP with the `curl` and `json` extensions
- HTTPS on your store
- An **approved** merchant account on [pay.parniancoin.com](https://pay.parniancoin.com)

## 1. Get your merchant account

1. Go to [pay.parniancoin.com](https://pay.parniancoin.com) and sign in with **Google** or **Microsoft**.
2. Enter your store details: store name, **website domain**, and your **PARC account number** (where payments will be received).
3. Set your **conversion rate** for your store currency (for example, how many IRT equal 1 PARC).
4. Wait for administrator approval. Your account cannot take payments until it is approved.
5. After approval, copy your **API Key** and **Webhook Secret** from the dashboard.

> **Note:** Any change to your profile, PARC account or website sends your account back for review. Payments are paused until it is approved again.

## 2. Install

**From a release (recommended)**

1. Download `parnianpay.zip` from [Releases](../../releases).
2. In the back office go to **Modules → Module Manager → Upload a module** and drop the zip file.
3. Click **Configure** when the installation finishes.

> ⚠️ Do **not** use GitHub's green **Code → Download ZIP** button. That archive contains a folder named `parniancoin-prestashop-main`, and PrestaShop requires the folder to be named exactly `parnianpay`. Always use the zip from Releases.

**Manually**

Copy the repository contents into `modules/parnianpay/` on your server (the folder name must be `parnianpay`), then install it from **Modules → Module Manager**.

## 3. Configure

In **Modules → Module Manager**, find **ParnianCoin** and click **Configure**:

| Setting | Description |
|---|---|
| API Key | From your merchant dashboard |
| Webhook Secret | From your merchant dashboard |

Save the settings. The **webhook URL** shown on the configuration page must match the one registered in your merchant dashboard.

Then check **Payment → Preferences** to make sure the module is allowed for your store's currencies, countries and customer groups.

> The conversion rate is **not** set in the module. It is managed per currency in your merchant dashboard, so all your stores and plugins use the same rate.

## Payment page and your domain

For your buyers' safety, the payment page only opens when the buyer arrives from the **website domain approved in your merchant account**. If your store moves to a new domain, update it in the dashboard first (this triggers a new review).

## Troubleshooting

- **Payment method does not appear at checkout**: check **Payment → Preferences** (currency, country and group restrictions), make sure your store currency has a rate in the dashboard, and your merchant account is approved.
- **"Module folder name is invalid" on upload**: you used GitHub's Download ZIP. Use `parnianpay.zip` from Releases.
- **Changes not visible after an update**: clear the cache in **Advanced Parameters → Performance**.
- **Payment page shows an access error**: the buyer did not come from your approved domain, or your account is under review.
- **Order not confirmed after paying**: check that your site is reachable over HTTPS, that the webhook URL and secret match the dashboard, and that no firewall blocks incoming requests from the gateway.

## Repository layout

```
parnianpay.php     Main module class
controllers/       Front controllers (redirect, return, webhook)
lib/               Gateway API client
views/             Templates and assets
translations/      en, fa, ar
logo.png           Module icon
```

## Security

- Never share your API Key or Webhook Secret, and never commit them to a repository.
- Buyers must never be asked for a private key or wallet password on your store.
- To report a vulnerability, please follow [SECURITY.md](SECURITY.md) instead of opening a public issue.

## License

Released under the [MIT License](LICENSE).
