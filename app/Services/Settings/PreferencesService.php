<?php

namespace App\Services\Settings;

use App\Models\User;

class PreferencesService
{
    public function updateFont(User $user, string $fontFamily): User
    {
        $user->font_family = $fontFamily;
        $user->save();

        return $user->refresh();
    }
}
