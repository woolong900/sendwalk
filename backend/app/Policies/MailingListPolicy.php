<?php

namespace App\Policies;

use App\Models\User;
use App\Models\MailingList;

class MailingListPolicy
{
    public function view(User $user, MailingList $list): bool
    {
        return $user->id === $list->user_id;
    }

    public function update(User $user, MailingList $list): bool
    {
        return $user->id === $list->user_id;
    }

    public function delete(User $user, MailingList $list): bool
    {
        return $user->id === $list->user_id;
    }
}

