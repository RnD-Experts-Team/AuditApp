<?php

namespace App\Http\Controllers;

use App\Models\Audit;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AuditController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        if (!$user) abort(401);

        $allowedStoreIds = $user->allowedStoreIdsCached();

        $audits = Audit::with(['store', 'user'])
            ->whereIn('store_id', $allowedStoreIds)
            ->paginate(15);

        return Inertia::render('Audits/Index', ['audits' => $audits]);
    }

    public function show(Audit $audit)
    {
        $user = Auth::user();
        if (!$user) abort(401);

        if (!$user->canAccessAudit($audit)) {
            abort(403, 'Unauthorized');
        }

        $audit->load(['store', 'user', 'cameraForms']);

        return Inertia::render('Audits/Show', ['audit' => $audit]);
    }
}
