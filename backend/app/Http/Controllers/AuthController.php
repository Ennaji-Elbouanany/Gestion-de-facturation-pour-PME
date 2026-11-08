<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Services\AccountSecurityService;
use App\Support\BusinessValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function forgotPassword(Request $request): JsonResponse
    {
        BusinessValidation::normalizeEmail($request);

        $data = $request->validate([
            'email' => ['required', 'email'],
        ], ['unique' => 'Cette adresse e-mail est déjà utilisée par un compte.', ...array_diff_key(BusinessValidation::messages(), ['unique' => true])], BusinessValidation::attributes());

        try {
            $status = Password::sendResetLink($data);
        } catch (\Throwable $e) {
            Log::error('Échec envoi du lien de réinitialisation.', [
                'email' => $data['email'],
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'Une erreur technique est survenue lors de l’envoi du lien. Vérifiez la configuration de messagerie (SMTP).',
            ], 500);
        }

        if (in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return response()->json([
                'message' => 'Si cette adresse existe, un lien de réinitialisation a été envoyé par e-mail.',
            ]);
        }

        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'message' => 'Trop de tentatives. Veuillez patienter une minute avant de réessayer.',
            ], 429);
        }

        return response()->json([
            'message' => 'Impossible d’envoyer le lien de réinitialisation. Vérifiez que l’adresse e-mail est correcte.',
        ], 422);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        BusinessValidation::normalizeEmail($request);

        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', PasswordRule::min(12)->mixedCase()->numbers()->symbols(), 'confirmed'],
        ], ['unique' => 'Cette adresse e-mail est déjà utilisée par un compte.', ...array_diff_key(BusinessValidation::messages(), ['unique' => true])], BusinessValidation::attributes());

        $status = app(AccountSecurityService::class)->reset($data);

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Le lien est invalide ou a expiré.',
            ], 422);
        }

        return response()->json([
            'message' => 'Votre mot de passe a été réinitialisé avec succès.',
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        BusinessValidation::normalizeEmail($request);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], ['unique' => 'Cette adresse e-mail est déjà utilisée par un compte.', ...array_diff_key(BusinessValidation::messages(), ['unique' => true])], BusinessValidation::attributes());

        $result = app(AccountSecurityService::class)->login($credentials);
        if (! $result) {
            return response()->json(['message' => 'Adresse e-mail ou mot de passe incorrect.'], 401);
        }

        return response()->json(['message' => 'Connexion réussie.', ...$result, 'token_type' => 'Bearer']);
    }

    public function logout(Request $request): JsonResponse
    {
        // Revoke only the bearer token used by the current device/tab.
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Déconnexion réussie.',
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        BusinessValidation::normalizeEmail($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'company_name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', PasswordRule::min(12)->mixedCase()->numbers()->symbols(), 'confirmed'],
            'role' => ['prohibited'],
            'company_id' => ['prohibited'],
            'permissions' => ['prohibited'],
        ], ['unique' => 'Cette adresse e-mail est déjà utilisée par un compte.', ...array_diff_key(BusinessValidation::messages(), ['unique' => true])], BusinessValidation::attributes());

        $result = DB::transaction(function () use ($data): array {
            $company = Company::create([
                'name' => $data['company_name'],
                'email' => $data['email'],
            ]);

            $user = (new User)->forceFill([
                'company_id' => $company->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => UserRole::Admin->value,
                'is_active' => true,
            ]);
            $user->save();

            return compact('company', 'user');
        });

        try {
            $result['user']->sendEmailVerificationNotification();
        } catch (\Throwable $exception) {
            Log::error('Échec de l’envoi de l’e-mail de vérification.', [
                'exception' => $exception,
            ]);
        }

        $accessToken = $result['user']->createToken('ffactura-spa', ['*'], now()->addHours(12));

        return response()->json([
            'message' => 'Compte créé avec succès.',
            'user' => $result['user'],
            'token' => $accessToken->plainTextToken,
            'token_type' => 'Bearer',
        ], 201);
    }
}
