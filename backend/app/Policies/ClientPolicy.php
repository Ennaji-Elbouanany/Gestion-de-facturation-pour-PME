<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Policies\Concerns\ChecksCompany;
use Illuminate\Auth\Access\Response;

class ClientPolicy
{
    use ChecksCompany;

    public function viewAny(User $user): Response
    {
        return $this->permit($user, $this->everyone());
    }

    public function view(User $user, Client $record): Response
    {
        return $this->permit($user, $this->everyone(), $record);
    }

    public function create(User $user): Response
    {
        return $this->permit($user, ['admin', 'employee', 'comptable']);
    }

    public function update(User $user, Client $record): Response
    {
        return $this->permit($user, ['admin', 'employee', 'comptable'], $record);
    }

    public function archive(User $user, Client $record): Response
    {
        return $this->permit($user, ['admin'], $record);
    }

    public function restore(User $user, Client $record): Response
    {
        return $this->permit($user, ['admin'], $record);
    }
}
