<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Generations\GenerationStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /** Credits bought minus credits spent, in nanodollars. */
    public function balanceNanos(): int
    {
        return (int) $this->creditTransactions()->sum('amount_nanos');
    }

    /**
     * Credits held for generations that are still running. Each holds the most it could
     * cost until it finishes, so a user can't start more than they can pay for.
     */
    public function reservedNanos(): int
    {
        return (int) $this->generations()
            ->whereNotIn('status', [GenerationStatus::Succeeded, GenerationStatus::Failed])
            ->sum('estimated_max_nanos');
    }

    public function availableNanos(): int
    {
        return $this->balanceNanos() - $this->reservedNanos();
    }
}
