<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailTemplate extends Model
{
    protected $table = 'v2_mail_templates';

    protected $fillable = ['name', 'language', 'subject', 'content'];

    public const DEFAULT_LANGUAGE = 'zh-CN';

    public const SUPPORTED_LANGUAGES = [
        'zh-CN', 'en-US', 'ja-JP', 'ko-KR', 'vi-VN', 'zh-TW', 'fa-IR', 'ru-RU',
    ];

    public static function normalizeLanguage(?string $language): string
    {
        $language = trim((string) $language);
        $language = preg_split('/[,;]/', $language, 2)[0] ?? $language;
        $language = str_replace('_', '-', trim($language));
        if (in_array($language, self::SUPPORTED_LANGUAGES, true)) {
            return $language;
        }

        $base = strtolower(explode('-', $language)[0] ?? '');
        foreach (self::SUPPORTED_LANGUAGES as $candidate) {
            if (strtolower(explode('-', $candidate)[0]) === $base && $base !== '') {
                return $candidate;
            }
        }

        return self::DEFAULT_LANGUAGE;
    }

    public static function defaultSubject(string $name, ?string $language, string $appName): string
    {
        $language = self::normalizeLanguage($language);
        $subjects = [
            'zh-CN' => ['verify' => '邮箱验证码', 'notify' => '站点通知', 'remindExpire' => '服务即将到期', 'remindTraffic' => '流量使用提醒', 'mailLogin' => '邮件登录'],
            'zh-TW' => ['verify' => '電子郵件驗證碼', 'notify' => '網站通知', 'remindExpire' => '服務即將到期', 'remindTraffic' => '流量使用提醒', 'mailLogin' => '電子郵件登入'],
            'en-US' => ['verify' => 'Email verification code', 'notify' => 'Notification', 'remindExpire' => 'Service expiration reminder', 'remindTraffic' => 'Traffic usage reminder', 'mailLogin' => 'Sign-in link'],
            'vi-VN' => ['verify' => 'Mã xác thực email', 'notify' => 'Thông báo', 'remindExpire' => 'Dịch vụ sắp hết hạn', 'remindTraffic' => 'Nhắc nhở dung lượng', 'mailLogin' => 'Liên kết đăng nhập'],
            'ja-JP' => ['verify' => 'メール認証コード', 'notify' => 'お知らせ', 'remindExpire' => 'サービス期限のお知らせ', 'remindTraffic' => '通信量のお知らせ', 'mailLogin' => 'ログインリンク'],
            'ko-KR' => ['verify' => '이메일 인증 코드', 'notify' => '알림', 'remindExpire' => '서비스 만료 알림', 'remindTraffic' => '트래픽 사용량 알림', 'mailLogin' => '로그인 링크'],
            'fa-IR' => ['verify' => 'کد تایید ایمیل', 'notify' => 'اعلان', 'remindExpire' => 'یادآوری پایان سرویس', 'remindTraffic' => 'یادآوری مصرف ترافیک', 'mailLogin' => 'پیوند ورود'],
            'ru-RU' => ['verify' => 'Код подтверждения email', 'notify' => 'Уведомление', 'remindExpire' => 'Срок действия услуги истекает', 'remindTraffic' => 'Напоминание о трафике', 'mailLogin' => 'Ссылка для входа'],
        ];

        return $appName . ' - ' . ($subjects[$language][$name] ?? $subjects['en-US'][$name] ?? $appName);
    }

    public static function label(string $name, ?string $language): string
    {
        $language = self::normalizeLanguage($language);
        $labels = [
            'zh-CN' => ['verify' => '邮箱验证码', 'notify' => '站点通知', 'remindExpire' => '到期提醒', 'remindTraffic' => '流量提醒', 'mailLogin' => '邮件登录'],
            'zh-TW' => ['verify' => '電子郵件驗證碼', 'notify' => '網站通知', 'remindExpire' => '到期提醒', 'remindTraffic' => '流量提醒', 'mailLogin' => '電子郵件登入'],
            'en-US' => ['verify' => 'Verification code', 'notify' => 'Notification', 'remindExpire' => 'Expiration reminder', 'remindTraffic' => 'Traffic reminder', 'mailLogin' => 'Email sign-in'],
            'vi-VN' => ['verify' => 'Mã xác thực email', 'notify' => 'Thông báo', 'remindExpire' => 'Nhắc hết hạn', 'remindTraffic' => 'Nhắc dung lượng', 'mailLogin' => 'Đăng nhập qua email'],
            'ja-JP' => ['verify' => 'メール認証コード', 'notify' => 'お知らせ', 'remindExpire' => '期限のお知らせ', 'remindTraffic' => '通信量のお知らせ', 'mailLogin' => 'メールログイン'],
            'ko-KR' => ['verify' => '이메일 인증 코드', 'notify' => '알림', 'remindExpire' => '만료 알림', 'remindTraffic' => '트래픽 알림', 'mailLogin' => '이메일 로그인'],
            'fa-IR' => ['verify' => 'کد تایید ایمیل', 'notify' => 'اعلان', 'remindExpire' => 'یادآوری انقضا', 'remindTraffic' => 'یادآوری ترافیک', 'mailLogin' => 'ورود با ایمیل'],
            'ru-RU' => ['verify' => 'Код подтверждения', 'notify' => 'Уведомление', 'remindExpire' => 'Напоминание об окончании', 'remindTraffic' => 'Напоминание о трафике', 'mailLogin' => 'Вход по email'],
        ];

        return $labels[$language][$name] ?? self::TEMPLATES[$name]['label'] ?? $name;
    }

    public static function defaultContent(string $name, ?string $language): string
    {
        $language = self::normalizeLanguage($language);
        $copy = [
            'zh-CN' => [
                'greeting' => '尊敬的用户您好！', 'back' => '返回 {{name}}',
                'verify' => ['邮箱验证码', '您的验证码是：<strong>{{code}}</strong>。请在 5 分钟内完成验证。如果并非您本人操作，请忽略此邮件。'],
                'notify' => ['网站通知', '{{content}}'],
                'remindExpire' => ['服务到期提醒', '您的服务将在 24 小时内到期，如需继续使用请及时续费。'],
                'remindTraffic' => ['流量使用提醒', '您的流量使用已达到 80%，请留意剩余流量。'],
                'mailLogin' => ['登录 {{name}}', '请在 5 分钟内点击以下链接登录。如果并非您本人操作，请忽略此邮件：<br><a href="{{link}}">{{link}}</a>'],
            ],
            'zh-TW' => [
                'greeting' => '您好！', 'back' => '返回 {{name}}',
                'verify' => ['電子郵件驗證碼', '您的驗證碼是：<strong>{{code}}</strong>。請在 5 分鐘內完成驗證。若非本人操作，請忽略此郵件。'],
                'notify' => ['網站通知', '{{content}}'],
                'remindExpire' => ['服務到期提醒', '您的服務將在 24 小時內到期，如需繼續使用請及時續費。'],
                'remindTraffic' => ['流量使用提醒', '您的流量使用已達到 80%，請留意剩餘流量。'],
                'mailLogin' => ['登入 {{name}}', '請在 5 分鐘內點擊以下連結登入。若非本人操作，請忽略此郵件：<br><a href="{{link}}">{{link}}</a>'],
            ],
            'en-US' => [
                'greeting' => 'Hello!', 'back' => 'Return to {{name}}',
                'verify' => ['Email verification code', 'Your verification code is <strong>{{code}}</strong>. It expires in 5 minutes. Ignore this email if you did not request it.'],
                'notify' => ['Notification', '{{content}}'],
                'remindExpire' => ['Service expiration reminder', 'Your service will expire within 24 hours. Please renew it if you want to keep using it.'],
                'remindTraffic' => ['Traffic usage reminder', 'You have used 80% of your traffic allowance. Please check your remaining traffic.'],
                'mailLogin' => ['Sign in to {{name}}', 'Use the link below within 5 minutes to sign in. Ignore this email if you did not request it:<br><a href="{{link}}">{{link}}</a>'],
            ],
            'vi-VN' => [
                'greeting' => 'Xin chào!', 'back' => 'Quay lại {{name}}',
                'verify' => ['Mã xác thực email', 'Mã xác thực của bạn là <strong>{{code}}</strong>. Mã có hiệu lực trong 5 phút. Nếu bạn không yêu cầu, hãy bỏ qua email này.'],
                'notify' => ['Thông báo', '{{content}}'],
                'remindExpire' => ['Dịch vụ sắp hết hạn', 'Dịch vụ của bạn sẽ hết hạn trong vòng 24 giờ. Vui lòng gia hạn nếu muốn tiếp tục sử dụng.'],
                'remindTraffic' => ['Nhắc nhở dung lượng', 'Bạn đã sử dụng 80% dung lượng. Vui lòng kiểm tra dung lượng còn lại.'],
                'mailLogin' => ['Đăng nhập vào {{name}}', 'Hãy dùng liên kết dưới đây trong vòng 5 phút để đăng nhập. Nếu bạn không yêu cầu, hãy bỏ qua email này:<br><a href="{{link}}">{{link}}</a>'],
            ],
            'ja-JP' => [
                'greeting' => 'こんにちは。', 'back' => '{{name}} に戻る',
                'verify' => ['メール認証コード', '認証コードは <strong>{{code}}</strong> です。5 分以内に認証してください。心当たりがない場合は、このメールを無視してください。'],
                'notify' => ['お知らせ', '{{content}}'],
                'remindExpire' => ['サービス期限のお知らせ', 'サービスは 24 時間以内に期限切れになります。引き続き利用する場合は更新してください。'],
                'remindTraffic' => ['通信量のお知らせ', '通信量の 80% を使用しました。残りの通信量をご確認ください。'],
                'mailLogin' => ['{{name}} にログイン', '5 分以内に以下のリンクからログインしてください。心当たりがない場合は無視してください。<br><a href="{{link}}">{{link}}</a>'],
            ],
            'ko-KR' => [
                'greeting' => '안녕하세요.', 'back' => '{{name}}(으)로 돌아가기',
                'verify' => ['이메일 인증 코드', '인증 코드는 <strong>{{code}}</strong>입니다. 5분 이내에 인증해 주세요. 요청하지 않았다면 이 메일을 무시하세요.'],
                'notify' => ['알림', '{{content}}'],
                'remindExpire' => ['서비스 만료 알림', '서비스가 24시간 이내에 만료됩니다. 계속 이용하려면 갱신해 주세요.'],
                'remindTraffic' => ['트래픽 사용량 알림', '트래픽의 80%를 사용했습니다. 남은 트래픽을 확인해 주세요.'],
                'mailLogin' => ['{{name}} 로그인', '5분 이내에 아래 링크로 로그인하세요. 요청하지 않았다면 이 메일을 무시하세요.<br><a href="{{link}}">{{link}}</a>'],
            ],
            'fa-IR' => [
                'greeting' => 'سلام!', 'back' => 'بازگشت به {{name}}',
                'verify' => ['کد تایید ایمیل', 'کد تایید شما <strong>{{code}}</strong> است و ۵ دقیقه اعتبار دارد. اگر این درخواست را نداده‌اید، ایمیل را نادیده بگیرید.'],
                'notify' => ['اعلان', '{{content}}'],
                'remindExpire' => ['یادآوری پایان سرویس', 'سرویس شما تا ۲۴ ساعت آینده منقضی می‌شود. برای ادامه استفاده آن را تمدید کنید.'],
                'remindTraffic' => ['یادآوری مصرف ترافیک', '۸۰٪ از ترافیک خود را مصرف کرده‌اید. لطفا ترافیک باقی‌مانده را بررسی کنید.'],
                'mailLogin' => ['ورود به {{name}}', 'برای ورود، تا ۵ دقیقه روی پیوند زیر کلیک کنید. اگر این درخواست را نداده‌اید، ایمیل را نادیده بگیرید.<br><a href="{{link}}">{{link}}</a>'],
            ],
            'ru-RU' => [
                'greeting' => 'Здравствуйте!', 'back' => 'Вернуться в {{name}}',
                'verify' => ['Код подтверждения email', 'Ваш код подтверждения: <strong>{{code}}</strong>. Он действует 5 минут. Если вы не запрашивали код, проигнорируйте это письмо.'],
                'notify' => ['Уведомление', '{{content}}'],
                'remindExpire' => ['Срок действия услуги истекает', 'Срок действия услуги истечёт в течение 24 часов. Продлите её, чтобы продолжить использование.'],
                'remindTraffic' => ['Напоминание о трафике', 'Вы использовали 80% трафика. Проверьте оставшийся объём.'],
                'mailLogin' => ['Вход в {{name}}', 'В течение 5 минут перейдите по ссылке ниже. Если вы не запрашивали вход, проигнорируйте письмо.<br><a href="{{link}}">{{link}}</a>'],
            ],
        ];

        $text = $copy[$language] ?? $copy['en-US'];
        [$title, $body] = $text[$name] ?? $text['notify'];
        $direction = $language === 'fa-IR' ? 'rtl' : 'ltr';

        return <<<HTML
<div dir="{$direction}" style="margin:0;background:#f1f5f9;padding:24px;font-family:Arial,sans-serif;color:#1f2937">
  <table width="100%" role="presentation" cellspacing="0" cellpadding="0"><tr><td align="center">
    <table width="600" role="presentation" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:#fff;border-radius:16px;overflow:hidden">
      <tr><td style="padding:22px 30px;background:#4f7cf7;color:#fff;font-size:21px;font-weight:700">{{name}}</td></tr>
      <tr><td style="padding:30px">
        <div style="font-size:24px;font-weight:700;margin-bottom:20px">{$title}</div>
        <div style="font-size:15px;line-height:1.7">{$text['greeting']}<br><br>{$body}</div>
      </td></tr>
      <tr><td style="padding:18px 30px;background:#f8fafc;font-size:13px"><a href="{{url}}" style="color:#64748b">{$text['back']}</a></td></tr>
    </table>
  </td></tr></table>
</div>
HTML;
    }

    /**
     * Template definitions: required/optional vars and default content.
     */
    public const TEMPLATES = [
        'verify' => [
            'label' => '邮箱验证码',
            'required_vars' => ['code'],
            'optional_vars' => ['name', 'url'],
        ],
        'notify' => [
            'label' => '站点通知',
            'required_vars' => ['content'],
            'optional_vars' => ['name', 'url'],
        ],
        'remindExpire' => [
            'label' => '到期提醒',
            'required_vars' => [],
            'optional_vars' => ['name', 'url'],
        ],
        'remindTraffic' => [
            'label' => '流量提醒',
            'required_vars' => [],
            'optional_vars' => ['name', 'url'],
        ],
        'mailLogin' => [
            'label' => '邮件登录',
            'required_vars' => ['link'],
            'optional_vars' => ['name', 'url'],
        ],
    ];

    /**
     * Get template metadata (vars, label) for a given template name.
     */
    public static function getMeta(string $name): ?array
    {
        return self::TEMPLATES[$name] ?? null;
    }

    /**
     * Get all template names.
     */
    public static function getNames(): array
    {
        return array_keys(self::TEMPLATES);
    }

    /**
     * Validate that required placeholders are present in the content.
     */
    public static function validateContent(string $name, string $content): array
    {
        $meta = self::getMeta($name);
        if (!$meta) {
            return ["Mẫu email không tồn tại: {$name}"];
        }

        $errors = [];
        foreach ($meta['required_vars'] as $var) {
            if (strpos($content, '{{' . $var . '}}') === false) {
                $errors[] = "Thiếu biến bắt buộc: {{{$var}}}";
            }
        }
        return $errors;
    }
}
