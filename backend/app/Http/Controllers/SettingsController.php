<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Services\AccountSecurityService;
use App\Services\MoneyCalculator;
use App\Support\BusinessValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Company::class);
        $request->validate(['role' => ['prohibited'], 'company_id' => ['prohibited'], 'permissions' => ['prohibited']]);
        $user = $request->user();

        return response()->json([
            'user' => $user,
            'company' => $user->company,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $this->authorize('update', $request->user());
        $request->validate(['role' => ['prohibited'], 'company_id' => ['prohibited'], 'permissions' => ['prohibited']]);
        $user = $request->user();
        BusinessValidation::normalizeEmail($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'current_password' => ['sometimes', 'nullable', 'string', 'max:1024'],
        ], BusinessValidation::messages(), BusinessValidation::attributes());

        $result = app(AccountSecurityService::class)->profile($user->id, $data);
        $sent = $result['email_changed'] ? $this->sendVerification($result['user']) : null;

        return response()->json([
            'message' => $result['email_changed']
                ? ($sent ? 'Profil mis à jour. Vérifiez votre nouvelle adresse e-mail.' : 'Profil mis à jour. Envoi du lien indisponible ; utilisez le bouton de renvoi.')
                : 'Profil mis à jour avec succès.',
            ...$result,
            'verification_email_sent' => $sent,
        ]);
    }

    public function updateCompany(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Company::class);
        $this->authorize('update', $request->user()->company);
        $request->validate(['role' => ['prohibited'], 'company_id' => ['prohibited'], 'permissions' => ['prohibited']]);
        $company = $request->user()->company;

        BusinessValidation::normalizeEmail($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => BusinessValidation::phone(),
            'tax_number' => BusinessValidation::fiscal(),
            'ice' => BusinessValidation::fiscal(),
            'address' => ['nullable', 'string', 'max:5000'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
        ], BusinessValidation::messages(), BusinessValidation::attributes());

        $company = DB::transaction(function () use ($company, $data): Company {
            $locked = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $locked->update($data);

            return $locked;
        }, 3);

        return response()->json([
            'message' => 'Informations de l’entreprise mises à jour avec succès.',
            'company' => $company->fresh(),
        ]);
    }

    public function updateBilling(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Company::class);
        $this->authorize('updateBilling', $request->user()->company);
        $request->validate(['role' => ['prohibited'], 'company_id' => ['prohibited'], 'permissions' => ['prohibited']]);
        $company = $request->user()->company;

        $data = $request->validate([
            'payment_terms_days' => ['sometimes', 'required', 'integer', 'min:0', 'max:365'],
            'default_tax_rate' => ['sometimes', 'nullable', ...MoneyCalculator::rules(), 'max:100'],
            'currency' => ['sometimes', 'required', Rule::in(['MAD', 'EUR', 'USD'])],
        ], BusinessValidation::messages(), BusinessValidation::attributes());

        $company = DB::transaction(function () use ($company, $data): Company {
            $locked = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $locked->update($data);

            return $locked;
        }, 3);

        return response()->json([
            'message' => 'Paramètres de facturation mis à jour avec succès.',
            'company' => $company->fresh(),
        ]);
    }

    public function updateDocuments(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Company::class);
        $this->authorize('updateDocuments', $request->user()->company);
        $request->validate(['role' => ['prohibited'], 'company_id' => ['prohibited'], 'permissions' => ['prohibited']]);
        $company = $request->user()->company;

        $data = $request->validate([
            'invoice_prefix' => ['sometimes', 'required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'invoice_footer' => ['nullable', 'string', 'max:5000'],
            'logo' => ['nullable', 'url:http,https', 'max:255'],
        ], BusinessValidation::messages(), BusinessValidation::attributes());

        $company = DB::transaction(function () use ($company, $data): Company {
            $locked = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $locked->update($data);

            return $locked;
        }, 3);

        return response()->json([
            'message' => 'Paramètres des documents mis à jour avec succès.',
            'company' => $company->fresh(),
        ]);
    }

    public function updateNotifications(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Company::class);
        $this->authorize('updateNotifications', $request->user()->company);
        $request->validate(['role' => ['prohibited'], 'company_id' => ['prohibited'], 'permissions' => ['prohibited']]);
        $company = $request->user()->company;

        $data = $request->validate([
            'notify_on_new_invoice' => ['sometimes', 'required', 'boolean'],
            'notify_on_payment' => ['sometimes', 'required', 'boolean'],
            'notify_on_quote' => ['sometimes', 'required', 'boolean'],
            'notify_on_overdue' => ['sometimes', 'required', 'boolean'],
        ], BusinessValidation::messages(), BusinessValidation::attributes());

        $company = DB::transaction(function () use ($company, $data): Company {
            $locked = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $locked->update($data);

            return $locked;
        }, 3);

        return response()->json([
            'message' => 'Préférences de notification mises à jour avec succès.',
            'company' => $company->fresh(),
        ]);
    }

    public function updateSecurity(Request $request): JsonResponse
    {
        $this->authorize('updateSecurity', $request->user());
        $request->validate(['role' => ['prohibited'], 'company_id' => ['prohibited'], 'permissions' => ['prohibited']]);
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', PasswordRule::min(12)->mixedCase()->numbers()->symbols(), 'confirmed'],
        ], BusinessValidation::messages(), BusinessValidation::attributes());

        app(AccountSecurityService::class)->changePassword($user->id, $data['current_password'], $data['password']);

        return response()->json([
            'message' => 'Mot de passe modifié. Veuillez vous reconnecter.',
            'reauthenticate' => true,
        ]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Votre adresse e-mail est déjà vérifiée.']);
        }
        if (! $this->sendVerification($user)) {
            return response()->json(['message' => 'Envoi indisponible. Réessayez ultérieurement.'], 503);
        }

        return response()->json(['message' => 'Lien de vérification envoyé.']);
    }

    private function sendVerification(User $user): bool
    {
        try {
            $user->sendEmailVerificationNotification();

            return true;
        } catch (\Throwable $exception) {
            Log::error('Envoi de vérification impossible.', ['exception' => $exception]);

            return false;
        }
    }
}
