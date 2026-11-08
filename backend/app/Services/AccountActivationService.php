<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AccountActivationService
{
    /**
     * Future collaborator-management API can call this after authorizing the
     * acting administrator and checking tenant/last-admin invariants.
     * Lock the user and revoke all tokens atomically with deactivation.
     */
    public function deactivate(int $userId): User
    {
        return DB::transaction(function () use ($userId): User {
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $user->forceFill(['is_active' => false])->save();
            $user->tokens()->delete();

            return $user;
        }, 3);
    }
}
