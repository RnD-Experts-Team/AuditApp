<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Store;


class AuditController extends Controller
{
    /**
     * GET /api/audits
     * List audits accessible to the authenticated user
     */
    public function index(Request $request)
    {
        $user = Auth::user();
       

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
        

        $audit = Audit::with([
            'store',
            'user',
            'cameraForms.notes.attachments',
            'cameraForms.entity',
            'cameraForms.rating',
        ])->findOrFail($id);

      

        return $this->success('Audit fetched successfully', $audit);
    }
/**
 * GET /api/audits/summary/{store_code}/{date}
 * Returns audit summary: total score + autofail items with images
 */
public function summary(string $store_code, string $date)
{
    

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return response()->json([
            'status'  => 'error',
            'message' => 'Invalid date format. Use yyyy-mm-dd',
            'data'    => null,
            'errors'  => ['date' => ['Date must be in yyyy-mm-dd format']],
        ], 422);
    }

    // Find store by 'store' field
    $store = Store::where('store', $store_code)->first();
    
    if (!$store) {
        return response()->json([
            'status'  => 'error',
            'message' => 'Store not found',
            'data'    => null,
            'errors'  => ['store' => ['Store code not found']],
        ], 404);
    }

    $store_id = $store->id;

    

    // Find audit with attachments included
    $audit = Audit::with([
        'store',
        'user',
        'cameraForms.entity.category',
        'cameraForms.rating',
        'cameraForms.notes.attachments', // ✅ Include attachments
    ])
    ->where('store_id', $store_id)
    ->where('date', $date)
    ->first();

    // Return empty structure if no audit exists
    if (!$audit) {
        return $this->success('No audit found for this date', [
            'has_audit' => false,
            'store_id' => $store_id,
            'store_code' => $store_code,
            'store_name' => $store->store,
            'date' => $date,
            'total_score' => null,
            'autofails' => [],
        ]);
    }

    // Calculate total score
    $totalScore = $this->calculateAuditScore($audit);

    // Collect autofails with images
    $autofails = $this->collectAutofails($audit);

    return $this->success('Audit summary retrieved successfully', [
        'has_audit' => true,
        'store_id' => $store_id,
        'store_code' => $store_code,
        'store_name' => $store->store,
        'store_group' => $store->group,
        'date' => $date,
        'audit_id' => $audit->id,
        'audited_by' => $audit->user ? [
            'id' => $audit->user->id,
            'name' => $audit->user->name,
        ] : null,
        'total_score' => $totalScore,
        'autofails' => $autofails,
    ]);
}

/**
 * Collect all autofail items with entity information AND images
 */
private function collectAutofails(Audit $audit): array
{
    $autofails = [];

    foreach ($audit->cameraForms as $form) {
        $ratingLabel = strtolower($form->rating ? $form->rating->label : '');
        
        if ($ratingLabel === 'auto fail') {
            // Collect notes text
            $notes = [];
            $images = [];
            
            foreach ($form->notes as $note) {
                // Add note text if exists
                if ($note->note !== null && trim($note->note) !== '') {
                    $notes[] = trim($note->note);
                }
                
                // Add all images from this note
                foreach ($note->attachments as $attachment) {
                    $images[] = [
                        'id' => $attachment->id,
                        'path' => $attachment->path,
                        'url' => $attachment->url, // ✅ Public URL from model
                    ];
                }
            }

            $autofails[] = [
                'camera_form_id' => $form->id,
                'entity' => [
                    'id' => $form->entity->id,
                    'label' => $form->entity->entity_label,
                    'date_range_type' => $form->entity->date_range_type,
                    'category' => $form->entity->category ? [
                        'id' => $form->entity->category->id,
                        'label' => $form->entity->category->label,
                    ] : null,
                ],
                'rating_id' => $form->rating_id,
                'notes' => $notes,
                'notes_count' => count($notes),
                'images' => $images, // ✅ Image URLs included
                'images_count' => count($images),
            ];
        }
    }

    return $autofails;
}

/**
 * Calculate total score (unchanged)
 */
private function calculateAuditScore(Audit $audit): ?float
{
    $pass = 0;
    $fail = 0;
    $hasAutoFail = false;

    foreach ($audit->cameraForms as $form) {
        $ratingLabel = strtolower($form->rating ? $form->rating->label : '');
        
        if ($ratingLabel === 'pass') {
            $pass++;
        } elseif ($ratingLabel === 'fail') {
            $fail++;
        } elseif ($ratingLabel === 'auto fail') {
            if ($form->entity && $form->entity->date_range_type === 'weekly') {
                $hasAutoFail = true;
            }
        }
    }

    $denominator = $pass + $fail;
    
    if ($denominator === 0) {
        return null;
    }

    $scoreWithoutAutoFail = $pass / $denominator;

    if ($hasAutoFail) {
        return 0.0;
    }

    return round($scoreWithoutAutoFail, 4);
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
