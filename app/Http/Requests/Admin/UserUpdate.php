<?php

namespace App\Http\Requests\Admin;

use App\Services\Plugin\HookManager;
use Illuminate\Foundation\Http\FormRequest;

class UserUpdate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $rules = [
            'id' => 'required|integer',
            'email' => 'email:strict',
            'invite_user_email' => 'nullable|email:strict',
            'password' => 'nullable|min:8',
            'transfer_enable' => 'numeric',
            'expired_at' => 'nullable|integer',
            'banned' => 'bool',
            'plan_id' => 'nullable|integer',
            'commission_rate' => 'nullable|integer|min:0|max:100',
            'discount' => 'nullable|integer|min:0|max:100',
            'is_admin' => 'boolean',
            'is_staff' => 'boolean',
            'is_reseller' => 'boolean',
            'u' => 'integer',
            'd' => 'integer',
            'balance' => 'numeric',
            'commission_type' => 'integer',
            'commission_balance' => 'numeric',
            'remarks' => 'nullable',
            'speed_limit' => 'nullable|integer',
            'device_limit' => 'nullable|integer',
            'locale' => 'nullable|string|in:zh-CN,zh-TW,en-US,vi-VN,ja-JP,ko-KR,fa-IR,ru-RU'
        ];

        return HookManager::filter('admin.user.update.rules', $rules, $this);
    }

    public function messages()
    {
        $messages = [
            'email.required' => __('Email can not be empty'),
            'email.email' => __('Email format is incorrect'),
            'invite_user_email.email' => __('The referrer email format is invalid'),
            'transfer_enable.numeric' => __('Traffic allowance must be numeric'),
            'expired_at.integer' => __('Expiration time is invalid'),
            'banned.in' => __('Account lock status is invalid'),
            'is_admin.required' => __('Administrator status must be selected'),
            'is_admin.in' => __('Administrator status is invalid'),
            'is_staff.required' => __('Staff status must be selected'),
            'is_staff.in' => __('Staff status is invalid'),
            'is_reseller.boolean' => __('Reseller status is invalid'),
            'plan_id.integer' => __('Subscription plan is invalid'),
            'commission_rate.integer' => __('Commission rate must be an integer'),
            'commission_rate.nullable' => __('Commission rate is invalid'),
            'commission_rate.min' => __('Commission rate must be at least 0'),
            'commission_rate.max' => __('Commission rate may not be greater than 100'),
            'discount.integer' => __('Exclusive discount must be an integer'),
            'discount.nullable' => __('Exclusive discount is invalid'),
            'discount.min' => __('Exclusive discount must be at least 0'),
            'discount.max' => __('Exclusive discount may not be greater than 100'),
            'u.integer' => __('Upload traffic is invalid'),
            'd.integer' => __('Download traffic is invalid'),
            'balance.integer' => __('Balance is invalid'),
            'commission_balance.integer' => __('Commission balance is invalid'),
            'password.min' => __('Password must be at least 8 characters'),
            'speed_limit.integer' => __('Speed limit is invalid'),
            'device_limit.integer' => __('Device limit is invalid'),
            'locale.in' => __('Account language is not supported')
        ];

        return HookManager::filter('admin.user.update.messages', $messages, $this);
    }
}
