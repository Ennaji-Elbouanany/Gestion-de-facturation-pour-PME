<?php

namespace App\Support;

use Illuminate\Http\Request;

final class BusinessValidation
{
    public static function normalizeEmail(Request $request): void
    {
        if ($request->exists('email') && is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email'))) ?: null]);
        }
    }

    public static function messages(): array
    {
        return ['password' => 'Utilisez au moins 12 caractères, une majuscule, une minuscule, un chiffre et un symbole.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.', 'required' => 'Le champ :attribute est obligatoire.', 'email' => 'Saisissez une adresse e-mail valide.',
            'unique' => 'Cette adresse e-mail est déjà utilisée dans votre entreprise, y compris dans les archives.',
            'string' => 'Le champ :attribute doit être du texte.', 'numeric' => 'Saisissez un nombre valide pour :attribute.',
            'regex' => 'Le format de :attribute est invalide.', 'min' => 'Le champ :attribute doit être au moins égal à :min.',
            'max' => 'Le champ :attribute dépasse la limite autorisée (:max).', 'integer' => 'Le champ :attribute doit être un entier.',
            'in' => 'La valeur de :attribute n’est pas autorisée.', 'boolean' => 'Le champ :attribute doit être vrai ou faux.',
            'url' => 'Saisissez une URL HTTP ou HTTPS valide.', 'confirmed' => 'La confirmation du mot de passe ne correspond pas.'];
    }

    public static function attributes(): array
    {
        return ['name' => 'nom', 'unit_price' => 'prix unitaire', 'tax_rate' => 'taux de TVA', 'default_tax_rate' => 'TVA par défaut',
            'payment_terms_days' => 'délai de paiement', 'invoice_prefix' => 'préfixe', 'current_password' => 'mot de passe actuel',
            'password' => 'mot de passe', 'phone' => 'téléphone', 'tax_number' => 'identifiant fiscal'];
    }

    public static function phone(): array
    {
        return ['nullable', 'string', 'max:30', 'regex:/^(?=.*[0-9])[+0-9(). \-]{5,30}$/D'];
    }

    public static function fiscal(): array
    {
        return ['nullable', 'string', 'max:100', 'regex:/^[\pL\pN][\pL\pN .\/_-]*$/uD'];
    }
}
