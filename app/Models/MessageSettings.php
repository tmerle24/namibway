<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $signature
 */
class MessageSettings extends Model
{
    protected $fillable = [
        'signature',
    ];

    private const DEFAULT_SIGNATURE = "Thanks,\nNamibWay";

    /** Always the same row — settings, not records. */
    public static function current(): self
    {
        return self::query()->firstOrCreate([], ['signature' => self::DEFAULT_SIGNATURE]);
    }

    public function getSignatureOrDefault(): string
    {
        return filled($this->signature) ? $this->signature : self::DEFAULT_SIGNATURE;
    }
}
