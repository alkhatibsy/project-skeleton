<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function updateEmail(User $user, User $model): bool
    {
        return $user->is($model);
    }
}
