<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;

class AuditRbacAccounts extends Command
{
    protected $signature = 'rbac:audit-accounts';
    protected $description = 'Vérifier les rôles et entreprises sans modifier les comptes';

    public function handle(): int
    {
        try {
            return $this->audit();
        } catch (\Throwable $exception) {
            $this->error('Audit indisponible pour la connexion configurée. Aucun compte modifié.');
            return self::FAILURE;
        }
    }

    private function audit(): int
    {
        $rows = User::query()->selectRaw('company_id, role, COUNT(*) AS total')->groupBy('company_id', 'role')->get();
        $this->table(['Entreprise ID', 'Rôle', 'Comptes'], $rows->map(fn ($row) => [
            $row->company_id ?? 'sans entreprise', $row->role ?? 'inconnu', $row->total,
        ])->all());
        $missing = Company::query()->whereDoesntHave('users', fn ($query) => $query->where('role', 'admin'))->pluck('id');
        $invalid = User::query()->where(function ($query) {
            $query->whereNull('role')->orWhereNotIn('role', ['admin', 'employee', 'comptable'])
                ->orWhereNull('company_id')->orWhereDoesntHave('company');
        })->count();
        if ($missing->isNotEmpty()) {
            $this->warn('Entreprises sans administrateur : '.$missing->implode(', '));
        }
        if ($invalid > 0) {
            $this->warn("Comptes avec rôle ou entreprise invalide : {$invalid}");
        }
        $this->info('Audit en lecture seule : aucun rôle et aucune donnée modifiés.');

        return $missing->isNotEmpty() || $invalid > 0 ? self::FAILURE : self::SUCCESS;
    }
}
