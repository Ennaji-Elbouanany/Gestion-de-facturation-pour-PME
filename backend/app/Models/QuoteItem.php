<?php

namespace App\Models;

use App\Services\QuoteService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quote_id',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'tax_rate',
        'subtotal',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (QuoteItem $item): void {
            abort_if($item->exists && $item->isDirty('quote_id'), 409, 'Le parent d’une ligne de devis est permanent.');
            $quote = Quote::whereKey($item->quote_id)->lockForUpdate()->firstOrFail();
            QuoteService::editable($quote);
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
