<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 9A — a record that a GST return was filed for a period, with the portal's
 * Acknowledgement Reference Number. Manually recorded (no portal integration).
 */
class GstReturnFiling extends Model
{
    use BelongsToCompany;

    protected $fillable = ['period', 'return_type', 'filed_at', 'arn', 'notes'];

    protected $casts = [
        'filed_at' => 'datetime',
    ];

    public const TYPES = ['gstr1' => 'GSTR-1', 'gstr3b' => 'GSTR-3B'];

    /** Record (or update) the ARN for a period + return type. */
    public static function record(string $period, string $returnType, string $arn, ?string $notes = null): self
    {
        return static::updateOrCreate(
            ['period' => $period, 'return_type' => $returnType],
            ['arn' => trim($arn), 'notes' => $notes, 'filed_at' => now()]
        );
    }

    /** Has this return been filed for the period? */
    public static function isFiled(string $period, string $returnType): bool
    {
        return static::where('period', $period)->where('return_type', $returnType)->whereNotNull('arn')->exists();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->return_type] ?? $this->return_type;
    }
}
