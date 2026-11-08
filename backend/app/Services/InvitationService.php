<?php

namespace App\Services;

use App\Mail\CollaboratorInvitationMail;
use App\Models\Company;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InvitationService
{
    public function invite(User $admin, string $email, string $role): UserInvitation
    {
        $email = Str::lower(trim($email));
        $plainToken = Str::random(64);
        $invitation = DB::transaction(function () use ($admin, $email, $role, $plainToken): UserInvitation {
            // Serialize concurrent invitations to the same company.
            Company::whereKey($admin->company_id)->lockForUpdate()->firstOrFail();
            if (User::where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => 'Cette adresse e-mail est déjà utilisée.']);
            }

            // Re-inviting revokes earlier unused tokens for that company/e-mail.
            UserInvitation::where('company_id', $admin->company_id)
                ->where('email', $email)->whereNull('accepted_at')->delete();

            return UserInvitation::create([
                'company_id' => $admin->company_id,
                'invited_by' => $admin->id,
                'email' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => now()->addHours(48),
            ]);
        }, 3);

        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $link = $frontend.'/accept-invitation?token='.rawurlencode($plainToken);
        Mail::to($email)->send(new CollaboratorInvitationMail(
            $admin->company->name,
            $role === 'comptable' ? 'comptable' : 'employé(e)',
            $link,
        ));

        return $invitation;
    }

    public function inspect(string $token): ?UserInvitation
    {
        if (strlen($token) !== 64 || ! ctype_alnum($token)) {
            return null;
        }

        return UserInvitation::where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function accept(string $token, string $name, string $password): User
    {
        if (strlen($token) !== 64 || ! ctype_alnum($token)) {
            throw ValidationException::withMessages(['token' => 'Invitation invalide ou expirée.']);
        }

        return DB::transaction(function () use ($token, $name, $password): User {
            $invitation = UserInvitation::where('token_hash', hash('sha256', $token))
                ->lockForUpdate()->first();

            if (! $invitation || $invitation->accepted_at || $invitation->expires_at->isPast()) {
                throw ValidationException::withMessages(['token' => 'Invitation invalide ou expirée.']);
            }
            if (User::where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages(['email' => 'Cette adresse e-mail est déjà utilisée.']);
            }

            $user = new User();
            $user->forceFill([
                'company_id' => $invitation->company_id,
                'name' => $name,
                'email' => $invitation->email,
                'password' => Hash::make($password),
                'role' => $invitation->role,
                'is_active' => true,
                // Possession of the unique invitation link proves access to this address.
                'email_verified_at' => now(),
            ])->save();

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        }, 3);
    }
}
