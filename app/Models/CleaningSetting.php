<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Scoring settings that can be changed at runtime — no code edit, no deploy.
 *
 * Read through CleaningSetting::get(). The whole table is loaded once per
 * request and cached in memory, because buildGrid() asks for these values once
 * per store row and a query each time would be silly for four rows.
 */
class CleaningSetting extends Model
{
    protected $table = 'cleaning_settings';

    protected $fillable = ['key', 'value', 'updated_by'];

    /**
     * The shipped defaults. A key missing from the table falls back to here, so
     * the system is fully functional before anyone has ever saved a setting —
     * and a typo in the table can never take scoring down.
     */
    public const DEFAULTS = [
        // 'average' = (items + chart) / 2   ·   'excel' = the old commitment-point formula
        'score_formula' => 'average',

        // How the two sides split the final score. Must add up to 100.
        'items_share' => '50',
        'chart_share' => '50',
    ];

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::values()[$key] ?? $default ?? self::DEFAULTS[$key] ?? null;
    }

    public static function getInt(string $key): int
    {
        return (int) static::get($key);
    }

    public static function getBool(string $key): bool
    {
        return filter_var(static::get($key), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<string,string>
     */
    public static function values(): array
    {
        if (static::$cache !== null) {
            return static::$cache;
        }

        try {
            $stored = static::query()->pluck('value', 'key')->all();
        } catch (Throwable $e) {
            // Table not migrated yet (or unreachable) — never let settings take
            // scoring down; the defaults are always a valid configuration.
            $stored = [];
        }

        return static::$cache = array_merge(self::DEFAULTS, array_filter(
            $stored,
            fn ($v) => $v !== null && $v !== ''
        ));
    }

    public static function put(string $key, mixed $value, ?int $userId = null): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, 'updated_by' => $userId],
        );

        static::flush();
    }

    public static function flush(): void
    {
        static::$cache = null;
    }
}
