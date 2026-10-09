<?php

namespace App\Actions\Account;

use App\Models\User;

class UpdateEmailAddress
{
    public function handle(User $user, string $email): void
    {
        $user->forceFill([
            'email' => $email,
            'email_verified_at' => null,
        ])->save();
    }
}
