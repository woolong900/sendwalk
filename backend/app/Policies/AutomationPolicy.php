<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Automation;

class AutomationPolicy
{
    public function view(User $user, Automation $automation): bool
    {
        return $user->id === $automation->user_id;
    }

    public function update(User $user, Automation $automation): bool
    {
        return $user->id === $automation->user_id;
    }

    public function delete(User $user, Automation $automation): bool
    {
        return $user->id === $automation->user_id;
    }
}

