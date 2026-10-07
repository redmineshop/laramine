<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'password_confirmation' => ['required', 'string'],
            'firstname' => ['required', 'string'],
            'lastname' => ['required', 'string'],
            'mail' => ['required', 'string'],
        ];
    }
}
