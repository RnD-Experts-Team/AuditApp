<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditController extends Controller
{
    /**
     * GET /api/audits
     * List audits accessible to the authenticated user
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return $this->unauthorized();
        }

        $allowedStoreIds = $user->allowedStoreIdsCached();

        $audits = Audit::with(['store', 'user'])
            ->whereIn('store_id', $allowedStoreIds)
            ->orderBy('date', 'desc')
            ->paginate(15);

        return $this->success('Audits fetched successfully', $audits);
    }

    /**
     * GET /api/audits/{id}
     * Show single audit with camera forms
     */
    public function show(int $id)
    {
        $user = Auth::user();
        if (!$user) {
            return $this->unauthorized();
        }

        $audit = Audit::with([
            'store',
            'user',
            'cameraForms.notes.attachments',
            'cameraForms.entity',
            'cameraForms.rating',
        ])->findOrFail($id);

        if (!$user->canAccessAudit($audit)) {
            return $this->forbidden();
        }

        return $this->success('Audit fetched successfully', $audit);
    }

    /* ------------------------------------------------------------
     | Shared API helpers (same convention as other controllers)
     |------------------------------------------------------------ */

    private function success(string $message, $data = null, int $code = 200)
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
        ], $code);
    }

    private function unauthorized()
    {
        return response()->json([
            'status'  => 'error',
            'message' => 'Unauthorized',
            'data'    => null,
            'errors'  => null,
        ], 401);
    }

    private function forbidden()
    {
        return response()->json([
            'status'  => 'error',
            'message' => 'Forbidden',
            'data'    => null,
            'errors'  => null,
        ], 403);
    }
}
