<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCameraFormRequest;
use App\Http\Requests\UpdateCameraFormRequest;
use App\Models\Audit;
use App\Models\CameraForm;
use App\Models\CameraFormNote;
use App\Models\CameraFormNoteAttachment;
use App\Models\Entity;
use App\Models\Rating;
use App\Models\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Illuminate\Support\Str;

class CameraFormController extends Controller
{
    public function index(\Illuminate\Http\Request $request)
    {
        $user = Auth::user();
        if (!$user) abort(401);

        $allowedStoreIds = $user->allowedStoreIdsCached();
        $dateRangeType = $request->input('date_range_type', 'daily');

        $entities = Entity::with('category')
            ->where('date_range_type', $dateRangeType)
            ->orderBy('category_id')
            ->orderBy('entity_label')
            ->get();

        $query = Audit::with([
            'store',
            'user',
            'cameraForms.entity',
            'cameraForms.rating',
            'cameraForms.notes.attachments',
        ])
            ->whereIn('store_id', $allowedStoreIds)
            ->whereHas('cameraForms.entity', function ($q) use ($dateRangeType) {
                $q->where('date_range_type', $dateRangeType);
            });

        if ($request->filled('date_from')) $query->where('date', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->where('date', '<=', $request->date_to);
        if ($request->filled('store_id'))  $query->where('store_id', (int) $request->store_id);

        $audits = $query->orderBy('date', 'desc')->paginate(15);

        $stores = Store::select('id', 'store', 'group')
            ->whereIn('id', $allowedStoreIds)
            ->orderBy('store')
            ->get();

        // groups no longer used for access control, but UI may still display groups
        $groups = Store::select('group')
            ->distinct()
            ->whereNotNull('group')
            ->whereIn('id', $allowedStoreIds)
            ->pluck('group');

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
        if (!$user) abort(401);

        $allowedStoreIds = $user->allowedStoreIdsCached();

        $entities = Entity::with('category')
            ->where('active', true)
            ->orderBy('category_id')
            ->orderBy('entity_label')
            ->get();

        $ratings = Rating::orderBy('id')->get();

        $stores = Store::whereIn('id', $allowedStoreIds)->orderBy('store')->get();

        return Inertia::render('CameraForms/Create', [
            'entities' => $entities,
            'ratings' => $ratings,
            'stores' => $stores,
        ]);
    }

    public function store(StoreCameraFormRequest $request)
    {
        $user = Auth::user();
        if (!$user) abort(401);

        $storeId = (int) $request->store_id;

        if (!$user->canAccessStoreId($storeId)) {
            abort(403, 'Unauthorized');
        }

        $store = Store::findOrFail($storeId);

        DB::beginTransaction();

        try {
            $audit = Audit::create([
                'store_id' => $storeId,
                'user_id' => (int) Auth::id(),
                'date' => $request->date,
            ]);

            foreach ($request->entities as $i => $entityData) {
                $hasRating = !empty($entityData['rating_id']);

                $notes = $entityData['notes'] ?? [];
                $hasNotesOrImages = false;

                if (is_array($notes)) {
                    foreach ($notes as $j => $noteRow) {
                        $noteText = isset($noteRow['note']) ? trim((string)$noteRow['note']) : '';
                        $files = $request->file("entities.$i.notes.$j.images") ?? [];
                        $hasImages = is_array($files) && count($files) > 0;

                        if ($noteText !== '' || $hasImages) {
                            $hasNotesOrImages = true;
                            break;
                        }
                    }
                }

                if (!$hasRating && !$hasNotesOrImages) {
                    continue;
                }

                $cf = CameraForm::create([
                    'user_id' => (int) Auth::id(),
                    'entity_id' => $entityData['entity_id'],
                    'audit_id' => $audit->id,
                    'rating_id' => $entityData['rating_id'] ?? null,
                ]);

                if (is_array($notes)) {
                    foreach ($notes as $j => $noteRow) {
                        $noteText = isset($noteRow['note']) ? (string)$noteRow['note'] : null;
                        $noteTrim = is_string($noteText) ? trim($noteText) : '';

                        $files = $request->file("entities.$i.notes.$j.images") ?? [];
                        $hasImages = is_array($files) && count($files) > 0;

                        if ($noteTrim === '' && !$hasImages) continue;

                        $note = $cf->notes()->create([
                            'note' => $noteTrim === '' ? null : $noteText,
                        ]);

                        if ($hasImages) {
                            $entityModel = Entity::findOrFail((int)$entityData['entity_id']);
                            $seq = $this->nextEntityImageSeq($audit->id, $entityModel->id);

                            foreach ($files as $file) {
                                $path = $this->storeImageWithNaming($file, $store, $request->date, $entityModel, $seq);
                                $note->attachments()->create(['path' => $path]);
                                $seq++;
                            }
                        }
                    }
                }
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
        if (!$user) abort(401);

        $audit = Audit::with([
            'store',
            'user',
            'cameraForms.rating',
            'cameraForms.entity.category',
            'cameraForms.notes.attachments',
        ])->findOrFail($id);

        if (!$user->canAccessAudit($audit)) {
            abort(403, 'Unauthorized');
        }

        $allowedStoreIds = $user->allowedStoreIdsCached();

        $entities = Entity::with('category')
            ->where('active', true)
            ->orderBy('category_id')
            ->orderBy('entity_label')
            ->get();

        $ratings = Rating::orderBy('id')->get();

        $stores = Store::whereIn('id', $allowedStoreIds)->orderBy('store')->get();

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
        if (!$user) abort(401);

        $audit = Audit::with(['cameraForms.notes.attachments'])->findOrFail($id);

        if (!$user->canAccessAudit($audit)) {
            abort(403, 'Unauthorized');
        }

        $storeId = (int) $request->store_id;
        if (!$user->canAccessStoreId($storeId)) {
            abort(403, 'Unauthorized');
        }

        $store = Store::findOrFail($storeId);

        DB::beginTransaction();

        try {
            $audit->update([
                'store_id' => $storeId,
                'date' => $request->date,
            ]);

            $submittedEntityIds = [];

            foreach ($request->entities as $i => $entityData) {
                $entityId = (int) $entityData['entity_id'];
                $submittedEntityIds[] = $entityId;

                $cameraForm = CameraForm::where('audit_id', $audit->id)
                    ->where('entity_id', $entityId)
                    ->with('notes.attachments')
                    ->first();

                if (!$cameraForm) {
                    $cameraForm = new CameraForm();
                    $cameraForm->audit_id = $audit->id;
                    $cameraForm->entity_id = $entityId;
                    $cameraForm->user_id = (int) Auth::id();
                }

                $cameraForm->rating_id = $entityData['rating_id'] ?? null;
                $cameraForm->save();

                $removeNoteIds = $entityData['remove_note_ids'] ?? [];
                if (is_array($removeNoteIds) && count($removeNoteIds) > 0) {
                    $notesToRemove = CameraFormNote::where('camera_form_id', $cameraForm->id)
                        ->whereIn('id', $removeNoteIds)
                        ->with('attachments')
                        ->get();

                    foreach ($notesToRemove as $note) {
                        foreach ($note->attachments as $att) {
                            if ($att->path) Storage::disk('public')->delete($att->path);
                            $att->delete();
                        }
                        $note->delete();
                    }
                }

                $notes = $entityData['notes'] ?? [];
                $keptNoteIds = [];

                if (is_array($notes)) {
                    foreach ($notes as $j => $noteRow) {
                        $noteId = isset($noteRow['id']) ? (int)$noteRow['id'] : null;
                        $noteText = isset($noteRow['note']) ? (string)$noteRow['note'] : null;
                        $noteTrim = is_string($noteText) ? trim($noteText) : '';

                        $files = $request->file("entities.$i.notes.$j.images") ?? [];
                        $hasImages = is_array($files) && count($files) > 0;

                        $removeAttachmentIds = $noteRow['remove_attachment_ids'] ?? [];
                        $wantsRemoveAtt = is_array($removeAttachmentIds) && count($removeAttachmentIds) > 0;

                        if (!$noteId && $noteTrim === '' && !$hasImages && !$wantsRemoveAtt) {
                            continue;
                        }

                        $note = null;

                        if ($noteId) {
                            $note = CameraFormNote::where('camera_form_id', $cameraForm->id)
                                ->where('id', $noteId)
                                ->with('attachments')
                                ->first();
                        }

                        if (!$note) {
                            $note = $cameraForm->notes()->create([
                                'note' => $noteTrim === '' ? null : $noteText,
                            ]);
                        } else {
                            $note->note = ($noteTrim === '') ? null : $noteText;
                            $note->save();
                        }

                        $keptNoteIds[] = $note->id;

                        if ($wantsRemoveAtt) {
                            $attsToRemove = CameraFormNoteAttachment::where('camera_form_note_id', $note->id)
                                ->whereIn('id', $removeAttachmentIds)
                                ->get();

                            foreach ($attsToRemove as $att) {
                                if ($att->path) Storage::disk('public')->delete($att->path);
                                $att->delete();
                            }
                        }

                        if ($hasImages) {
                            $entityModel = Entity::findOrFail($entityId);
                            $seq = $this->nextEntityImageSeq($audit->id, $entityModel->id);

                            foreach ($files as $file) {
                                $path = $this->storeImageWithNaming($file, $store, $request->date, $entityModel, $seq);
                                $note->attachments()->create(['path' => $path]);
                                $seq++;
                            }
                        }

                        $note->load('attachments');
                        $hasAnything = (is_string($note->note) && trim($note->note) !== '') || ($note->attachments->count() > 0);
                        if (!$hasAnything) {
                            foreach ($note->attachments as $att) {
                                if ($att->path) Storage::disk('public')->delete($att->path);
                                $att->delete();
                            }
                            $note->delete();
                            $keptNoteIds = array_values(array_filter($keptNoteIds, fn($x) => $x !== $note->id));
                        }
                    }
                }

                $cameraForm->load('notes.attachments');
                $hasNotes = $cameraForm->notes->count() > 0;

                if (($cameraForm->rating_id === null) && !$hasNotes) {
                    $cameraForm->delete();
                }
            }

            CameraForm::where('audit_id', $audit->id)
                ->whereNotIn('entity_id', $submittedEntityIds)
                ->with('notes.attachments')
                ->get()
                ->each(function (CameraForm $cf) {
                    foreach ($cf->notes as $note) {
                        foreach ($note->attachments as $att) {
                            if ($att->path) Storage::disk('public')->delete($att->path);
                            $att->delete();
                        }
                        $note->delete();
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
        if (!$user) abort(401);

        $audit = Audit::with(['cameraForms.notes.attachments'])->findOrFail($id);

        if (!$user->canAccessAudit($audit)) {
            abort(403, 'Unauthorized');
        }

        try {
            foreach ($audit->cameraForms as $cf) {
                foreach ($cf->notes as $note) {
                    foreach ($note->attachments as $att) {
                        if ($att->path) Storage::disk('public')->delete($att->path);
                        $att->delete();
                    }
                    $note->delete();
                }
                $cf->delete();
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
        if (!$user) abort(401);

        $audit = Audit::with([
            'store',
            'user',
            'cameraForms.entity.category',
            'cameraForms.rating',
            'cameraForms.notes.attachments',
        ])->findOrFail($id);

        if (!$user->canAccessAudit($audit)) {
            abort(403, 'Unauthorized');
        }

        return Inertia::render('CameraForms/Show', [
            'audit' => $audit,
        ]);
    }

    private function storeFolder(Store $store): string
    {
        $storeSlug = Str::slug($store->store ?: ('store-' . $store->id));
        return "camera_forms/stores/{$store->id}-{$storeSlug}";
    }

    private function entityBaseName(Entity $entity): string
    {
        $label = $entity->entity_label ?: ('entity-' . $entity->id);
        $slug = Str::slug($label);
        return $slug !== '' ? $slug : ('entity-' . $entity->id);
    }

    private function nextEntityImageSeq(int $auditId, int $entityId): int
    {
        $count = DB::table('camera_form_note_attachments as a')
            ->join('camera_form_notes as n', 'n.id', '=', 'a.camera_form_note_id')
            ->join('camera_forms as cf', 'cf.id', '=', 'n.camera_form_id')
            ->where('cf.audit_id', $auditId)
            ->where('cf.entity_id', $entityId)
            ->count();

        return $count + 1;
    }

    private function storeImageWithNaming(
        \Illuminate\Http\UploadedFile $file,
        Store $store,
        string $date,
        Entity $entity,
        int $seq
    ): string {
        $baseFolder = $this->storeFolder($store) . "/{$date}";

        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $entityBase = $this->entityBaseName($entity);

        $filename = "{$entityBase}-{$seq}.{$ext}";

        return $file->storeAs($baseFolder, $filename, 'public');
    }
}
