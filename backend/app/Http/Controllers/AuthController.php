<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            $status = Password::sendResetLink($data);
        } catch (\Throwable $e) {
            Log::error('Échec envoi du lien de réinitialisation.', [
                'email' => $data['email'],
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'Une erreur technique est survenue lors de l’envoi du lien. Vérifiez la configuration de messagerie (SMTP).',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        if ($status === Password::RESET_LINK_SENT) {
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
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $data,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();

                $user->tokens()->delete();
            }
        );

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
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Adresse e-mail ou mot de passe incorrect.',
            ], 401);
        }

        $token = $user->createToken('frontend')->plainTextToken;

        return response()->json([
            'message' => 'Connexion réussie.',
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Déconnexion réussie.',
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'company_name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', 'string', 'in:employee,admin,comptable'],
        ]);

        $result = DB::transaction(function () use ($data): array {
            $company = Company::create([
                'name' => $data['company_name'],
                'email' => $data['email'],
            ]);

            $user = User::create([
                'company_id' => $company->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => $data['role'],
            ]);

            return compact('company', 'user');
        });

        $token = $result['user']->createToken('frontend')->plainTextToken;

        return response()->json([
            'message' => 'Compte créé avec succès.',
            'token' => $token,
            'user_id' => $result['user']->id,
            'company' => $result['company'],
            'user' => $result['user'],
        ], 201);
    }
}
