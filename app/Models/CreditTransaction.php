<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditTransaction extends Model
{
    public const PURCHASE = 'purchase';

    public const CHARGE = 'charge';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_nanos' => 'integer'];
    }
}
