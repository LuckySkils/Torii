<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Services\Reconciler\DeliveryReconciler;
use Illuminate\Http\RedirectResponse;

/** The reconciler's manual repair (§17): run a delivery's chain again from the start. */
class DeliveryController extends Controller
{
    public function repair(Delivery $delivery, DeliveryReconciler $reconciler): RedirectResponse
    {
        $reconciler->repair($delivery);

        return back()->with('success', config('subtracker.reconciler.dry_run')
            ? 'Re-checking it (dry run: fixes are only recorded).'
            : 'Re-checking it.');
    }
}
