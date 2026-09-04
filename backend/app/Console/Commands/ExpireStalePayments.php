<?php

namespace App\Console\Commands;

use App\Services\PaymentExpirySweepService;
use Illuminate\Console\Command;

/**
 * Phase 6 — the scheduled entry point for database-design.md §12's expiry
 * sweep. Deliberately thin: every actual decision (candidate selection,
 * locking, the live Stripe guard, cancellation, inventory release) lives
 * in PaymentExpirySweepService, which is unit-tested directly — this
 * command only invokes it and reports the outcome.
 */
class ExpireStalePayments extends Command
{
    protected $signature = 'payments:expire-stale';

    protected $description = 'Cancel pending Orders whose current Payment has sat at requires_payment past the configured expiry window (database-design.md §12).';

    public function handle(PaymentExpirySweepService $sweepService): int
    {
        $counts = $sweepService->sweep();

        $this->info(sprintf(
            'Payment expiry sweep: %d processed, %d cancelled, %d deferred, %d skipped, %d errors.',
            $counts['processed'],
            $counts['cancelled'],
            $counts['deferred'],
            $counts['skipped'],
            $counts['errors'],
        ));

        return self::SUCCESS;
    }
}
