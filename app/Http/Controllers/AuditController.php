<?php

namespace App\Http\Controllers;

use App\Models\Audit;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AuditController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();

        // Admins see all audits
        if ($user->isAdmin()) {
            $audits = Audit::with(['store', 'user'])
                ->paginate(15);
        } else {
            // Regular users see only audits from their groups
            $userGroups = $user->getGroupNumbers();
            $audits = Audit::whereHas('store', function ($query) use ($userGroups) {
                $query->whereIn('group', $userGroups);
            })
            ->with(['store', 'user'])
            ->paginate(15);
        }

        return Inertia::render('Audits/Index', ['audits' => $audits]);
    }

    /**
     * Show the specified resource.
     */
    public function show(Audit $audit)
    {
        $user = auth()->user();

        // Check if user has access to this audit
        if (!$user->isAdmin() && !$user->canAccessAudit($audit)) {
            abort(403, 'Unauthorized');
        }

        $audit->load(['store', 'user', 'cameraForms']);

        return Inertia::render('Audits/Show', ['audit' => $audit]);
    }
}
