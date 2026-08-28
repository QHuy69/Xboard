@php
    $isVi = $locale === 'vi-VN';
    $isZh = $locale === 'zh-CN';
    $isJa = $locale === 'ja-JP';
    $isKo = $locale === 'ko-KR';
    $text = function (string $vi, string $en, string $zh, string $ja, string $ko) use ($isVi, $isZh, $isJa, $isKo): string {
        return $isVi ? $vi : ($isZh ? $zh : ($isJa ? $ja : ($isKo ? $ko : $en)));
    };
    $scriptLabels = [
        'remaining' => $text('Thời gian còn lại để thanh toán', 'Time remaining to pay', '剩余支付时间', '支払い残り時間', '결제 남은 시간'),
        'expired' => $text('Đơn hàng đã hết thời gian thanh toán.', 'This order has expired.', '订单已过期。', 'この注文の支払い期限が切れました。', '주문 결제 시간이 만료되었습니다.'),
        'checking' => $text('Đang kiểm tra...', 'Checking...', '检查中...', '確認中...', '확인 중...'),
        'pending' => $text('Chưa nhận được thanh toán.', 'Payment has not been received yet.', '尚未收到付款。', 'まだ支払いを確認できません。', '아직 결제가 확인되지 않았습니다.'),
        'paid' => $text('Thanh toán thành công.', 'Payment successful.', '支付成功。', '支払いが完了しました。', '결제가 완료되었습니다.'),
        'cancelled' => $text('Đơn hàng đã bị hủy.', 'This order was cancelled.', '订单已取消。', '注文はキャンセルされました。', '주문이 취소되었습니다.')
    ];
@endphp
<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $text('Thanh toán', 'Payment', '支付', 'お支払い', '결제') }} · ZaoGuang Service</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: linear-gradient(135deg, #edf7ff, #eefcf7); color: #27364a; }
        .card { width: min(560px, 100%); background: #fff; border-radius: 22px; box-shadow: 0 18px 60px rgba(31, 57, 86, .18); overflow: hidden; }
        .header { padding: 28px 30px 22px; background: linear-gradient(135deg, #49bfa7, #6ed5bf); color: #fff; }
        .header h1 { margin: 0 0 8px; font-size: 25px; }
        .header p { margin: 0; opacity: .9; }
        .body { padding: 26px 30px 30px; }
        .summary { display: grid; gap: 10px; margin-bottom: 20px; padding: 16px 18px; background: #f6fafb; border: 1px solid #e7f1ef; border-radius: 14px; }
        .row { display: flex; justify-content: space-between; gap: 16px; align-items: center; }
        .label { color: #718096; }
        .value { text-align: right; font-weight: 650; word-break: break-word; }
        .amount { font-size: 24px; color: #1caa82; }
        .qr-wrap { display: grid; place-items: center; padding: 18px; background: #fff; border: 1px solid #e8eef0; border-radius: 16px; }
        .qr-wrap img { width: min(320px, 100%); height: auto; image-rendering: pixelated; border-radius: 8px; }
        .hint { margin: 15px 0 0; text-align: center; color: #677489; line-height: 1.55; }
        .countdown { margin: 20px 0; padding: 13px 16px; text-align: center; border-radius: 12px; background: #fff8e6; color: #a3650b; font-weight: 650; }
        .countdown.expired { background: #fff0f0; color: #c0392b; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-top: 20px; }
        .button { display: inline-flex; justify-content: center; align-items: center; min-height: 44px; padding: 0 19px; border: 0; border-radius: 11px; text-decoration: none; cursor: pointer; font-weight: 650; font-size: 14px; }
        .primary { background: #49bfa7; color: white; }
        .secondary { background: #edf3f5; color: #475569; }
        .status { min-height: 24px; margin-top: 14px; text-align: center; color: #627083; }
        @media (max-width: 480px) { .body, .header { padding-left: 20px; padding-right: 20px; } .row { align-items: flex-start; flex-direction: column; gap: 3px; } .value { text-align: left; } }
    </style>
</head>
<body>
<main class="card">
    <header class="header">
        <h1>{{ $text('Thanh toán qua VietQR', 'Pay with VietQR', '使用 VietQR 支付', 'VietQRで支払う', 'VietQR 결제') }}</h1>
        <p>{{ $text('ZaoGuang Service · Đơn hàng', 'ZaoGuang Service · Order', 'ZaoGuang Service · 订单', 'ZaoGuang Service · 注文', 'ZaoGuang Service · 주문') }} {{ $order->trade_no }}</p>
    </header>
    <section class="body">
        <div class="summary">
            <div class="row"><span class="label">{{ $text('Ngân hàng', 'Bank', '银行', '銀行', '은행') }}</span><span class="value">{{ $bankName }}</span></div>
            <div class="row"><span class="label">{{ $text('Chủ tài khoản', 'Account name', '账户名称', '口座名義', '예금주') }}</span><span class="value">{{ $accountName }}</span></div>
            <div class="row"><span class="label">{{ $text('Số tiền', 'Amount', '金额', '金額', '금액') }}</span><span class="value amount">{{ number_format($amountVnd) }} VND</span></div>
            <div class="row"><span class="label">{{ $text('Giá gốc', 'Original price', '原价', '元の価格', '원래 금액') }}</span><span class="value">¥{{ $amountCny }}</span></div>
            <div class="row"><span class="label">{{ $text('Nội dung chuyển khoản', 'Transfer description', '转账备注', '振込内容', '입금 메모') }}</span><span class="value">{{ $transferDescription }}</span></div>
        </div>
        <div class="qr-wrap"><img src="{{ $qrUrl }}" alt="{{ $text('Mã QR thanh toán', 'Payment QR code', '支付二维码', '支払いQRコード', '결제 QR 코드') }}"></div>
        <p class="hint">{{ $text('Mở ứng dụng ngân hàng, quét mã QR và chuyển đúng số tiền. Hệ thống tự động đối soát sau khi nhận được tiền.', 'Open your banking app, scan the QR code, and transfer the exact amount. Payment is matched automatically after receipt.', '打开银行应用扫描二维码并转账准确金额。到账后系统会自动核对。', '銀行アプリでQRコードを読み取り、正確な金額を送金してください。入金後に自動照合されます。', '은행 앱으로 QR 코드를 스캔하고 정확한 금액을 이체하세요. 입금 후 자동으로 확인됩니다.') }}</p>
        <div id="countdown" class="countdown"></div>
        <div id="status" class="status" aria-live="polite"></div>
        <div class="actions">
            <a class="button secondary" href="{{ $returnUrl }}">{{ $text('Quay lại đơn hàng', 'Back to orders', '返回订单', '注文に戻る', '주문으로 돌아가기') }}</a>
            <button id="check" class="button primary" type="button">{{ $text('Kiểm tra thanh toán', 'Check payment', '检查支付状态', '支払い状態を確認', '결제 상태 확인') }}</button>
        </div>
    </section>
</main>
<script>
(() => {
    const expiresAt = {{ (int) $expiresAt }} * 1000;
    const countdown = document.getElementById('countdown');
    const status = document.getElementById('status');
    const check = document.getElementById('check');
    const labels = @json($scriptLabels);
    const formatRemaining = () => {
        const seconds = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
        if (seconds <= 0) {
            countdown.textContent = labels.expired;
            countdown.classList.add('expired');
            check.disabled = true;
            return false;
        }
        const h = String(Math.floor(seconds / 3600)).padStart(2, '0');
        const m = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
        const s = String(seconds % 60).padStart(2, '0');
        countdown.textContent = labels.remaining + ': ' + h + ':' + m + ':' + s;
        return true;
    };
    const checkStatus = async () => {
        if (!formatRemaining()) return;
        status.textContent = labels.checking;
        try {
            const response = await fetch(@json($statusUrl), { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error('status');
            const result = await response.json();
            if (result.status === 1 || result.status === 3) {
                status.textContent = labels.paid;
                setTimeout(() => { window.location.href = @json($returnUrl); }, 900);
            } else if (result.status === 2) {
                status.textContent = labels.cancelled;
            } else {
                status.textContent = labels.pending;
            }
        } catch (error) {
            status.textContent = labels.pending;
        }
    };
    formatRemaining();
    setInterval(formatRemaining, 1000);
    check.addEventListener('click', checkStatus);
    setInterval(checkStatus, 5000);
})();
</script>
</body>
</html>
