@php
    $locale = $locale ?? 'zh-CN';
    $previewMode = (bool) ($previewMode ?? true);
    $selectedWallet = in_array(($selectedWallet ?? 'wechatpay'), ['wechatpay', 'alipay'], true) ? $selectedWallet : 'wechatpay';
    $amountCny = $amountCny ?? '88.00';
    $tradeNo = $tradeNo ?? 'CN-DEMO-20260901';
    $returnUrl = $returnUrl ?? '/orders';
    $createEndpoint = $createEndpoint ?? '';
    $expiresAt = (int) ($expiresAt ?? 0);
    $csrfToken = $csrfToken ?? '';
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
    $pageTitle = $text('Thanh toán bằng ví Trung Quốc', 'Pay with a China wallet', '中国钱包支付', '中國錢包付款', '中国ウォレットで支払う', '중국 지갑으로 결제', 'پرداخت با کیف پول چین', 'Оплата китайским кошельком');
    $subtitle = $text('Chọn WeChat Pay hoặc Alipay và quét mã QR để thanh toán bằng CNY.', 'Choose WeChat Pay or Alipay and scan the QR code to pay in CNY.', '选择微信支付或支付宝，扫描二维码以人民币付款。', '選擇微信支付或支付寶，掃描 QR 碼以人民幣付款。', 'WeChat Pay または Alipay を選び、QRコードを読み取って人民元で支払います。', 'WeChat Pay 또는 Alipay를 선택하고 QR 코드를 스캔하여 CNY로 결제하세요.', 'WeChat Pay یا Alipay را انتخاب و کد QR را برای پرداخت با یوان اسکن کنید.', 'Выберите WeChat Pay или Alipay и отсканируйте QR-код для оплаты в CNY.');
    $secureLabel = $text('Thanh toán bảo mật', 'Secure checkout', '安全支付', '安全付款', '安全な決済', '안전한 결제', 'پرداخت امن', 'Безопасная оплата');
    $orderLabel = $text('Mã đơn hàng', 'Order number', '订单号', '訂單編號', '注文番号', '주문 번호', 'شماره سفارش', 'Номер заказа');
    $amountLabel = $text('Số tiền cần thanh toán', 'Amount due', '应付金额', '應付金額', 'お支払い金額', '결제 금액', 'مبلغ قابل پرداخت', 'Сумма к оплате');
    $chooseWallet = $text('Chọn ví thanh toán', 'Choose a wallet', '选择支付方式', '選擇付款方式', 'ウォレットを選択', '결제 지갑 선택', 'انتخاب کیف پول', 'Выберите кошелёк');
    $wechatNote = $text('Quét bằng WeChat', 'Scan with WeChat', '使用微信扫一扫', '使用微信掃一掃', 'WeChatでスキャン', 'WeChat으로 스캔', 'اسکن با WeChat', 'Сканировать в WeChat');
    $alipayNote = $text('Quét bằng Alipay', 'Scan with Alipay', '使用支付宝扫一扫', '使用支付寶掃一掃', 'Alipayでスキャン', 'Alipay로 스캔', 'اسکن با Alipay', 'Сканировать в Alipay');
    $wechatInstruction = $text('Mở WeChat, chọn “Quét” và hướng camera vào mã QR. Nếu đang xem trên điện thoại, hãy dùng thiết bị khác để quét.', 'Open WeChat, choose Scan, and point the camera at the QR code. If this page is on your phone, scan it from another device.', '打开微信，点击“扫一扫”并扫描二维码。如果本页显示在手机上，请使用另一台设备扫码。', '開啟微信，點選「掃一掃」並掃描 QR 碼。如果本頁顯示在手機上，請使用另一台裝置掃碼。', 'WeChatの「スキャン」を開いてQRコードを読み取ります。スマートフォンで表示中の場合は、別の端末から読み取ってください。', 'WeChat의 스캔을 열어 QR 코드를 인식하세요. 휴대폰에서 이 페이지를 보고 있다면 다른 기기로 스캔하세요.', 'در WeChat گزینه Scan را باز و کد QR را اسکن کنید. اگر صفحه روی تلفن است، از دستگاه دیگری استفاده کنید.', 'Откройте сканер WeChat и наведите камеру на QR-код. Если страница открыта на телефоне, используйте другое устройство.');
    $alipayInstruction = $text('Mở Alipay, chọn “Quét” và hướng camera vào mã QR. Xác nhận đúng số tiền trước khi thanh toán.', 'Open Alipay, choose Scan, and point the camera at the QR code. Confirm the amount before paying.', '打开支付宝，点击“扫一扫”并扫描二维码。付款前请核对应付金额。', '開啟支付寶，點選「掃一掃」並掃描 QR 碼。付款前請核對應付金額。', 'Alipayの「スキャン」を開いてQRコードを読み取り、支払い前に金額を確認してください。', 'Alipay의 스캔을 열어 QR 코드를 인식하고 결제 전에 금액을 확인하세요.', 'در Alipay گزینه Scan را باز و کد QR را اسکن کنید. پیش از پرداخت مبلغ را بررسی کنید.', 'Откройте сканер Alipay, отсканируйте QR-код и проверьте сумму перед оплатой.');
    $previewMessage = $text('Đây là bản xem trước giao diện. Nhà cung cấp thanh toán chưa được kết nối.', 'This is a UI preview. No payment provider is connected yet.', '这是界面预览，支付服务商尚未接入。', '這是介面預覽，支付服務商尚未接入。', 'これはUIプレビューです。決済事業者はまだ接続されていません。', 'UI 미리보기이며 결제 제공업체는 아직 연결되지 않았습니다.', 'این فقط پیش‌نمایش رابط است و ارائه‌دهنده پرداخت هنوز متصل نیست.', 'Это предварительный просмотр интерфейса; платёжный провайдер ещё не подключён.');
    $previewNote = $text('QR hiện tại không thể thanh toán. Khi có tài khoản nhà cung cấp, backend sẽ tạo QR riêng cho từng đơn và xác minh bằng callback/query chính thức.', 'The current QR cannot accept payment. After a provider is connected, the backend will create one QR per order and verify it using official callbacks and status queries.', '当前二维码不能付款。接入服务商后，后端将为每个订单生成独立二维码，并通过官方回调和查单接口确认结果。', '目前的 QR 碼無法付款。接入服務商後，後端會為每筆訂單產生獨立 QR 碼，並透過官方回呼及查單介面確認結果。', '現在のQRコードでは支払えません。事業者接続後、バックエンドが注文ごとにQRを生成し、公式コールバックと照会APIで確認します。', '현재 QR로는 결제할 수 없습니다. 제공업체 연결 후 백엔드가 주문별 QR을 만들고 공식 콜백과 조회 API로 확인합니다.', 'کد QR فعلی قابل پرداخت نیست. پس از اتصال ارائه‌دهنده، سرور برای هر سفارش QR جداگانه می‌سازد و نتیجه را با callback و query رسمی تأیید می‌کند.', 'Текущий QR-код не принимает оплату. После подключения провайдера сервер создаст отдельный QR для заказа и проверит результат через официальные callback/query API.');
    $createLabel = $text('Tạo mã QR thanh toán', 'Create payment QR', '生成支付二维码', '產生付款 QR 碼', '支払いQRを作成', '결제 QR 만들기', 'ساخت کد QR پرداخت', 'Создать платёжный QR');
    $previewAction = $text('Xem trạng thái demo', 'Preview the flow', '查看演示状态', '查看示範狀態', 'デモ状態を表示', '데모 상태 보기', 'نمایش وضعیت آزمایشی', 'Показать демо-статус');
    $backLabel = $text('Quay lại đơn hàng', 'Back to orders', '返回订单', '返回訂單', '注文に戻る', '주문으로 돌아가기', 'بازگشت به سفارش‌ها', 'Назад к заказам');
    $demoBadge = $text('QR DEMO · KHÔNG THANH TOÁN', 'DEMO QR · NOT PAYABLE', '演示二维码 · 无法付款', '示範 QR 碼 · 無法付款', 'デモQR・支払い不可', '데모 QR · 결제 불가', 'QR آزمایشی · غیرقابل پرداخت', 'ДЕМО QR · ОПЛАТА НЕВОЗМОЖНА');
    $remainingLabel = $text('Thời gian còn lại', 'Time remaining', '剩余时间', '剩餘時間', '残り時間', '남은 시간', 'زمان باقی‌مانده', 'Осталось времени');
    $preparingMessage = $text('Đang tạo mã QR bảo mật...', 'Preparing a secure QR code...', '正在生成安全二维码...', '正在產生安全 QR 碼...', '安全なQRコードを作成中...', '보안 QR 코드를 준비하는 중...', 'در حال ساخت کد QR امن...', 'Создаём безопасный QR-код...');
    $waitingMessage = $text('Đang chờ xác nhận thanh toán...', 'Waiting for payment confirmation...', '正在等待付款确认...', '正在等待付款確認...', '支払い確認を待っています...', '결제 확인을 기다리는 중...', 'در انتظار تأیید پرداخت...', 'Ожидаем подтверждение оплаты...');
    $checkingMessage = $text('Đang kiểm tra trạng thái...', 'Checking payment status...', '正在查询付款状态...', '正在查詢付款狀態...', '支払い状態を確認中...', '결제 상태 확인 중...', 'در حال بررسی وضعیت پرداخت...', 'Проверяем статус платежа...');
    $paidMessage = $text('Thanh toán thành công. Đang quay lại đơn hàng...', 'Payment successful. Returning to the order...', '付款成功，正在返回订单...', '付款成功，正在返回訂單...', '支払い完了。注文に戻ります...', '결제가 완료되었습니다. 주문으로 돌아갑니다...', 'پرداخت موفق بود. در حال بازگشت به سفارش...', 'Оплата успешна. Возвращаемся к заказу...');
    $cancelledMessage = $text('Thanh toán đã bị hủy.', 'Payment was cancelled.', '付款已取消。', '付款已取消。', '支払いはキャンセルされました。', '결제가 취소되었습니다.', 'پرداخت لغو شد.', 'Платёж отменён.');
    $expiredMessage = $text('Mã QR đã hết hạn. Vui lòng tạo mã mới.', 'The QR code has expired. Please create a new one.', '二维码已过期，请重新生成。', 'QR 碼已過期，請重新產生。', 'QRコードの有効期限が切れました。再作成してください。', 'QR 코드가 만료되었습니다. 새로 만들어 주세요.', 'کد QR منقضی شده است. کد جدید بسازید.', 'Срок действия QR-кода истёк. Создайте новый.');
    $failedMessage = $text('Chưa thể tạo mã QR. Vui lòng thử lại.', 'Unable to prepare the QR code. Please try again.', '暂时无法生成二维码，请重试。', '暫時無法產生 QR 碼，請重試。', 'QRコードを作成できません。もう一度お試しください。', 'QR 코드를 만들 수 없습니다. 다시 시도하세요.', 'ساخت کد QR ممکن نشد. دوباره تلاش کنید.', 'Не удалось создать QR-код. Попробуйте ещё раз.');
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'fa-IR' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light">
    <title>{{ $pageTitle }} · ZaoGuang Service</title>
    <link rel="stylesheet" href="/payment/china-wallet-checkout.css?v=1">
</head>
<body>
<main
    class="cn-pay-shell"
    data-china-wallet-checkout
    data-wallet="{{ $selectedWallet }}"
    data-preview="{{ $previewMode ? '1' : '0' }}"
    data-trade-no="{{ $tradeNo }}"
    data-create-endpoint="{{ $createEndpoint }}"
    data-return-url="{{ $returnUrl }}"
    data-expires-at="{{ $expiresAt }}"
    data-csrf-token="{{ $csrfToken }}"
    data-wechat-name="WeChat Pay"
    data-alipay-name="Alipay"
    data-wechat-instruction="{{ $wechatInstruction }}"
    data-alipay-instruction="{{ $alipayInstruction }}"
    data-preview-message="{{ $previewMessage }}"
    data-preparing-message="{{ $preparingMessage }}"
    data-waiting-message="{{ $waitingMessage }}"
    data-checking-message="{{ $checkingMessage }}"
    data-paid-message="{{ $paidMessage }}"
    data-cancelled-message="{{ $cancelledMessage }}"
    data-expired-message="{{ $expiredMessage }}"
    data-failed-message="{{ $failedMessage }}"
    data-remaining-label="{{ $remainingLabel }}"
>
    <article class="cn-pay-card">
        <header class="cn-pay-topbar">
            <div class="cn-pay-brand">
                <span class="cn-pay-brand-mark" aria-hidden="true">ZG</span>
                <span class="cn-pay-brand-name">ZaoGuang Service</span>
            </div>
            <div class="cn-pay-security"><span>{{ $secureLabel }}</span></div>
        </header>

        <div class="cn-pay-grid">
            <section class="cn-pay-details" aria-labelledby="cn-pay-title">
                <p class="cn-pay-eyebrow">CNY · QR PAY</p>
                <h1 id="cn-pay-title" class="cn-pay-title">{{ $pageTitle }}</h1>
                <p class="cn-pay-subtitle">{{ $subtitle }}</p>

                <div class="cn-pay-order">
                    <div class="cn-pay-row">
                        <span class="cn-pay-label">{{ $orderLabel }}</span>
                        <bdi class="cn-pay-value">{{ $tradeNo }}</bdi>
                    </div>
                    <div class="cn-pay-row">
                        <span class="cn-pay-label">{{ $amountLabel }}</span>
                        <bdi class="cn-pay-value cn-pay-amount">¥{{ $amountCny }}</bdi>
                    </div>
                </div>

                <h2 class="cn-pay-section-title">{{ $chooseWallet }}</h2>
                <div class="cn-pay-wallets" role="group" aria-label="{{ $chooseWallet }}">
                    <button class="cn-pay-wallet" type="button" data-wallet="wechatpay" data-wallet-option="wechatpay" aria-pressed="{{ $selectedWallet === 'wechatpay' ? 'true' : 'false' }}">
                        <span class="cn-pay-wallet-mark" aria-hidden="true">微</span>
                        <span class="cn-pay-wallet-copy">
                            <span class="cn-pay-wallet-name">WeChat Pay</span>
                            <span class="cn-pay-wallet-note">{{ $wechatNote }}</span>
                        </span>
                    </button>
                    <button class="cn-pay-wallet" type="button" data-wallet="alipay" data-wallet-option="alipay" aria-pressed="{{ $selectedWallet === 'alipay' ? 'true' : 'false' }}">
                        <span class="cn-pay-wallet-mark" aria-hidden="true">支</span>
                        <span class="cn-pay-wallet-copy">
                            <span class="cn-pay-wallet-name">Alipay</span>
                            <span class="cn-pay-wallet-note">{{ $alipayNote }}</span>
                        </span>
                    </button>
                </div>

                <p class="cn-pay-preview-note">{{ $previewNote }}</p>
            </section>

            <section class="cn-pay-qr-panel" aria-live="polite">
                <div class="cn-pay-wallet-heading">
                    <span class="cn-pay-wallet-heading-mark" data-active-wallet-mark aria-hidden="true">微</span>
                    <span class="cn-pay-wallet-heading-name" data-active-wallet-name>WeChat Pay</span>
                </div>
                <div class="cn-pay-qr-frame">
                    <img class="cn-pay-qr-image" data-payment-qr-image alt="{{ $text('Mã QR thanh toán', 'Payment QR code', '支付二维码', '付款 QR 碼', '支払いQRコード', '결제 QR 코드', 'کد QR پرداخت', 'QR-код для оплаты') }}" hidden>
                    <div class="cn-pay-qr-demo" data-payment-qr-demo aria-hidden="true"></div>
                    <span class="cn-pay-demo-badge" data-demo-badge>{{ $demoBadge }}</span>
                </div>
                <p class="cn-pay-instruction" data-wallet-instruction>{{ $selectedWallet === 'alipay' ? $alipayInstruction : $wechatInstruction }}</p>
                <div class="cn-pay-state" data-payment-state role="status" aria-live="polite">{{ $previewMessage }}</div>
                <div class="cn-pay-countdown" data-payment-countdown hidden></div>
                <div class="cn-pay-actions">
                    <a class="cn-pay-button cn-pay-button-secondary" href="{{ $returnUrl }}">{{ $backLabel }}</a>
                    <button class="cn-pay-button cn-pay-button-primary" type="button" data-create-payment>{{ $previewMode ? $previewAction : $createLabel }}</button>
                </div>
            </section>
        </div>
    </article>
</main>
<script src="/payment/china-wallet-checkout.js?v=1" defer></script>
</body>
</html>
