<?php

namespace App\Policies;

use App\Models\{Olt, User};

class OltPolicy
{
    public function view(User $user, Olt $olt): bool { return $user->tenant_id === $olt->tenant_id && $user->allows('read'); }
    public function create(User $user): bool { return $user->allows('manage'); }
    public function update(User $user, Olt $olt): bool { return $this->view($user, $olt) && $user->allows('manage'); }
    public function delete(User $user, Olt $olt): bool { return $this->update($user, $olt); }
    public function sync(User $user, Olt $olt): bool { return $this->view($user, $olt) && $user->allows('operate'); }
    public function cli(User $user, Olt $olt): bool { return $this->view($user, $olt) && $user->allows('cli'); }
}
