<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;

final class UserPermissions
{
    public static function for(User $user): array
    {
        $permissions = [];
        foreach (['clients' => \App\Models\Client::class, 'products' => \App\Models\Product::class,
            'invoices' => Invoice::class, 'quotes' => \App\Models\Quote::class,
            'payments' => \App\Models\Payment::class] as $resource => $model) {
            $permissions[$resource.'.view'] = $user->can('viewAny', $model);
            if ($resource !== 'payments') {
                $permissions[$resource.'.create'] = $user->can('create', $model);
            }
        }
        // Synthetic resources contain no financial data and are never cached.
        $client = new \App\Models\Client(['company_id' => $user->company_id]);
        $product = new \App\Models\Product(['company_id' => $user->company_id]);
        $quote = new \App\Models\Quote(['company_id' => $user->company_id]);
        $draft = new Invoice(['company_id' => $user->company_id, 'status' => 'draft']);
        foreach (['archive', 'restore'] as $ability) {
            $permissions['clients.'.$ability] = $user->can($ability, $client);
            $permissions['invoices.'.$ability] = $user->can($ability, $draft);
            $permissions['quotes.'.$ability] = $user->can($ability, $quote);
        }
        $permissions['clients.update'] = $user->can('update', $client);
        $permissions['products.update'] = $user->can('update', $product);
        $permissions['products.delete'] = $user->can('delete', $product);
        $permissions['invoices.update'] = $user->can('updateAny', Invoice::class);
        $permissions['invoices.updateDraft'] = $user->can('update', $draft);
        $permissions['invoices.updateStatus'] = $user->can('updateStatus', $draft);
        $permissions['quotes.update'] = $user->can('update', $quote);
        $permissions['quotes.updateStatus'] = $user->can('updateStatus', $quote);
        $permissions['quotes.convert'] = $user->can('convert', $quote);
        $permissions['payments.create'] = $user->can('create', [\App\Models\Payment::class, $draft]);
        $permissions['payments.update'] = $permissions['payments.create'];
        $permissions['dashboard.financial'] = $user->can('viewFinancialStatistics', Company::class);
        $permissions['dashboard.view'] = $user->can('viewAny', Company::class);
        foreach (['update', 'updateBilling', 'updateDocuments', 'updateNotifications'] as $ability) {
            $permissions['settings.'.$ability] = $user->company !== null && $user->can($ability, $user->company);
        }
        $permissions['profile.update'] = $user->can('update', $user);
        $permissions['profile.updateSecurity'] = $user->can('updateSecurity', $user);

        return $permissions;
    }
}
