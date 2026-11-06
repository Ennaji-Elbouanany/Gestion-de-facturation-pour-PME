<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Policies\Concerns\ChecksCompany;
use Illuminate\Auth\Access\Response;

class CompanyPolicy
{
    use ChecksCompany;

    public function viewAny(User $user): Response
    {
        return $this->permit($user, $this->everyone());
    }

    public function view(User $user, Company $company): Response
    {
        return $this->permit($user, $this->everyone(), $company);
    }

    public function viewFinancialStatistics(User $user): Response
    {
        return $this->permit($user, ['admin', 'comptable']);
    }

    public function update(User $user, Company $company): Response
    {
        return $this->permit($user, ['admin'], $company);
    }

    public function updateBilling(User $user, Company $company): Response
    {
        return $this->permit($user, ['admin', 'comptable'], $company);
    }

    public function updateDocuments(User $user, Company $company): Response
    {
        return $this->update($user, $company);
    }

    public function updateNotifications(User $user, Company $company): Response
    {
        return $this->update($user, $company);
    }
}
