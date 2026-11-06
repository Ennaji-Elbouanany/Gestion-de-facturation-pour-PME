<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Policies\Concerns\ChecksCompany;
use Illuminate\Auth\Access\Response;

class ProductPolicy
{
    use ChecksCompany;

    public function viewAny(User $user): Response
    {
        return $this->permit($user, $this->everyone());
    }

    public function view(User $user, Product $record): Response
    {
        return $this->permit($user, $this->everyone(), $record);
    }

    public function create(User $user): Response
    {
        return $this->permit($user, ['admin', 'comptable']);
    }

    public function update(User $user, Product $record): Response
    {
        return $this->permit($user, ['admin', 'comptable'], $record);
    }

    public function delete(User $user, Product $record): Response
    {
        return $this->permit($user, ['admin'], $record);
    }
}
