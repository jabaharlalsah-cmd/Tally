<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 10B — the post-filing acknowledgement log for a quarterly Form 26Q.
 *
 * Not auto-populated. After the return is uploaded to the e-filing portal and accepted,
 * the CA records the token / provisional receipt number here, so the 26Q Returns screen
 * can show each quarter's status (draft → exported → filed with token).
 */
class TdsReturnFiling extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'fy_start', 'quarter', 'filed_at', 'token_no', 'receipt_no', 'notes',
    ];

    protected $casts = [
        'fy_start' => 'integer',
        'quarter' => 'integer',
        'filed_at' => 'datetime',
    ];

    /** "FY 2026-27 · Q2" */
    public function label(): string
    {
        return 'FY '.Voucher::statutoryFyLabel($this->fy_start).' · Q'.$this->quarter;
    }
}
