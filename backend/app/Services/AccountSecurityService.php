<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountSecurityService
{
    public function login(#[\SensitiveParameter] array $credentials): ?array
    {
        return FinancialTransaction::run(function () use ($credentials): ?array {
            $user = User::where('email', $credentials['email'])->lockForUpdate()->first();
            if (! $user || ! Hash::check($credentials['password'], $user->password)) {
                return null;
            }
            $token = $user->createToken('ffactura-spa', ['*'], now()->addHours(12));

            return ['user' => $user, 'token' => $token->plainTextToken];
        });
    }

    public function changePassword(int $userId, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $password): void
    {
        FinancialTransaction::run(function () use ($userId, $current, $password): void {
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $this->checkPassword($user, $current);
            $this->replacePassword($user, $password);
        });
    }

    public function reset(#[\SensitiveParameter] array $data): string
    {
        return FinancialTransaction::run(function () use ($data): string {
            // Lock before the broker validates the reset token; concurrent resets cannot reuse it.
            User::where('email', $data['email'])->lockForUpdate()->first();

            return Password::reset($data, fn (User $user, #[\SensitiveParameter] string $password) => $this->replacePassword($user, $password));
        });
    }

    public function profile(int $userId, #[\SensitiveParameter] array $data): array
    {
        try {
            return FinancialTransaction::run(function () use ($userId, $data): array {
                $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
                $changed = $data['email'] !== $user->email;
                if ($changed) {
                    $this->checkPassword($user, $data['current_password'] ?? '');
                    if (User::where('email', $data['email'])->whereKeyNot($user->id)->exists()) {
                        throw ValidationException::withMessages(['email' => 'Cette adresse e-mail est déjà utilisée.']);
                    }
                    $user->email_verified_at = null;
                }
                $user->fill(['name' => $data['name'], 'email' => $data['email']])->save();

                return ['user' => $user, 'email_changed' => $changed];
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['email' => 'Cette adresse e-mail est déjà utilisée.']);
        }
    }

    private function checkPassword(User $user, #[\SensitiveParameter] string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Le mot de passe actuel est incorrect.']);
        }
    }

    private function replacePassword(User $user, #[\SensitiveParameter] string $password): void
    {
        $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
        $user->tokens()->delete();
    }
}
