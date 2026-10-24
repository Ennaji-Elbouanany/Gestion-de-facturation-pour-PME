<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $user,
            'company' => $user->company,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $conflict = User::where('email', $data['email'])
            ->where('id', '!=', $user->id)
            ->first();

        if ($conflict) {
            return response()->json([
                'message' => 'Cette adresse e-mail est déjà utilisée par un autre compte.',
                'errors' => [
                    'email' => ['Cette adresse e-mail est déjà utilisée par un autre compte.'],
                ],
            ], 422);
        }

        $user->update($data);

        return response()->json([
            'message' => 'Profil mis à jour avec succès.',
            'user' => $user->fresh(),
        ]);
    }

    public function updateCompany(Request $request): JsonResponse
    {
        $company = $request->user()->company;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'ice' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
        ]);

        $company->update($data);

        return response()->json([
            'message' => 'Informations de l’entreprise mises à jour avec succès.',
            'company' => $company->fresh(),
        ]);
    }

    public function updateBilling(Request $request): JsonResponse
    {
        $company = $request->user()->company;

        $data = $request->validate([
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'default_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => ['nullable', 'string', 'max:10'],
        ]);

        $company->update($data);

        return response()->json([
            'message' => 'Paramètres de facturation mis à jour avec succès.',
            'company' => $company->fresh(),
        ]);
    }

    public function updateDocuments(Request $request): JsonResponse
    {
        $company = $request->user()->company;

        $data = $request->validate([
            'invoice_prefix' => ['nullable', 'string', 'max:20'],
            'invoice_footer' => ['nullable', 'string'],
            'logo' => ['nullable', 'string', 'max:255'],
        ]);

        $company->update($data);

        return response()->json([
            'message' => 'Paramètres des documents mis à jour avec succès.',
            'company' => $company->fresh(),
        ]);
    }

    public function updateNotifications(Request $request): JsonResponse
    {
        $company = $request->user()->company;

        $data = $request->validate([
            'notify_on_new_invoice' => ['nullable', 'boolean'],
            'notify_on_payment' => ['nullable', 'boolean'],
            'notify_on_quote' => ['nullable', 'boolean'],
            'notify_on_overdue' => ['nullable', 'boolean'],
        ]);

        $company->update($data);

        return response()->json([
            'message' => 'Préférences de notification mises à jour avec succès.',
            'company' => $company->fresh(),
        ]);
    }

    public function updateSecurity(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Le mot de passe actuel est incorrect.',
                'errors' => [
                    'current_password' => ['Le mot de passe actuel est incorrect.'],
                ],
            ], 422);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
        ])->save();

        return response()->json([
            'message' => 'Mot de passe modifié avec succès.',
        ]);
    }
}