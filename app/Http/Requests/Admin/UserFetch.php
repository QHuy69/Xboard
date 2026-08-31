<?php

namespace App\Http\Requests\Admin;

use App\Services\Plugin\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserFetch extends FormRequest
{
    private const FILTERABLE_FIELDS = [
        'id',
        'invite_user_id',
        'invite_user.email',
        'telegram_id',
        'email',
        'balance',
        'discount',
        'commission_type',
        'commission_rate',
        'commission_balance',
        't',
        'u',
        'd',
        'total_used',
        'transfer_enable',
        'banned',
        'is_admin',
        'is_staff',
        'is_reseller',
        'last_login_at',
        'last_login_ip',
        'uuid',
        'group_id',
        'group_ids',
        'plan_id',
        'speed_limit',
        'device_limit',
        'online_count',
        'last_online_at',
        'remind_expire',
        'remind_traffic',
        'token',
        'expired_at',
        'next_reset_at',
        'last_reset_at',
        'reset_count',
        'remarks',
        'locale',
        'created_at',
        'updated_at',
    ];

    private const SORTABLE_FIELDS = [
        'id',
        'invite_user_id',
        'telegram_id',
        'email',
        'balance',
        'discount',
        'commission_type',
        'commission_rate',
        'commission_balance',
        't',
        'u',
        'd',
        'total_used',
        'transfer_enable',
        'banned',
        'is_admin',
        'is_staff',
        'is_reseller',
        'last_login_at',
        'last_login_ip',
        'uuid',
        'group_id',
        'plan_id',
        'speed_limit',
        'device_limit',
        'online_count',
        'last_online_at',
        'remind_expire',
        'remind_traffic',
        'token',
        'expired_at',
        'next_reset_at',
        'last_reset_at',
        'reset_count',
        'remarks',
        'locale',
        'created_at',
        'updated_at',
    ];

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $rules = [
            'current' => ['sometimes', 'integer', 'min:1'],
            'pageSize' => ['sometimes', 'integer', 'min:1'],
            'filter' => ['sometimes', 'array'],
            'filter.*.id' => ['required', 'string', Rule::in(self::FILTERABLE_FIELDS)],
            'filter.*.value' => ['present'],
            'filter.*.logic' => ['sometimes', 'string', Rule::in(['and', 'or', 'AND', 'OR'])],
            'sort' => ['sometimes', 'array'],
            'sort.*.id' => ['required', 'string', Rule::in(self::SORTABLE_FIELDS)],
            'sort.*.desc' => ['required', 'boolean'],
        ];

        return HookManager::filter('admin.user.fetch.rules', $rules, $this);
    }

    public function messages()
    {
        return [
            'filter.*.id.required' => __('Filter field is required'),
            'filter.*.id.in' => __('Invalid filter field'),
            'filter.*.value.present' => __('Filter value is required'),
            'filter.*.logic.in' => __('Invalid filter logic'),
            'sort.*.id.required' => __('Sort field is required'),
            'sort.*.id.in' => __('Invalid sort field'),
            'sort.*.desc.required' => __('Sort direction is required'),
            'sort.*.desc.boolean' => __('Invalid sort direction'),
        ];
    }
}
