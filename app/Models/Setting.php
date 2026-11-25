<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'group',
        'type',
        'value',
        'label',
        'description',
        'updated_by',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function castValue(): mixed
    {
        return self::decodeValue($this->value, (string) $this->type);
    }

    public static function decodeValue(?string $raw, string $type): mixed
    {
        if ($raw === null) {
            return $type === 'json' ? [] : null;
        }

        return match ($type) {
            'integer', 'int' => (int) $raw,
            'float', 'decimal', 'double' => (float) $raw,
            'boolean', 'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'json', 'array' => json_decode($raw, true) ?? [],
            default => $raw,
        };
    }

    public static function encodeValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return $type === 'json' ? json_encode([]) : null;
        }

        return match ($type) {
            'integer', 'int' => (string) (int) $value,
            'float', 'decimal', 'double' => (string) (float) $value,
            'boolean', 'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'json', 'array' => json_encode(is_array($value) ? $value : []),
            default => (string) $value,
        };
    }
}
