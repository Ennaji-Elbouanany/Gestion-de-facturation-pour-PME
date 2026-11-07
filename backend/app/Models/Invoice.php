<?php

namespace App\Models;

use App\Models\Concerns\PreventsPhysicalDeletion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory, PreventsPhysicalDeletion;

    protected $fillable = [
        'company_id',
        'client_id',
        'user_id',
        'invoice_number',
        'invoice_date',
        'due_date',
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
            'issued_at' => 'datetime',
            'issued_snapshot' => 'array',
            'invoice_date' => 'date',
            'due_date' => 'date',
            'archived_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function getDocumentStatusAttribute(?string $value): string
    {
        return $value ?? match ($this->attributes['status'] ?? 'draft') {
            'draft' => 'draft', 'cancelled' => 'cancelled', default => 'issued',
        };
    }

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice): void {
            abort_if($invoice->isDirty('invoice_number'), 409, 'Le numéro de facture est permanent.');
            abort_if($invoice->isDirty('source_quote_id'), 409, 'Le lien de conversion est permanent.');
            $state = $invoice->getRawOriginal('document_status') ?? match ($invoice->getRawOriginal('status')) {
                'draft' => 'draft', 'cancelled' => 'cancelled', default => 'issued',
            };
            if ($state !== 'draft' && $invoice->isDirty(['client_id', 'invoice_number', 'invoice_date', 'due_date',
                'subtotal', 'tax_amount', 'discount_amount', 'total_amount', 'notes', 'issued_snapshot', 'issued_at'])) {
                abort(409, 'Le contenu d’une facture émise ou annulée est figé.');
            }
            if ($state !== 'draft' && $invoice->isDirty('document_status') && $invoice->document_status === 'draft') {
                abort(409, 'Une facture émise ne peut pas redevenir un brouillon.');
            }
        });
    }

    public function sourceQuote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'source_quote_id');
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

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
