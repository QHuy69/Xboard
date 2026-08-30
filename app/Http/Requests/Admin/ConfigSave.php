<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ConfigSave extends FormRequest
{
    const RULES = [
        // invite & commission
        'invite_force' => '',
        'invite_commission' => 'integer|nullable',
        'invite_gen_limit' => 'integer|nullable',
        'invite_never_expire' => '',
        'commission_first_time_enable' => '',
        'commission_auto_check_enable' => '',
        'commission_withdraw_limit' => 'nullable|numeric',
        'commission_withdraw_method' => 'nullable|array',
        'withdraw_close_enable' => '',
        'commission_distribution_enable' => '',
        'commission_distribution_l1' => 'nullable|numeric',
        'commission_distribution_l2' => 'nullable|numeric',
        'commission_distribution_l3' => 'nullable|numeric',
        // site
        'logo' => 'nullable|url',
        'force_https' => '',
        'stop_register' => '',
        'app_name' => '',
        'app_description' => '',
        'app_url' => 'nullable|url',
        'subscribe_url' => 'nullable',
        'try_out_enable' => '',
        'try_out_plan_id' => 'integer',
        'try_out_hour' => 'numeric',
        'tos_url' => 'nullable|url',
        'currency' => '',
        'currency_symbol' => '',
        'ticket_must_wait_reply' => '',
        // subscribe
        'plan_change_enable' => '',
        'reset_traffic_method' => 'in:0,1,2,3,4',
        'surplus_enable' => '',
        'new_order_event_id' => '',
        'renew_order_event_id' => '',
        'change_order_event_id' => '',
        'show_info_to_server_enable' => '',
        'show_protocol_to_server_enable' => '',
        'subscribe_path' => '',
        // server
        'server_token' => 'nullable|min:16',
        'server_pull_interval' => 'integer',
        'server_push_interval' => 'integer',
        'device_limit_mode' => 'integer',
        'server_ws_enable' => 'boolean',
        'server_ws_url' => 'nullable|url',
        // frontend
        'frontend_theme' => '',
        'frontend_theme_sidebar' => 'nullable|in:dark,light',
        'frontend_theme_header' => 'nullable|in:dark,light',
        'frontend_theme_color' => 'nullable|in:default,darkblue,black,green',
        'frontend_background_url' => 'nullable|url',
        'crisp_website_id' => 'nullable|uuid',
        'messenger_page_username' => 'nullable|regex:/^[A-Za-z0-9._-]{3,100}$/',
        // email
        'email_host' => '',
        'email_port' => '',
        'email_username' => '',
        'email_password' => '',
        'email_encryption' => '',
        'email_from_address' => '',
        'remind_mail_enable' => '',
        // telegram
        'telegram_bot_enable' => '',
        'telegram_bot_token' => '',
        'telegram_webhook_url' => 'nullable|url',
        'telegram_discuss_id' => '',
        'telegram_channel_id' => '',
        'telegram_discuss_link' => 'nullable|url',
        // app
        'windows_version' => '',
        'windows_download_url' => '',
        'macos_version' => '',
        'macos_download_url' => '',
        'android_version' => '',
        'android_download_url' => '',
        // safe
        'email_whitelist_enable' => 'boolean',
        'email_whitelist_suffix' => 'nullable|array',
        'email_gmail_limit_enable' => 'boolean',
        'captcha_enable' => 'boolean',
        'captcha_type' => 'in:recaptcha,turnstile,recaptcha-v3',
        'recaptcha_enable' => 'boolean',
        'recaptcha_key' => '',
        'recaptcha_site_key' => '',
        'recaptcha_v3_secret_key' => '',
        'recaptcha_v3_site_key' => '',
        'recaptcha_v3_score_threshold' => 'numeric|min:0|max:1',
        'turnstile_secret_key' => '',
        'turnstile_site_key' => '',
        'email_verify' => 'bool',
        'safe_mode_enable' => 'boolean',
        'register_limit_by_ip_enable' => 'boolean',
        'register_limit_count' => 'integer',
        'register_limit_expire' => 'integer',
        'secure_path' => 'min:7|regex:/^[\w-]*$/',
        'password_limit_enable' => 'boolean',
        'password_limit_count' => 'integer',
        'password_limit_expire' => 'integer',
        'default_remind_expire' => 'boolean',
        'default_remind_traffic' => 'boolean',
        'subscribe_template_singbox' => 'nullable',
        'subscribe_template_clash' => 'nullable',
        'subscribe_template_clashmeta' => 'nullable',
        'subscribe_template_stash' => 'nullable',
        'subscribe_template_surge' => 'nullable',
        'subscribe_template_surfboard' => 'nullable'
    ];
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return self::RULES;
    }

    public function messages()
    {
        // illiteracy prompt
        return [
            'app_url.url' => 'URL trang web không hợp lệ, phải bắt đầu bằng http:// hoặc https://',
            'subscribe_url.url' => 'URL đăng ký không hợp lệ, phải bắt đầu bằng http:// hoặc https://',
            'server_token.min' => 'Khóa giao tiếp phải có ít nhất 16 ký tự',
            'tos_url.url' => 'URL điều khoản dịch vụ không hợp lệ, phải bắt đầu bằng http:// hoặc https://',
            'telegram_webhook_url.url' => 'URL Telegram Webhook không hợp lệ, phải bắt đầu bằng http:// hoặc https://',
            'telegram_discuss_link.url' => 'URL nhóm Telegram không hợp lệ, phải bắt đầu bằng http:// hoặc https://',
            'logo.url' => 'URL logo không hợp lệ, phải bắt đầu bằng http:// hoặc https://',
            'crisp_website_id.uuid' => 'Crisp Website ID không đúng định dạng UUID',
            'messenger_page_username.regex' => 'Tên người dùng Messenger chỉ được chứa chữ cái, số, dấu chấm, gạch dưới hoặc gạch ngang',
            'secure_path.min' => 'Đường dẫn quản trị phải có ít nhất 7 ký tự',
            'secure_path.regex' => 'Đường dẫn quản trị chỉ được chứa chữ cái, số, dấu gạch dưới hoặc dấu gạch ngang',
            'captcha_type.in' => 'Loại CAPTCHA chỉ có thể là reCAPTCHA, Turnstile hoặc reCAPTCHA v3',
            'recaptcha_v3_score_threshold.numeric' => 'Ngưỡng điểm reCAPTCHA v3 phải là một số',
            'recaptcha_v3_score_threshold.min' => 'Ngưỡng điểm reCAPTCHA v3 không được nhỏ hơn 0',
            'recaptcha_v3_score_threshold.max' => 'Ngưỡng điểm reCAPTCHA v3 không được lớn hơn 1'
        ];
    }
}
