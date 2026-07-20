<?php

namespace App\Services\TallyImport;

use RuntimeException;

/**
 * A voucher line referenced a master (ledger, stock item, cost centre) that isn't
 * present in the export's masters. Caught by the importer and turned into a named
 * rejection so the customer sees exactly which reference is missing.
 */
class ResolveException extends RuntimeException
{
}
