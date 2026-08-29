<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\MailTemplate;
use App\Services\MailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MailTemplateController extends Controller
{
    public function list(Request $request)
    {
        $language = $this->requestedLanguage($request);
        $dbTemplates = MailTemplate::where('language', $language)->get()->keyBy('name');

        $result = [];
        foreach (MailTemplate::TEMPLATES as $name => $meta) {
            $db = $dbTemplates->get($name);
            $result[] = [
                'name' => $name,
                'label' => MailTemplate::label($name, $language),
                'language' => $language,
                'customized' => $db !== null,
                'subject' => $db?->subject,
                'updated_at' => $db?->updated_at?->timestamp,
            ];
        }

        // Keep the original array response so older admin bundles continue to work.
        return $this->success($result);
    }

    public function get(Request $request)
    {
        $name = $request->input('name');
        $meta = MailTemplate::getMeta($name);
        if (!$meta) {
            return $this->fail([404, 'Mẫu email không tồn tại']);
        }

        $language = $this->requestedLanguage($request);
        $db = MailTemplate::where('name', $name)->where('language', $language)->first();
        $appName = admin_setting('app_name', 'XBoard');

        return $this->success([
            'name' => $name,
            'label' => MailTemplate::label($name, $language),
            'language' => $language,
            'languages' => MailTemplate::SUPPORTED_LANGUAGES,
            'required_vars' => $meta['required_vars'],
            'optional_vars' => $meta['optional_vars'],
            'customized' => $db !== null,
            'subject' => $db?->subject ?? MailTemplate::defaultSubject($name, $language, $appName),
            'content' => $db?->content ?? MailTemplate::defaultContent($name, $language),
        ]);
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'name' => 'required|string',
            'language' => 'nullable|string|max:10',
            'subject' => 'required|string|max:255',
            'content' => 'required|string',
        ]);

        $meta = MailTemplate::getMeta($params['name']);
        if (!$meta) {
            return $this->fail([404, 'Mẫu email không tồn tại']);
        }

        $language = MailTemplate::normalizeLanguage($params['language'] ?? $this->requestedLanguage($request));

        $errors = MailTemplate::validateContent($params['name'], $params['content']);
        if (!empty($errors)) {
            return $this->fail([422, implode('; ', $errors)]);
        }

        MailTemplate::updateOrCreate(
            ['name' => $params['name'], 'language' => $language],
            ['subject' => $params['subject'], 'content' => $params['content']]
        );
        Cache::forget("mail_template:{$params['name']}:{$language}");

        return $this->success(true);
    }

    public function reset(Request $request)
    {
        $name = $request->input('name');
        $meta = MailTemplate::getMeta($name);
        if (!$meta) {
            return $this->fail([404, 'Mẫu email không tồn tại']);
        }

        $language = $this->requestedLanguage($request);
        MailTemplate::where('name', $name)->where('language', $language)->delete();
        Cache::forget("mail_template:{$name}:{$language}");
        return $this->success(true);
    }

    public function test(Request $request)
    {
        $name = $request->input('name');
        $meta = MailTemplate::getMeta($name);
        if (!$meta) {
            return $this->fail([404, 'Mẫu email không tồn tại']);
        }

        $email = $request->input('email', $request->user()->email);
        $language = $this->requestedLanguage($request);
        $testVars = $this->getTestVars($name, $language);

        try {
            $log = MailService::sendEmail([
                'email' => $email,
                'subject' => MailTemplate::defaultSubject($name, $language, admin_setting('app_name', 'XBoard')),
                'language' => $language,
                'template_name' => $name,
                'template_value' => $testVars,
            ]);

            if ($log['error']) {
                return $this->fail([500, 'Gửi email thất bại: ' . $log['error']]);
            }
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, 'Gửi email thất bại: ' . $e->getMessage()]);
        }
    }

    private function requestedLanguage(Request $request): string
    {
        $header = (string) $request->header('content-language', $request->header('accept-language', ''));
        $header = trim(explode(',', $header)[0] ?? '');
        return MailTemplate::normalizeLanguage($request->input('language', $header));
    }

    private function getTestVars(string $name, string $language): array
    {
        $appName = admin_setting('app_name', 'XBoard');
        $appUrl = admin_setting('app_url', 'https://example.com');
        $testContent = match ($language) {
            'vi-VN' => 'Đây là email thông báo thử nghiệm.',
            'ja-JP' => 'これはテスト通知メールです。',
            'ko-KR' => '테스트 알림 이메일입니다.',
            'zh-CN' => '这是一封测试通知邮件。',
            'zh-TW' => '這是一封測試通知郵件。',
            'fa-IR' => 'این یک ایمیل اعلان آزمایشی است.',
            'ru-RU' => 'Это тестовое уведомление.',
            default => 'This is a test notification email.',
        };

        return match ($name) {
            'verify' => [
                'name' => $appName,
                'code' => '123456',
                'url' => $appUrl,
            ],
            'notify' => [
                'name' => $appName,
                'content' => $testContent,
                'url' => $appUrl,
            ],
            'remindExpire' => [
                'name' => $appName,
                'url' => $appUrl,
            ],
            'remindTraffic' => [
                'name' => $appName,
                'url' => $appUrl,
            ],
            'mailLogin' => [
                'name' => $appName,
                'link' => $appUrl . '/login?token=test-token',
                'url' => $appUrl,
            ],
            default => ['name' => $appName, 'url' => $appUrl],
        };
    }
}
