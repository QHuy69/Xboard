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
            'password' => 'nullable|min:8',
            'transfer_enable' => 'numeric',
            'expired_at' => 'nullable|integer',
            'banned' => 'bool',
            'plan_id' => 'nullable|integer',
            'commission_rate' => 'nullable|integer|min:0|max:100',
            'discount' => 'nullable|integer|min:0|max:100',
            'is_admin' => 'boolean',
            'is_staff' => 'boolean',
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
            'email.required' => 'Email không được để trống',
            'email.email' => 'Định dạng email không hợp lệ',
            'transfer_enable.numeric' => 'Lưu lượng phải là một số',
            'expired_at.integer' => 'Thời gian hết hạn không hợp lệ',
            'banned.in' => 'Trạng thái khóa tài khoản không hợp lệ',
            'is_admin.required' => 'Phải chọn quyền quản trị viên',
            'is_admin.in' => 'Quyền quản trị viên không hợp lệ',
            'is_staff.required' => 'Phải chọn quyền nhân viên',
            'is_staff.in' => 'Quyền nhân viên không hợp lệ',
            'plan_id.integer' => 'Gói đăng ký không hợp lệ',
            'commission_rate.integer' => 'Tỷ lệ hoa hồng phải là số nguyên',
            'commission_rate.nullable' => 'Tỷ lệ hoa hồng không hợp lệ',
            'commission_rate.min' => 'Tỷ lệ hoa hồng tối thiểu là 0',
            'commission_rate.max' => 'Tỷ lệ hoa hồng tối đa là 100',
            'discount.integer' => 'Giảm giá độc quyền phải là số nguyên',
            'discount.nullable' => 'Giảm giá độc quyền không hợp lệ',
            'discount.min' => 'Giảm giá độc quyền tối thiểu là 0',
            'discount.max' => 'Giảm giá độc quyền tối đa là 100',
            'u.integer' => 'Lưu lượng tải lên không hợp lệ',
            'd.integer' => 'Lưu lượng tải xuống không hợp lệ',
            'balance.integer' => 'Số dư không hợp lệ',
            'commission_balance.integer' => 'Số dư hoa hồng không hợp lệ',
            'password.min' => 'Mật khẩu phải có ít nhất 8 ký tự',
            'speed_limit.integer' => 'Giới hạn tốc độ không hợp lệ',
            'device_limit.integer' => 'Giới hạn thiết bị không hợp lệ',
            'locale.in' => 'Ngôn ngữ tài khoản không được hỗ trợ'
        ];

        return HookManager::filter('admin.user.update.messages', $messages, $this);
    }
}
