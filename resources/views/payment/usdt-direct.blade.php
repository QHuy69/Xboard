@php
    $isVi = $locale === 'vi-VN';
    $isZh = $locale === 'zh-CN';
    $isTw = $locale === 'zh-TW';
    $isJa = $locale === 'ja-JP';
    $isKo = $locale === 'ko-KR';
    $isFa = $locale === 'fa-IR';
    $isRu = $locale === 'ru-RU';
    $text = function (string $vi, string $en, string $zh, string $tw, string $ja, string $ko, string $fa, string $ru) use ($isVi, $isZh, $isTw, $isJa, $isKo, $isFa, $isRu): string {
        return match (true) {
            $isVi => $vi,
            $isZh => $zh,
            $isTw => $tw,
            $isJa => $ja,
            $isKo => $ko,
            $isFa => $fa,
            $isRu => $ru,
            default => $en,
        };
    };
    $initialStatusText = match ($initialStatus) {
        'paid' => $text('Thanh toán thành công. Đang quay lại đơn hàng...', 'Payment successful. Returning to your order...', '支付成功，正在返回订单...', '付款成功，正在返回訂單...', '支払いが完了しました。注文に戻ります...', '결제가 완료되었습니다. 주문으로 돌아갑니다...', 'پرداخت موفق بود؛ در حال بازگشت به سفارش...', 'Оплата успешна. Возвращаемся к заказу...'),
        'confirming' => $text('Đã thấy giao dịch. Đang chờ xác nhận an toàn...', 'Transfer detected. Waiting for secure confirmation...', '已检测到转账，正在等待安全确认...', '已偵測到轉帳，正在等待安全確認...', '送金を検出しました。安全な承認を待っています...', '송금을 감지했습니다. 안전한 확인을 기다리는 중...', 'تراکنش دیده شد؛ در انتظار تأیید امن...', 'Перевод обнаружен. Ожидаем безопасного подтверждения...'),
        'manual_review' => $text('Giao dịch cần được kiểm tra thủ công. Vui lòng giữ mã đơn hàng.', 'The transfer needs manual review. Keep your order number.', '该转账需要人工审核，请保留订单号。', '此轉帳需要人工審核，請保留訂單號。', '送金は手動確認が必要です。注文番号を保管してください。', '송금에 수동 검토가 필요합니다. 주문 번호를 보관하세요.', 'تراکنش نیاز به بررسی دستی دارد؛ شماره سفارش را نگه دارید.', 'Перевод требует ручной проверки. Сохраните номер заказа.'),
        'expired' => $text('Hóa đơn đã hết hạn. Không gửi thêm tiền.', 'This invoice has expired. Do not send funds.', '账单已过期，请勿继续转账。', '帳單已過期，請勿繼續轉帳。', '請求は期限切れです。送金しないでください。', '인보이스가 만료되었습니다. 송금하지 마세요.', 'صورتحساب منقضی شده است؛ وجه ارسال نکنید.', 'Счёт истёк. Не отправляйте средства.'),
        'cancelled' => $text('Đơn hàng đã bị hủy.', 'This order was cancelled.', '订单已取消。', '訂單已取消。', '注文はキャンセルされました。', '주문이 취소되었습니다.', 'سفارش لغو شده است.', 'Заказ отменён.'),
        default => $text('Đang chờ bạn chuyển đúng số USDT.', 'Waiting for the exact USDT transfer.', '正在等待准确金额的 USDT 转账。', '正在等待正確金額的 USDT 轉帳。', '正確なUSDT送金を待っています。', '정확한 USDT 송금을 기다리고 있습니다.', 'در انتظار انتقال مبلغ دقیق USDT.', 'Ожидаем перевод точной суммы USDT.'),
    };
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'fa-IR' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>{{ $text('Thanh toán USDT', 'USDT payment', 'USDT 支付', 'USDT 付款', 'USDT支払い', 'USDT 결제', 'پرداخت USDT', 'Оплата USDT') }} · ZaoGuang Service</title>
    <link rel="stylesheet" href="/payment/usdt-direct.css?v=1">
    <script src="/payment/usdt-direct.js?v=1" defer></script>
</head>
<body>
<main
    class="usdt-shell"
    data-usdt-checkout
    data-status-url="{{ $statusUrl }}"
    data-return-url="{{ $returnUrl }}"
    data-expires-at="{{ (int) $expiresAt }}"
    data-initial-status="{{ $initialStatus }}"
    data-label-copied="{{ $text('Đã sao chép', 'Copied', '已复制', '已複製', 'コピーしました', '복사됨', 'کپی شد', 'Скопировано') }}"
    data-label-copy-failed="{{ $text('Không thể sao chép. Hãy nhấn giữ để sao chép.', 'Could not copy. Press and hold to copy.', '无法复制，请长按复制。', '無法複製，請長按複製。', 'コピーできません。長押ししてコピーしてください。', '복사할 수 없습니다. 길게 눌러 복사하세요.', 'کپی نشد؛ برای کپی لمس طولانی کنید.', 'Не удалось скопировать. Нажмите и удерживайте.') }}"
    data-label-pending="{{ $text('Đang chờ bạn chuyển đúng số USDT.', 'Waiting for the exact USDT transfer.', '正在等待准确金额的 USDT 转账。', '正在等待正確金額的 USDT 轉帳。', '正確なUSDT送金を待っています。', '정확한 USDT 송금을 기다리고 있습니다.', 'در انتظار انتقال مبلغ دقیق USDT.', 'Ожидаем перевод точной суммы USDT.') }}"
    data-label-checking="{{ $text('Đang kiểm tra blockchain TRON...', 'Checking the TRON blockchain...', '正在检查 TRON 区块链...', '正在檢查 TRON 區塊鏈...', 'TRONブロックチェーンを確認しています...', 'TRON 블록체인을 확인하는 중...', 'در حال بررسی بلاکچین TRON...', 'Проверяем блокчейн TRON...') }}"
    data-label-confirming="{{ $text('Đã thấy giao dịch. Đang chờ xác nhận an toàn...', 'Transfer detected. Waiting for secure confirmation...', '已检测到转账，正在等待安全确认...', '已偵測到轉帳，正在等待安全確認...', '送金を検出しました。安全な承認を待っています...', '송금을 감지했습니다. 안전한 확인을 기다리는 중...', 'تراکنش دیده شد؛ در انتظار تأیید امن...', 'Перевод обнаружен. Ожидаем безопасного подтверждения...') }}"
    data-label-paid="{{ $text('Thanh toán thành công. Đang quay lại đơn hàng...', 'Payment successful. Returning to your order...', '支付成功，正在返回订单...', '付款成功，正在返回訂單...', '支払いが完了しました。注文に戻ります...', '결제가 완료되었습니다. 주문으로 돌아갑니다...', 'پرداخت موفق بود؛ در حال بازگشت به سفارش...', 'Оплата успешна. Возвращаемся к заказу...') }}"
    data-label-expired="{{ $text('Hóa đơn đã hết hạn. Không gửi thêm tiền.', 'This invoice has expired. Do not send funds.', '账单已过期，请勿继续转账。', '帳單已過期，請勿繼續轉帳。', '請求は期限切れです。送金しないでください。', '인보이스가 만료되었습니다. 송금하지 마세요.', 'صورتحساب منقضی شده است؛ وجه ارسال نکنید.', 'Счёт истёк. Не отправляйте средства.') }}"
    data-label-review="{{ $text('Giao dịch cần được kiểm tra thủ công. Vui lòng giữ mã đơn hàng.', 'The transfer needs manual review. Keep your order number.', '该转账需要人工审核，请保留订单号。', '此轉帳需要人工審核，請保留訂單號。', '送金は手動確認が必要です。注文番号を保管してください。', '송금에 수동 검토가 필요합니다. 주문 번호를 보관하세요.', 'تراکنش نیاز به بررسی دستی دارد؛ شماره سفارش را نگه دارید.', 'Перевод требует ручной проверки. Сохраните номер заказа.') }}"
    data-label-cancelled="{{ $text('Đơn hàng đã bị hủy.', 'This order was cancelled.', '订单已取消。', '訂單已取消。', '注文はキャンセルされました。', '주문이 취소되었습니다.', 'سفارش لغو شده است.', 'Заказ отменён.') }}"
    data-label-network-error="{{ $text('Chưa thể cập nhật trạng thái. Hệ thống sẽ tự thử lại.', 'Could not refresh yet. The system will retry automatically.', '暂时无法更新状态，系统将自动重试。', '暫時無法更新狀態，系統將自動重試。', '状態を更新できません。自動的に再試行します。', '상태를 갱신할 수 없습니다. 자동으로 다시 시도합니다.', 'وضعیت به‌روزرسانی نشد؛ سامانه دوباره تلاش می‌کند.', 'Не удалось обновить статус. Система повторит попытку.') }}"
    data-label-remaining="{{ $text('Thời gian còn lại', 'Time remaining', '剩余时间', '剩餘時間', '残り時間', '남은 시간', 'زمان باقی‌مانده', 'Осталось времени') }}"
>
    <section class="usdt-card" aria-labelledby="checkout-title">
        <header class="usdt-topbar">
            <a class="usdt-brand" href="{{ $returnUrl }}" aria-label="ZaoGuang Service">
                <span class="usdt-brand-mark" aria-hidden="true">ZG</span>
                <span>ZaoGuang Service</span>
            </a>
            <span class="usdt-secure"><span aria-hidden="true">●</span>{{ $text('Thanh toán bảo mật', 'Secure checkout', '安全支付', '安全付款', '安全な決済', '안전 결제', 'پرداخت امن', 'Безопасная оплата') }}</span>
        </header>

        <div class="usdt-layout">
            <section class="usdt-details">
                <p class="usdt-eyebrow">USDT · TRC20</p>
                <h1 id="checkout-title">{{ $text('Thanh toán bằng USDT', 'Pay with USDT', '使用 USDT 支付', '使用 USDT 付款', 'USDTで支払う', 'USDT로 결제', 'پرداخت با USDT', 'Оплата через USDT') }}</h1>
                <p class="usdt-lead">{{ $text('Chuyển đúng số tiền trên mạng TRON. Hệ thống sẽ tự xác nhận và kích hoạt đơn hàng.', 'Send the exact amount on the TRON network. Your order will be confirmed automatically.', '请通过 TRON 网络转账准确金额，系统将自动确认并激活订单。', '請透過 TRON 網路轉帳正確金額，系統將自動確認並啟用訂單。', 'TRONネットワークで正確な金額を送金してください。注文は自動確認されます。', 'TRON 네트워크에서 정확한 금액을 보내세요. 주문은 자동으로 확인됩니다.', 'مبلغ دقیق را در شبکه TRON ارسال کنید؛ سفارش خودکار تأیید می‌شود.', 'Отправьте точную сумму в сети TRON. Заказ подтвердится автоматически.') }}</p>

                <dl class="usdt-summary">
                    <div><dt>{{ $text('Đơn hàng', 'Order', '订单', '訂單', '注文', '주문', 'سفارش', 'Заказ') }}</dt><dd><bdi>{{ $order->trade_no }}</bdi></dd></div>
                    @if($order->plan)
                        <div><dt>{{ $text('Gói đăng ký', 'Subscription', '订阅套餐', '訂閱方案', 'プラン', '구독 플랜', 'اشتراک', 'Подписка') }}</dt><dd>{{ $order->plan->name }}</dd></div>
                    @endif
                    <div><dt>{{ $text('Mạng lưới', 'Network', '网络', '網路', 'ネットワーク', '네트워크', 'شبکه', 'Сеть') }}</dt><dd><span class="usdt-network">TRON · TRC20</span></dd></div>
                    <div><dt>{{ $text('Token', 'Token', '代币', '代幣', 'トークン', '토큰', 'توکن', 'Токен') }}</dt><dd>USDT</dd></div>
                </dl>

                <aside class="usdt-warning" role="note">
                    <strong>{{ $text('Chỉ gửi USDT TRC20', 'Send USDT TRC20 only', '仅发送 USDT TRC20', '僅傳送 USDT TRC20', 'USDT TRC20のみ送金', 'USDT TRC20만 전송', 'فقط USDT TRC20 ارسال کنید', 'Отправляйте только USDT TRC20') }}</strong>
                    <span>{{ $text('Gửi token khác hoặc dùng sai mạng có thể làm mất tiền và không thể tự động khôi phục.', 'Other tokens or networks may result in permanent loss and cannot be recovered automatically.', '发送其他代币或使用错误网络可能导致永久损失，且无法自动恢复。', '傳送其他代幣或使用錯誤網路可能造成永久損失，且無法自動復原。', '他のトークンやネットワークを使うと資金を失い、自動復旧できない場合があります。', '다른 토큰이나 네트워크를 사용하면 자금을 영구적으로 잃을 수 있으며 자동 복구되지 않습니다.', 'ارسال توکن یا شبکه دیگر ممکن است باعث از دست رفتن دائمی وجه شود.', 'Другой токен или сеть могут привести к безвозвратной потере средств.') }}</span>
                </aside>
            </section>

            <section class="usdt-payment" aria-label="{{ $text('Thông tin chuyển USDT', 'USDT transfer details', 'USDT 转账信息', 'USDT 轉帳資訊', 'USDT送金情報', 'USDT 송금 정보', 'اطلاعات انتقال USDT', 'Данные перевода USDT') }}">
                <div class="usdt-qr-wrap">
                    <img src="{{ $qrUrl }}" alt="{{ $text('Mã QR địa chỉ nhận USDT', 'QR code for the USDT receiving address', 'USDT 收款地址二维码', 'USDT 收款地址 QR 碼', 'USDT受取アドレスのQRコード', 'USDT 수신 주소 QR 코드', 'کد QR نشانی دریافت USDT', 'QR-код адреса получения USDT') }}" width="384" height="384">
                </div>

                <div class="usdt-field usdt-amount-field">
                    <span class="usdt-field-label">{{ $text('Số tiền chính xác', 'Exact amount', '准确金额', '正確金額', '正確な金額', '정확한 금액', 'مبلغ دقیق', 'Точная сумма') }}</span>
                    <div class="usdt-value-line">
                        <bdi class="usdt-amount" data-payment-amount>{{ $amountUsdt }}</bdi><span class="usdt-unit">USDT</span>
                        <button class="usdt-copy" type="button" data-copy-value="{{ $amountUsdt }}" aria-label="{{ $text('Sao chép số tiền', 'Copy amount', '复制金额', '複製金額', '金額をコピー', '금액 복사', 'کپی مبلغ', 'Копировать сумму') }}">{{ $text('Sao chép', 'Copy', '复制', '複製', 'コピー', '복사', 'کپی', 'Копировать') }}</button>
                    </div>
                    <small>{{ $text('Không làm tròn hoặc thay đổi bất kỳ chữ số nào.', 'Do not round or change any digit.', '请勿四舍五入或更改任何数字。', '請勿四捨五入或更改任何數字。', '丸めたり数字を変更したりしないでください。', '반올림하거나 숫자를 변경하지 마세요.', 'هیچ رقمی را گرد یا تغییر ندهید.', 'Не округляйте и не изменяйте цифры.') }}</small>
                </div>

                <div class="usdt-field">
                    <span class="usdt-field-label">{{ $text('Địa chỉ nhận', 'Receiving address', '收款地址', '收款地址', '受取アドレス', '수신 주소', 'نشانی دریافت', 'Адрес получения') }}</span>
                    <div class="usdt-address-line">
                        <bdi class="usdt-address" data-payment-address>{{ $receivingAddress }}</bdi>
                        <button class="usdt-copy" type="button" data-copy-value="{{ $receivingAddress }}" aria-label="{{ $text('Sao chép địa chỉ', 'Copy address', '复制地址', '複製地址', 'アドレスをコピー', '주소 복사', 'کپی نشانی', 'Копировать адрес') }}">{{ $text('Sao chép', 'Copy', '复制', '複製', 'コピー', '복사', 'کپی', 'Копировать') }}</button>
                    </div>
                </div>

                <div class="usdt-countdown" data-countdown></div>
                <div class="usdt-status" data-payment-status data-state="{{ $initialStatus }}" role="status" aria-live="polite">
                    <span class="usdt-status-dot" aria-hidden="true"></span>
                    <span data-status-text>{{ $initialStatusText }}</span>
                </div>

                <div class="usdt-actions">
                    <a class="usdt-button usdt-button-secondary" href="{{ $returnUrl }}">{{ $text('Quay lại đơn hàng', 'Back to orders', '返回订单', '返回訂單', '注文に戻る', '주문으로 돌아가기', 'بازگشت به سفارش', 'Назад к заказу') }}</a>
                    <button class="usdt-button usdt-button-primary" type="button" data-refresh-status>{{ $text('Kiểm tra thanh toán', 'Check payment', '检查支付状态', '檢查付款', '支払いを確認', '결제 확인', 'بررسی پرداخت', 'Проверить оплату') }}</button>
                </div>
                <p class="usdt-help">{{ $text('Trang này tự cập nhật. Bạn có thể giữ trang mở trong lúc thanh toán.', 'This page refreshes automatically. You may keep it open while paying.', '此页面会自动更新，付款时可以保持页面打开。', '此頁面會自動更新，付款時可保持頁面開啟。', 'このページは自動更新されます。支払い中は開いたままで構いません。', '이 페이지는 자동으로 갱신됩니다. 결제 중 열어 두셔도 됩니다.', 'این صفحه خودکار به‌روزرسانی می‌شود؛ هنگام پرداخت آن را باز نگه دارید.', 'Страница обновляется автоматически; оставьте её открытой во время оплаты.') }}</p>
            </section>
        </div>
    </section>

    <div class="usdt-toast" data-copy-toast role="status" aria-live="polite" hidden></div>
</main>
</body>
</html>
