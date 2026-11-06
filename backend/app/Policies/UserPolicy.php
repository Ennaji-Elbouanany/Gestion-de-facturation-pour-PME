<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    public function update(User $user, User $target): Response
    {
        return $user->id === $target->id ? Response::allow() : Response::denyAsNotFound();
    }

    public function updateSecurity(User $user, User $target): Response
    {
        return $this->update($user, $target);
    }
}
