<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;
use App\Policies\Concerns\ChecksCompany;
use Illuminate\Auth\Access\Response;

class QuotePolicy
{
    use ChecksCompany;

    public function viewAny(User $user): Response
    {
        return $this->permit($user, $this->everyone());
    }

    public function view(User $user, Quote $record): Response
    {
        return $this->permit($user, $this->everyone(), $record);
    }

    public function create(User $user): Response
    {
        return $this->permit($user, ['admin', 'employee']);
    }

    public function update(User $user, Quote $record): Response
    {
        return $this->permit($user, ['admin', 'employee'], $record);
    }

    public function archive(User $user, Quote $record): Response
    {
        return $this->permit($user, ['admin', 'employee'], $record);
    }

    public function restore(User $user, Quote $record): Response
    {
        return $this->permit($user, ['admin', 'employee'], $record);
    }

    public function updateStatus(User $user, Quote $record): Response
    {
        return $this->permit($user, ['admin', 'employee'], $record);
    }

    public function convert(User $user, Quote $record): Response
    {
        return $this->permit($user, ['admin', 'comptable'], $record);
    }
}
