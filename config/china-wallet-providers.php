<?php

return [
    'currency' => 'CNY',
    'wallets' => ['wechatpay', 'alipay'],
    'drivers' => [
        'direct' => [
            'label' => 'Direct merchant APIs',
            'checkout_fit' => 'qr',
            'credentials' => [
                'wechat_app_id',
                'wechat_merchant_id',
                'wechat_merchant_certificate_serial',
                'wechat_merchant_private_key',
                'wechat_api_v3_key',
                'wechat_platform_public_key_id',
                'wechat_platform_public_key',
                'alipay_app_id',
                'alipay_merchant_private_key',
                'alipay_public_key',
            ],
            'api_hosts' => ['api.mch.weixin.qq.com', 'openapi.alipay.com'],
            'wallets' => [
                'wechatpay' => [
                    'create_operation' => 'POST /v3/pay/transactions/native',
                    'provider_method' => 'NATIVE',
                    'qr_field' => 'code_url',
                    'success_status' => 'SUCCESS',
                    'signature' => 'WECHATPAY2-SHA256-RSA2048 + APIv3 AES-256-GCM',
                ],
                'alipay' => [
                    'create_operation' => 'alipay.trade.precreate',
                    'provider_method' => 'F2F',
                    'qr_field' => 'qr_code',
                    'success_status' => 'TRADE_SUCCESS',
                    'signature' => 'RSA2',
                ],
            ],
        ],
        'stripe' => [
            'label' => 'Stripe PaymentIntents',
            'checkout_fit' => 'mixed',
            'credentials' => ['secret_key', 'webhook_secret'],
            'api_hosts' => ['api.stripe.com'],
            'wallets' => [
                'wechatpay' => [
                    'provider_method' => 'wechat_pay',
                    'action' => 'next_action.wechat_pay_display_qr_code',
                    'success_status' => 'succeeded',
                ],
                'alipay' => [
                    'provider_method' => 'alipay',
                    'action' => 'next_action.alipay_handle_redirect',
                    'success_status' => 'succeeded',
                ],
            ],
        ],
        'adyen' => [
            'label' => 'Adyen Checkout API',
            'checkout_fit' => 'mixed',
            'credentials' => ['api_key', 'merchant_account', 'hmac_key', 'live_url_prefix'],
            'api_hosts' => ['checkout-test.adyen.com'],
            'wallets' => [
                'wechatpay' => [
                    'provider_method' => 'wechatpayQR',
                    'action' => 'action.qrCodeData',
                    'success_status' => 'Authorised',
                ],
                'alipay' => [
                    'provider_method' => 'alipay',
                    'action' => 'redirect',
                    'success_status' => 'Authorised',
                ],
            ],
        ],
        'antom' => [
            'label' => 'Antom payment APIs',
            'checkout_fit' => 'contract-dependent',
            'credentials' => ['client_id', 'merchant_private_key', 'antom_public_key', 'api_region'],
            'api_hosts' => ['open-na-global.alipay.com'],
            'wallets' => [
                'wechatpay' => [
                    'provider_method' => 'WECHATPAY',
                    'action' => 'provider response action',
                    'success_status' => 'SUCCESS',
                ],
                'alipay' => [
                    'provider_method' => 'ALIPAY_CN',
                    'action' => 'provider response action',
                    'success_status' => 'SUCCESS',
                ],
            ],
        ],
        '2c2p' => [
            'label' => '2C2P Payment Gateway',
            'checkout_fit' => 'qr',
            'credentials' => ['merchant_id', 'secret_key'],
            'api_hosts' => ['sandbox-pgw.2c2p.com', 'pgw.2c2p.com'],
            'wallets' => [
                'wechatpay' => [
                    'provider_method' => 'WCQR',
                    'action' => 'Do Payment response data QR image',
                    'success_status' => '0000',
                ],
                'alipay' => [
                    'provider_method' => 'ALQR',
                    'action' => 'Do Payment response data QR image',
                    'success_status' => '0000',
                ],
            ],
        ],
    ],
];
