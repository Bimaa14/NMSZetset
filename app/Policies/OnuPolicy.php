<?php

namespace App\Policies;

use App\Models\{Onu, User};

class OnuPolicy
{
    public function reboot(User $user, Onu $onu): bool
    {
        return $user->tenant_id === $onu->tenant_id && $user->allows('operate');
    }

    public function tag(User $user, Onu $onu): bool
    {
        return $user->tenant_id === $onu->tenant_id && $user->allows('tags');
    }
}
