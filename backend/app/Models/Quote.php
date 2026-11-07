<?php

namespace App\Models;

use App\Models\Concerns\PreventsPhysicalDeletion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Quote extends Model
{
    use HasFactory, PreventsPhysicalDeletion;

    protected $fillable = [
        'company_id',
        'client_id',
        'user_id',
        'quote_number',
        'quote_date',
        'valid_until',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quote_date' => 'date',
            'valid_until' => 'date',
            'archived_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Quote $quote): void {
            abort_if($quote->status === 'converted', 422, 'Une conversion exige une facture liée.');
        });
        static::updating(function (Quote $quote): void {
            abort_if($quote->isDirty('quote_number'), 409, 'Le numéro du devis est permanent.');
            $original = $quote->getRawOriginal('status');
            if ($original !== 'draft' && $quote->isDirty(['company_id', 'client_id', 'quote_number', 'quote_date', 'valid_until', 'subtotal', 'tax_amount', 'discount_amount', 'total_amount', 'notes'])) {
                abort(409, 'Le contenu d’un devis envoyé est figé.');
            }
            if ($quote->isDirty('status')) {
                $allowed = ['draft' => ['sent'], 'sent' => ['accepted', 'rejected', 'expired'], 'accepted' => ['converted']];
                abort_unless(in_array($quote->status, $allowed[$original] ?? [], true), 409, 'Transition commerciale interdite.');
                if ($quote->status === 'converted') {
                    abort_unless($quote->invoice()->exists(), 409, 'La conversion exige une facture liée.');
                }
            }
        });
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'source_quote_id')->select(['id', 'company_id', 'source_quote_id', 'invoice_number']);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Relation used by scoped route binding for the {quoteItem} parameter.
    public function quoteItems(): HasMany
    {
        return $this->items();
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }
}
