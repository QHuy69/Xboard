<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class GiftCardRedeemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'code' => 'required|string|min:8|max:32',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'code.required' => __('Gift card code is required'),
            'code.min' => __('Gift card code must be at least 8 characters'),
            'code.max' => __('Gift card code cannot exceed 32 characters'),
        ];
    }
}
