<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LikeStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'post_id' => ['required', 'integer', 'exists:posts,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
