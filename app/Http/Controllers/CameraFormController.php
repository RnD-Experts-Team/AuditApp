<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCameraFormRequest;
use App\Http\Requests\UpdateCameraFormRequest;
use App\Models\Audit;
use App\Models\CameraForm;
use App\Models\Entity;
use App\Models\Rating;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CameraFormController extends Controller
{
    /**
     * Display a listing of camera forms grouped by audits.
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $dateRangeType = $request->input('date_range_type', 'daily');

        // Get all entities for the current tab (these will be the dynamic columns)
        $entities = Entity::with('category')
            ->where('date_range_type', $dateRangeType)
            ->orderBy('category_id')
            ->orderBy('entity_label')
            ->get();

        // Build query for audits with filters
        $query = Audit::with(['store', 'user', 'cameraForms.entity', 'cameraForms.rating'])
            ->whereHas('cameraForms.entity', function ($q) use ($dateRangeType) {
                $q->where('date_range_type', $dateRangeType);
            });

        // Filter by user groups if not admin
        if (!$user->isAdmin()) {
            $userGroups = $user->getGroupNumbers();
            $query->whereHas('store', function ($q) use ($userGroups) {
                $q->whereIn('group', $userGroups);
            });
        }

        // Date range filter
        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->date_to);
        }

        // Store filter
        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        // Group filter
        if ($request->filled('group')) {
            $query->whereHas('store', function ($q) use ($request) {
                $q->where('group', $request->group);
            });
        }

        $audits = $query->orderBy('date', 'desc')->paginate(15);

        // Get filter options - only show user's groups/stores
        if ($user->isAdmin()) {
            $stores = Store::select('id', 'store', 'group')->get();
            $groups = Store::select('group')->distinct()->whereNotNull('group')->pluck('group');
        } else {
            $userGroups = $user->getGroupNumbers();
            $stores = Store::select('id', 'store', 'group')
                ->whereIn('group', $userGroups)
                ->get();
            $groups = collect($userGroups);
        }

        return Inertia::render('CameraForms/Index', [
            'audits' => $audits,
            'entities' => $entities,
            'stores' => $stores,
            'groups' => $groups,
            'filters' => $request->only(['date_range_type', 'date_from', 'date_to', 'store_id', 'group']),
        ]);
    }

    /**
     * Show the form for creating a new camera form.
     */
    public function create()
    {
        $user = auth()->user();

        $entities = Entity::with('category')->get();
        $ratings = Rating::all();

        // Filter stores by user groups if not admin
        if ($user->isAdmin()) {
            $stores = Store::all();
        } else {
            $userGroups = $user->getGroupNumbers();
            $stores = Store::whereIn('group', $userGroups)->get();
        }

        return Inertia::render('CameraForms/Create', [
            'entities' => $entities,
            'ratings' => $ratings,
            'stores' => $stores,
        ]);
    }

    /**
     * Store a newly created camera form in storage.
     */
    public function store(StoreCameraFormRequest $request)
    {
        $user = auth()->user();

        // Check if user has access to this store
        $store = Store::findOrFail($request->store_id);
        if (!$user->isAdmin() && !$user->hasGroupAccess($store->group)) {
            abort(403, 'Unauthorized');
        }

        DB::beginTransaction();

        try {
            // Create the audit record
            $audit = Audit::create([
                'store_id' => $request->store_id,
                'user_id' => auth()->id(),
                'date' => $request->date,
            ]);

            // Create camera form entries for each entity
            foreach ($request->entities as $entityData) {
                // Only create if rating or note is provided
                if (!empty($entityData['rating_id']) || !empty($entityData['note'])) {
                    CameraForm::create([
                        'user_id' => auth()->id(),
                        'entity_id' => $entityData['entity_id'],
                        'audit_id' => $audit->id,
                        'rating_id' => $entityData['rating_id'] ?? null,
                        'note' => $entityData['note'] ?? null,
                    ]);
                }
            }

            DB::commit();

            return redirect()->route('camera-forms.index')
                ->with('success', 'Camera form created successfully.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withErrors(['error' => 'Failed to create camera form.']);
        }
    }

    /**
     * Show the form for editing the specified camera form.
     */
    public function edit($id)
    {
        $user = auth()->user();

        $audit = Audit::with([
            'store',
            'user',
            'cameraForms.entity.category',
            'cameraForms.rating'
        ])->findOrFail($id);

        // Check if user has access to this audit
        if (!$user->isAdmin() && !$user->canAccessAudit($audit)) {
            abort(403, 'Unauthorized');
        }

        $ratings = Rating::all();

        // Filter stores by user groups if not admin
        if ($user->isAdmin()) {
            $stores = Store::all();
        } else {
            $userGroups = $user->getGroupNumbers();
            $stores = Store::whereIn('group', $userGroups)->get();
        }

        return Inertia::render('CameraForms/Edit', [
            'audit' => $audit,
            'ratings' => $ratings,
            'stores' => $stores,
        ]);
    }

    /**
     * Update the specified camera form in storage.
     */
    public function update(UpdateCameraFormRequest $request, $id)
    {
        $user = auth()->user();

        $audit = Audit::findOrFail($id);

        // Check if user has access to this audit
        if (!$user->isAdmin() && !$user->canAccessAudit($audit)) {
            abort(403, 'Unauthorized');
        }

        // Check if user has access to the new store
        $store = Store::findOrFail($request->store_id);
        if (!$user->isAdmin() && !$user->hasGroupAccess($store->group)) {
            abort(403, 'Unauthorized');
        }

        DB::beginTransaction();

        try {
            // Update the audit record
            $audit->update([
                'store_id' => $request->store_id,
                'date' => $request->date,
            ]);

            // Delete existing camera forms for this audit
            $audit->cameraForms()->delete();

            // Create new camera form entries
            foreach ($request->entities as $entityData) {
                if (!empty($entityData['rating_id']) || !empty($entityData['note'])) {
                    CameraForm::create([
                        'user_id' => auth()->id(),
                        'entity_id' => $entityData['entity_id'],
                        'audit_id' => $audit->id,
                        'rating_id' => $entityData['rating_id'] ?? null,
                        'note' => $entityData['note'] ?? null,
                    ]);
                }
            }

            DB::commit();

            return redirect()->route('camera-forms.index')
                ->with('success', 'Camera form updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Update failed: ' . $e->getMessage());

            return back()->withErrors(['error' => 'Failed to update camera form: ' . $e->getMessage()]);
        }
    }

    /**
     * Remove the specified camera form from storage.
     */
    public function destroy($id)
    {
        $user = auth()->user();

        $audit = Audit::findOrFail($id);

        // Check if user has access to this audit
        if (!$user->isAdmin() && !$user->canAccessAudit($audit)) {
            abort(403, 'Unauthorized');
        }

        try {
            $audit->delete();

            return redirect()->route('camera-forms.index')
                ->with('success', 'Camera form deleted successfully.');
        } catch (\Exception $e) {
            \Log::error('Failed to delete audit: ' . $e->getMessage());

            return back()->withErrors(['error' => 'Failed to delete: ' . $e->getMessage()]);
        }
    }

    /**
     * Show the specified camera form.
     */
    public function show($id)
    {
        $user = auth()->user();

        $audit = Audit::with([
            'store',
            'user',
            'cameraForms.entity.category',
            'cameraForms.rating'
        ])->findOrFail($id);

        // Check if user has access to this audit
        if (!$user->isAdmin() && !$user->canAccessAudit($audit)) {
            abort(403, 'Unauthorized');
        }

        return Inertia::render('CameraForms/Show', [
            'audit' => $audit,
        ]);
    }
}
