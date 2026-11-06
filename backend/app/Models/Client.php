<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Client extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::deleting(function (Client $client): void {
            if ($client->isForceDeleting()) {
                throw new LogicException('Un client doit être archivé, jamais supprimé définitivement.');
            }
        });
    }

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'phone',
        'tax_number',
        'address',
        'city',
        'country',
    ];


    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
