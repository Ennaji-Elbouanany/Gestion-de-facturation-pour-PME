<?php

namespace App\Models;

use App\Models\Concerns\PreventsPhysicalDeletion;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class PaymentRevision extends Model
{
    use PreventsPhysicalDeletion;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une correction financière est immuable.'));
    }
}
