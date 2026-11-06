<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\ChecksCompany;
use Illuminate\Auth\Access\Response;

class InvoicePolicy
{
    use ChecksCompany;

    public function viewAny(User $user): Response
    {
        return $this->permit($user, $this->everyone());
    }

    public function view(User $user, Invoice $record): Response
    {
        return $this->permit($user, $this->everyone(), $record);
    }

    public function create(User $user): Response
    {
        return $this->permit($user, ['admin', 'employee', 'comptable']);
    }

    public function update(User $user, Invoice $record): Response
    {
        $roles = $record->status === 'draft' ? $this->everyone() : ['admin', 'comptable'];
        return $this->permit($user, $roles, $record);
    }

    public function archive(User $user, Invoice $record): Response
    {
        return $this->permit($user, ['admin', 'employee', 'comptable'], $record);
    }

    public function restore(User $user, Invoice $record): Response
    {
        return $this->permit($user, ['admin', 'employee', 'comptable'], $record);
    }

    public function updateStatus(User $user, Invoice $record): Response
    {
        return $this->permit($user, ['admin', 'comptable'], $record);
    }

    public function updateAny(User $user): Response
    {
        return $this->permit($user, ['admin', 'comptable']);
    }
}
