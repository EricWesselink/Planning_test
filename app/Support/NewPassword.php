<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

class NewPassword
{
    /**
     * Rules for a password that is being set or changed.
     * Existing passwords are not checked against this on login.
     *
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return ['required', 'string', Password::min(10), 'confirmed'];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'password.required' => 'Vul een nieuw wachtwoord in.',
            'password.min' => 'Het wachtwoord moet minstens 10 tekens zijn.',
            'password.confirmed' => 'De wachtwoorden komen niet overeen.',
        ];
    }
}
