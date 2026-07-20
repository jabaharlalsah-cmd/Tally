<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Phase 13 — thrown when something tries to change a stock item's costing_method after stock
 * movements exist. Changing a live item's method would silently revalue history; it is a migration
 * operation, not a UI toggle. Caught by the Stock Item form/controller and surfaced as a clear
 * validation error; the model's updating observer throws it as the last-line server authority.
 */
class CostingMethodLockedException extends RuntimeException
{
}
