<?php

namespace App\Enum;

enum AuthOtpPurpose: string
{
    case EMAIL_VERIFICATION = 'email_verification';
    case PASSWORD_RESET = 'password_reset';

    public function expiresInMinutes(): int
    {
        $default = $this === self::EMAIL_VERIFICATION ? 60 : 10;

        return (int) config("auth.otp.{$this->value}.expire", $default);
    }
}
