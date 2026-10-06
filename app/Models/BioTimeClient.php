<?php

namespace App\Models;

use Database\Factories\BioTimeClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property string $username
 * @property string $password
 * @property string $token
 * @property string $token_hash
 * @property bool $is_active
 */
class BioTimeClient extends Model
{
    /** @use HasFactory<BioTimeClientFactory> */
    use HasFactory;

    protected $fillable = ['company_id', 'username', 'password', 'is_active'];

    protected $hidden = ['password', 'token', 'token_hash'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'token' => 'encrypted', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (BioTimeClient $client): void {
            $client->token = bin2hex(random_bytes(20));
            $client->token_hash = hash('sha256', $client->token);
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
