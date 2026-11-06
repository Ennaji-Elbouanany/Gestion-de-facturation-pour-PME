<?php

namespace App\Policies\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

trait ChecksCompany
{
    private function permit(User $user, array $roles, ?Model $resource = null): Response
    {
        $companyId = $resource instanceof \App\Models\Company ? $resource->id : $resource?->company_id;
        if ($resource !== null && ($user->company_id === null || (string) $companyId !== (string) $user->company_id)) {
            return Response::denyAsNotFound();
        }
        if ($user->company_id === null || $user->company === null
            || UserRole::tryFrom((string) $user->role) === null || ! in_array($user->role, $roles, true)) {
            return Response::deny('Vous ne disposez pas des permissions nécessaires pour cette action.');
        }

        return Response::allow();
    }

    private function everyone(): array
    {
        return array_column(UserRole::cases(), 'value');
    }
}
