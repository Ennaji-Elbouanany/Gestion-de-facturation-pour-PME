<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'tax_number',
        'ice',
        'payment_terms_days',
        'default_tax_rate',
        'currency',
        'invoice_prefix',
        'invoice_footer',
        'notify_on_new_invoice',
        'notify_on_payment',
        'notify_on_quote',
        'notify_on_overdue',
        'logo',
    ];

    protected function casts(): array
    {
        return ['payment_terms_days' => 'integer', 'default_tax_rate' => 'decimal:2',
            'notify_on_new_invoice' => 'boolean', 'notify_on_payment' => 'boolean',
            'notify_on_quote' => 'boolean', 'notify_on_overdue' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
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
