<?php

namespace App\Payments\ChinaWallet;

enum ChinaWallet: string
{
    case WECHAT_PAY = 'wechatpay';
    case ALIPAY = 'alipay';
}
