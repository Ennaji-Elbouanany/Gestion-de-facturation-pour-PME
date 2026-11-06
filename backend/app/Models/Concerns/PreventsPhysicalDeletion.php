<?php

namespace App\Models\Concerns;

use LogicException;

trait PreventsPhysicalDeletion
{
    public static function bootPreventsPhysicalDeletion(): void
    {
        static::deleting(function (): void {
            throw new LogicException('Cet enregistrement financier doit être conservé.');
        });
    }
}
