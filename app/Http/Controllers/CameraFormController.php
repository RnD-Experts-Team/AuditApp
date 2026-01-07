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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class CameraFormController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $dateRangeType = $request->input('date_range_type', 'daily');

        $entities = Entity::with('category')
            ->where('date_range_type', $dateRangeType)
            ->orderBy('category_id')
            ->orderBy('entity_label')
            ->get();

        $query = Audit::with(['store', 'user', 'cameraForms.entity', 'cameraForms.rating'])
            ->whereHas('cameraForms.entity', function ($q) use ($dateRangeType) {
                $q->where('date_range_type', $dateRangeType);
            });

        if (!$user->isAdmin()) {
            $userGroups = $user->getGroupNumbers();
            $query->whereHas('store', function ($q) use ($userGroups) {
                $q->whereIn('group', $userGroups);
            });
        }

        if ($request->filled('date_from')) $query->where('date', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->where('date', '<=', $request->date_to);
        if ($request->filled('store_id'))  $query->where('store_id', $request->store_id);

        if ($request->filled('group')) {
            $query->whereHas('store', function ($q) use ($request) {
                $q->where('group', $request->group);
            });
        }

        $audits = $query->orderBy('date', 'desc')->paginate(15);

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

    public function create()
    {
        $user = Auth::user();

        $entities = Entity::with('category')
            ->where('active', true)
            ->orderBy('category_id')
            ->orderBy('entity_label')
            ->get();

        $ratings = Rating::orderBy('id')->get();

        if ($user->isAdmin()) {
            $stores = Store::orderBy('store')->get();
        } else {
            $userGroups = $user->getGroupNumbers();
            $stores = Store::whereIn('group', $userGroups)->orderBy('store')->get();
        }

        return Inertia::render('CameraForms/Create', [
            'entities' => $entities,
            'ratings' => $ratings,
            'stores' => $stores,
        ]);
    }

    public function store(StoreCameraFormRequest $request)
    {
        $user = Auth::user();

        $store = Store::findOrFail($request->store_id);
        if (!$user->isAdmin() && !$user->hasGroupAccess($store->group)) {
            abort(403, 'Unauthorized');
        }

        DB::beginTransaction();

        try {
            $audit = Audit::create([
                'store_id' => $request->store_id,
                'user_id' => Auth::id(),
                'date' => $request->date,
            ]);

            foreach ($request->entities as $i => $entityData) {
                $hasRating = !empty($entityData['rating_id']);
                $hasNote   = !empty($entityData['note']);
                $file      = $request->file("entities.$i.image");

                if (!$hasRating && !$hasNote && !$file) {
                    continue;
                }

                $imagePath = null;
                if ($file) {
                    $imagePath = $file->store("camera_forms/audits/{$audit->id}", 'public');
                }

                CameraForm::create([
                    'user_id' => Auth::id(),
                    'entity_id' => $entityData['entity_id'],
                    'audit_id' => $audit->id,
                    'rating_id' => $entityData['rating_id'] ?? null,
                    'note' => $entityData['note'] ?? null,
                    'image_path' => $imagePath,
                ]);
            }

            DB::commit();

            return redirect()->route('camera-forms.index')
                ->with('success', 'Camera form created successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Store failed: ' . $e->getMessage());

            return back()->withErrors(['error' => 'Failed to create camera form: ' . $e->getMessage()]);
        }
    }

    public function edit($id)
    {
        $user = Auth::user();

        $audit = Audit::with([
            'store',
            'user',
            'cameraForms.rating',
            'cameraForms.entity.category',
        ])->findOrFail($id);

        if (!$user->isAdmin() && !$user->canAccessAudit($audit)) {
            abort(403, 'Unauthorized');
        }

        $entities = Entity::with('category')
            ->where('active', true)
            ->orderBy('category_id')
            ->orderBy('entity_label')
            ->get();

        $ratings = Rating::orderBy('id')->get();

        if ($user->isAdmin()) {
            $stores = Store::orderBy('store')->get();
        } else {
            $userGroups = $user->getGroupNumbers();
            $stores = Store::whereIn('group', $userGroups)->orderBy('store')->get();
        }

        return Inertia::render('CameraForms/Edit', [
            'audit' => $audit,
            'entities' => $entities,
            'ratings' => $ratings,
            'stores' => $stores,
        ]);
    }

    public function update(UpdateCameraFormRequest $request, $id)
    {
        $user = Auth::user();

        $audit = Audit::with('cameraForms')->findOrFail($id);

        if (!$user->isAdmin() && !$user->canAccessAudit($audit)) {
            abort(403, 'Unauthorized');
        }

        $store = Store::findOrFail($request->store_id);
        if (!$user->isAdmin() && !$user->hasGroupAccess($store->group)) {
            abort(403, 'Unauthorized');
        }

        DB::beginTransaction();

        try {
            $audit->update([
                'store_id' => $request->store_id,
                'date' => $request->date,
            ]);

            // We'll upsert per entity_id, and delete anything not submitted
            $submittedEntityIds = [];

            foreach ($request->entities as $i => $entityData) {
                $entityId = (int) $entityData['entity_id'];
                $submittedEntityIds[] = $entityId;

                $hasRating = !empty($entityData['rating_id']);
                $hasNote   = !empty($entityData['note']);
                $file      = $request->file("entities.$i.image");
                $remove    = filter_var($entityData['remove_image'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if (!$hasRating && !$hasNote && !$file && !$remove) {
                    // If it's completely empty and no remove requested, we treat it as "ignore"
                    // (but since it's submitted, we will still keep existing row unless you want otherwise)
                }

                $cameraForm = CameraForm::where('audit_id', $audit->id)
                    ->where('entity_id', $entityId)
                    ->first();

                if (!$cameraForm) {
                    $cameraForm = new CameraForm();
                    $cameraForm->audit_id = $audit->id;
                    $cameraForm->entity_id = $entityId;
                    $cameraForm->user_id = Auth::id();
                }

                // Handle remove or replace image
                if ($remove) {
                    if ($cameraForm->image_path) {
                        Storage::disk('public')->delete($cameraForm->image_path);
                    }
                    $cameraForm->image_path = null;
                }

                if ($file) {
                    if ($cameraForm->image_path) {
                        Storage::disk('public')->delete($cameraForm->image_path);
                    }
                    $cameraForm->image_path = $file->store("camera_forms/audits/{$audit->id}", 'public');
                }

                $cameraForm->rating_id = $entityData['rating_id'] ?? null;
                $cameraForm->note = $entityData['note'] ?? null;

                // Only save if it has at least something (rating/note/image)
                $hasAnything = ($cameraForm->rating_id !== null)
                    || (is_string($cameraForm->note) && trim($cameraForm->note) !== '')
                    || ($cameraForm->image_path !== null);

                if ($hasAnything) {
                    $cameraForm->save();
                } else {
                    // If empty, delete existing record (and file)
                    if ($cameraForm->exists) {
                        if ($cameraForm->image_path) {
                            Storage::disk('public')->delete($cameraForm->image_path);
                        }
                        $cameraForm->delete();
                    }
                }
            }

            // Delete rows that were not submitted at all (keeps behavior consistent if your UI filters them out)
            CameraForm::where('audit_id', $audit->id)
                ->whereNotIn('entity_id', $submittedEntityIds)
                ->get()
                ->each(function (CameraForm $cf) {
                    if ($cf->image_path) {
                        Storage::disk('public')->delete($cf->image_path);
                    }
                    $cf->delete();
                });

            DB::commit();

            return redirect()->route('camera-forms.index')
                ->with('success', 'Camera form updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Update failed: ' . $e->getMessage());

            return back()->withErrors(['error' => 'Failed to update camera form: ' . $e->getMessage()]);
        }
    }

    public function destroy($id)
    {
        $user = Auth::user();

        $audit = Audit::with('cameraForms')->findOrFail($id);

        if (!$user->isAdmin() && !$user->canAccessAudit($audit)) {
            abort(403, 'Unauthorized');
        }

        try {
            // Delete images
            foreach ($audit->cameraForms as $cf) {
                if ($cf->image_path) {
                    Storage::disk('public')->delete($cf->image_path);
                }
            }

            $audit->delete();

            return redirect()->route('camera-forms.index')
                ->with('success', 'Camera form deleted successfully.');
        } catch (\Exception $e) {
            Log::error('Failed to delete audit: ' . $e->getMessage());

            return back()->withErrors(['error' => 'Failed to delete: ' . $e->getMessage()]);
        }
    }

    public function show($id)
    {
        $user = Auth::user();

        $audit = Audit::with([
            'store',
            'user',
            'cameraForms.entity.category',
            'cameraForms.rating'
        ])->findOrFail($id);

        if (!$user->isAdmin() && !$user->canAccessAudit($audit)) {
            abort(403, 'Unauthorized');
        }

        return Inertia::render('CameraForms/Show', [
            'audit' => $audit,
        ]);
    }
}
