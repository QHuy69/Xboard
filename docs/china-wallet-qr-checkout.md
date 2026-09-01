# China wallet QR checkout (frontend-ready)

This checkout is intentionally a UI preview until a real acquiring provider is selected and merchant credentials are available. It supports exactly two customer-facing methods:

- `wechatpay` — WeChat Pay Native QR
- `alipay` — Alipay transaction QR

No provider credentials, signing keys, payable QR values, callbacks, or payment-success mutations belong in the frontend.

## Current preview

In `local` and `testing` environments:

```text
/_preview/payment/china-wallets?lang=zh-CN&wallet=wechatpay&amount=88.00
/_preview/payment/china-wallets?lang=zh-CN&wallet=alipay&amount=88.00
```

Production does not register this preview route. The QR-like graphic is deliberately non-payable and carries a visible demo badge.

## Future backend contract

When a provider is chosen, render `payment.china-wallet-checkout` with `previewMode=false`, a signed order context and a same-origin `createEndpoint`. The page will send:

```json
{
  "trade_no": "merchant order reference",
  "wallet": "wechatpay|alipay"
}
```

The same-origin backend endpoint should validate order ownership, amount, currency, expiry, gateway availability and idempotency before creating one provider transaction. Return:

```json
{
  "qr_image_url": "/payment/china/qr/<opaque-token>.png",
  "status_url": "/payment/china/status/<opaque-token>",
  "expires_at": 1788200000
}
```

Both returned URLs are deliberately required to be same-origin. The QR endpoint should proxy or render the provider payload server-side; never expose merchant keys or accept an arbitrary image URL from the browser.

The status endpoint should return one of `pending`, `paid`, `cancelled`, or `expired`. Only a server-verified `paid` result may activate the order and trigger the frontend return to the order page. Webhooks and active provider queries must be authenticated and idempotent.

## Provider notes

- WeChat Pay Native requires the merchant backend to call the Native transaction API. It returns a short-lived `code_url`; the merchant frontend converts that value into a QR image. WeChat sends a payment notification, and the backend must query the order when notification delivery is uncertain. Official guide: <https://pay.wechatpay.cn/doc/v3/merchant/4012791877>
- WeChat's official development guide says the `code_url` is not a clickable checkout URL and is intended to be encoded as a QR. The current merchant API documentation states a two-hour `code_url` lifetime, but the UI must use the actual provider expiry returned for each order. Official guide: <https://pay.wechatpay.cn/doc/v3/partner/4012076269>
- Alipay transaction QR integration is also server-to-server: the merchant system pre-creates the transaction, displays the returned QR, then implements query, cancel/refund and asynchronous result handling. Official guide: <https://docs.antom.com/ac/transactionqrcode/integration>
- Antom's current wallet documentation can return QR content through `orderCodeForm.codeDetails.codeValue` for AlipayCN. Provider selection and final endpoint shape depend on the merchant/acquirer contract. Official guide: <https://docs.antom.com/ac/marketplace/Non-card_payments>

## Provider research and prepared adapter boundary

The source now contains a provider-neutral backend boundary in
`App\Contracts\ChinaWalletGateway`. Every future driver must normalize its
create/query/webhook/refund flow into the same CNY-only value objects under
`App\Payments\ChinaWallet`. The catalog in `config/china-wallet-providers.php`
records provider method identifiers and response locations without enabling
any gateway or storing credentials.

| Candidate | WeChat Pay | Alipay | Fit for the current QR-only UI | Important constraint |
| --- | --- | --- | --- | --- |
| Direct merchant APIs | Native `code_url` QR | F2F `qr_code` | Best | Requires two approved direct merchant setups and separate signing material. |
| Stripe PaymentIntents | Web QR action | Redirect action | Mixed | A Stripe account must be available for the merchant's legal entity and each method must be enabled. Vietnam is not listed on Stripe's current global payments availability page. |
| Adyen Checkout API | `wechatpayQR` action | Redirect action | Mixed | Live onboarding and payment-method approval are required. API-only webhooks must be HMAC verified. |
| Antom | `WECHATPAY` method identifier | `ALIPAY_CN` method identifier | Contract-dependent | Current public capability tables list specific acquiring entities and merchant-entity locations; eligibility must be confirmed in writing before implementation. |
| 2C2P | `WCQR` QR channel | `ALQR` QR channel | Strong technical fit | Payment Token and responses use HS256 JWT; CNY/channel availability for a Vietnam merchant must be confirmed by 2C2P Sales. |

Official references used for the base:

- WeChat Native create/query/callback flow: <https://pay.wechatpay.cn/doc/v3/merchant/4012791891>
- WeChat callback signature and AES-256-GCM resource decryption: <https://pay.wechatpay.cn/doc/v3/merchant/4012071382>
- Stripe WeChat QR response fields: <https://docs.stripe.com/api/payment_intents/object>
- Stripe Alipay redirect and `payment_intent.succeeded`: <https://docs.stripe.com/payments/alipay/accept-a-payment>
- Adyen API-only QR action: <https://docs.adyen.com/online-payments/build-your-integration/advanced-flow/?integration=API+only&platform=Web>
- Adyen webhook HMAC verification: <https://docs.adyen.com/development-resources/webhooks/secure-webhooks/verify-hmac-signatures/>
- Antom payment-method identifiers: <https://docs.antom.com/ac/pm/enumeration_values>
- Antom request/response/notification signature verification: <https://docs.antom.com/ac/ams/digital_signature>
- 2C2P QR channels and Direct API response: <https://developer.2c2p.com/docs/direct-api-method-qr-payment>
- 2C2P Payment Token and HS256 JWT flow: <https://developer.2c2p.com/docs/api-payment-token>

The adapter boundary deliberately accepts the untouched webhook body together
with headers. Drivers must authenticate that raw message before reading order,
amount, currency, or status. A verified callback still cannot credit an order
until the persisted provider reference, merchant trade number, CNY amount and
current pending state all match.

The catalog also lists the future credential names and fixed provider hosts so
the eventual admin form can render password/key fields without accepting an
arbitrary API URL. Secrets must remain encrypted/redacted per payment record;
they must never be inherited globally across multiple payment methods.

Among aggregator candidates, 2C2P is the closest match to the requested
same-site QR experience because its current channel catalog exposes both
Alipay QR and WeChat QR. Its public documentation also states that currency
availability depends on merchant country, so this remains a technical candidate
rather than proof that a Vietnam VPN merchant will be approved for CNY.

## Go-live checklist

1. Choose the acquiring model (direct merchant, service provider/sub-merchant, or Antom/acquirer).
2. Confirm Mainland China merchant eligibility, settlement currency, fees, refunds and reconciliation.
3. Store credentials only in backend secret storage; sign and verify every provider message.
4. Enforce one active provider transaction per order/wallet attempt and persist the provider reference before network calls.
5. Verify amount and currency from the database, not request payloads or callback text.
6. Add authenticated webhook endpoints, replay protection, active status queries and reconciliation tooling.
7. Generate/proxy QR images on the same origin, apply short cache lifetimes, and never cache status responses.
8. Test sandbox, expiry, cancellation, duplicate callbacks, delayed callbacks, partial outages and successful automatic return before enabling the payment method.
9. Confirm the provider accepts the VPN/proxy business category in writing; technical API support alone is not merchant approval.
