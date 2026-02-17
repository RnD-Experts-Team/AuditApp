<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Store;
// use App\Services\ScoringService;
use App\Models\CameraForm;

class AuditController extends Controller
{
    // private ScoringService $scoringService;

    // public function __construct(ScoringService $scoringService)
    // {
    //     $this->scoringService = $scoringService;
    // }
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
    /**
     * GET /api/audits/summary/{store_code}/{date}
     * Returns audit summary: total score + autofail items with images
     */
    // public function summary(string $store_code, string $date)
    // {
    //     $user = Auth::user();
    //     if (!$user) {
    //         return $this->unauthorized();
    //     }


    //     // Validate date format
    //     if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    //         return response()->json([
    //             'status'  => 'error',
    //             'message' => 'Invalid date format. Use yyyy-mm-dd',
    //             'data'    => null,
    //             'errors'  => ['date' => ['Date must be in yyyy-mm-dd format']],
    //         ], 422);
    //     }

    //     // Find store by 'store' field
    //     $store = Store::where('store', $store_code)->first();

    //     if (!$store) {
    //         return response()->json([
    //             'status'  => 'error',
    //             'message' => 'Store not found',
    //             'data'    => null,
    //             'errors'  => ['store' => ['Store code not found']],
    //         ], 404);
    //     }

    //     $store_id = $store->id;

    //     if (!$user->canAccessAudit($audit)) {
    //         return $this->forbidden();
    //     }

    //     // Find audit with attachments included
    //     $audit = Audit::with([
    //         'store',
    //         'user',
    //         'cameraForms.entity.category',
    //         'cameraForms.rating',
    //         'cameraForms.notes.attachments', // ✅ Include attachments
    //     ])
    //         ->where('store_id', $store_id)
    //         ->where('date', $date)
    //         ->first();

    //     // Return empty structure if no audit exists
    //     if (!$audit) {
    //         return $this->success('No audit found for this date', [
    //             'has_audit' => false,
    //             'store_id' => $store_id,
    //             'store_code' => $store_code,
    //             'store_name' => $store->store,
    //             'date' => $date,
    //             'total_score' => null,
    //             'autofails' => [],
    //         ]);
    //     }

    //     // Calculate total score
    //     $totalScore = $this->scoringService->calculateDailyScore(
    //         $audit->cameraForms->all()
    //     );
    //     // Collect autofails with images
    //     $autofails = $this->collectAutofails($audit);

    //     return $this->success('Audit summary retrieved successfully', [
    //         'has_audit' => true,
    //         'store_id' => $store_id,
    //         'store_code' => $store_code,
    //         'store_name' => $store->store,
    //         'store_group' => $store->group,
    //         'date' => $date,
    //         'audit_id' => $audit->id,
    //         'audited_by' => $audit->user ? [
    //             'id' => $audit->user->id,
    //             'name' => $audit->user->name,
    //         ] : null,
    //         'total_score' => $totalScore,
    //         'autofails' => $autofails,
    //     ]);
    // }

    /**
     * Collect all autofail items with entity information AND images
     */
    // private function collectAutofails(Audit $audit): array
    // {
    //     $autofails = [];

    //     foreach ($audit->cameraForms as $form) {
    //         $ratingLabel = strtolower($form->rating ? $form->rating->label : '');

    //         if ($ratingLabel === 'auto fail') {
    //             // Collect notes text
    //             $notes = [];
    //             $images = [];

    //             foreach ($form->notes as $note) {
    //                 // Add note text if exists
    //                 if ($note->note !== null && trim($note->note) !== '') {
    //                     $notes[] = trim($note->note);
    //                 }

    //                 // Add all images from this note
    //                 foreach ($note->attachments as $attachment) {
    //                     $images[] = [
    //                         'id' => $attachment->id,
    //                         'path' => $attachment->path,
    //                         'url' => $attachment->url, // ✅ Public URL from model
    //                     ];
    //                 }
    //             }

    //             $autofails[] = [
    //                 'camera_form_id' => $form->id,
    //                 'entity' => [
    //                     'id' => $form->entity->id,
    //                     'label' => $form->entity->entity_label,
    //                     'date_range_type' => $form->entity->date_range_type,
    //                     'category' => $form->entity->category ? [
    //                         'id' => $form->entity->category->id,
    //                         'label' => $form->entity->category->label,
    //                     ] : null,
    //                 ],
    //                 'rating_id' => $form->rating_id,
    //                 'notes' => $notes,
    //                 'notes_count' => count($notes),
    //                 'images' => $images, // ✅ Image URLs included
    //                 'images_count' => count($images),
    //             ];
    //         }
    //     }

    //     return $autofails;
    // }


    public function ratingsSummary(int $store_id, string $date_start, string $date_end)
    {
        $user = Auth::user();
        if (!$user) {
            return $this->unauthorized();
        }

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid date format. Use yyyy-mm-dd',
                'data'    => null,
                'errors'  => ['date' => ['Date must be in yyyy-mm-dd format']],
            ], 422);
        }

        // Find the store by store_id
        $store = Store::find($store_id);
        if (!$store) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Store not found',
                'data'    => null,
                'errors'  => ['store' => ['Store ID not found']],
            ], 404);
        }

        // Get camera forms for the store in the specified date range
        $cameraForms = CameraForm::with('entity')
            ->whereHas('audit', function ($query) use ($store_id, $date_start, $date_end) {
                $query->where('store_id', $store_id)
                    ->whereBetween('date', [$date_start, $date_end]);
            })
            ->whereIn('rating_id', [5, 6]) // 5 = Auto-fail, 6 = Urgent
            ->get();

        // Count the occurrences of rating 5 (auto-fail) and 6 (urgent) per entity
        $entityCounts = [];

        foreach ($cameraForms as $form) {
            if (!isset($entityCounts[$form->entity_id])) {
                $entityCounts[$form->entity_id] = [
                    'entity' => $form->entity,
                    'auto_fail_count' => 0,
                    'urgent_count' => 0,
                ];
            }

            if ($form->rating_id == 5) {
                $entityCounts[$form->entity_id]['auto_fail_count']++;
            } elseif ($form->rating_id == 6) {
                $entityCounts[$form->entity_id]['urgent_count']++;
            }
        }

        // Sort entities by the total of auto-fail and urgent counts, descending
        uasort($entityCounts, function ($a, $b) {
            return ($b['auto_fail_count'] + $b['urgent_count']) - ($a['auto_fail_count'] + $a['urgent_count']);
        });

        // Get the top 5 entities
        $topEntities = array_slice($entityCounts, 0, 5);

        // Prepare the response
        $response = [];
        foreach ($topEntities as $data) {
            $response[] = [
                'entity_id' => $data['entity']->id,
                'entity_label' => $data['entity']->entity_label,
                'auto_fail_count' => $data['auto_fail_count'],
                'urgent_count' => $data['urgent_count'],
                'total_count' => $data['auto_fail_count'] + $data['urgent_count'],
            ];
        }

        return $this->success('Top 5 entities retrieved successfully', $response);
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
