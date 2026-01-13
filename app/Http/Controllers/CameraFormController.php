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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Illuminate\Support\Str;

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

        $query = Audit::with([
            'store',
            'user',
            'cameraForms.entity',
            'cameraForms.rating',
            'cameraForms.notes.attachments',
        ])
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
                    'user_id' => Auth::id(),
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

                        // skip empty note rows
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

        $audit = Audit::with([
            'store',
            'user',
            'cameraForms.rating',
            'cameraForms.entity.category',
            'cameraForms.notes.attachments',
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

        $audit = Audit::with(['cameraForms.notes.attachments'])->findOrFail($id);

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
                    $cameraForm->user_id = Auth::id();
                }

                $cameraForm->rating_id = $entityData['rating_id'] ?? null;
                $cameraForm->save();

                // Remove whole notes
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

                // Upsert notes
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

                        // If this note row is totally empty AND is new AND no removals -> skip it
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

                        // remove specific attachments from this note
                        if ($wantsRemoveAtt) {
                            $attsToRemove = CameraFormNoteAttachment::where('camera_form_note_id', $note->id)
                                ->whereIn('id', $removeAttachmentIds)
                                ->get();

                            foreach ($attsToRemove as $att) {
                                if ($att->path) Storage::disk('public')->delete($att->path);
                                $att->delete();
                            }
                        }

                        // add new uploads
                        if ($hasImages) {
                            $entityModel = Entity::findOrFail($entityId);
                            $seq = $this->nextEntityImageSeq($audit->id, $entityModel->id);

                            foreach ($files as $file) {
                                $path = $this->storeImageWithNaming($file, $store, $request->date, $entityModel, $seq);
                                $note->attachments()->create(['path' => $path]);
                                $seq++;
                            }
                        }

                        // if note becomes empty (no text + no attachments) -> delete it
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

                // If cameraForm is empty now (no rating + no notes) delete it
                $cameraForm->load('notes.attachments');
                $hasNotes = $cameraForm->notes->count() > 0;

                if (($cameraForm->rating_id === null) && !$hasNotes) {
                    $cameraForm->delete();
                }
            }

            // Delete camera_forms not submitted
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

        $audit = Audit::with(['cameraForms.notes.attachments'])->findOrFail($id);

        if (!$user->isAdmin() && !$user->canAccessAudit($audit)) {
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

        $audit = Audit::with([
            'store',
            'user',
            'cameraForms.entity.category',
            'cameraForms.rating',
            'cameraForms.notes.attachments',
        ])->findOrFail($id);

        if (!$user->isAdmin() && !$user->canAccessAudit($audit)) {
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

    /**
     * Next sequence number for naming: entity-1, entity-2...
     * computed across ALL note attachments for this audit+entity.
     */
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
        string $date,       // YYYY-MM-DD
        Entity $entity,
        int $seq
    ): string {
        $baseFolder = $this->storeFolder($store) . "/{$date}";

        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $entityBase = $this->entityBaseName($entity);

        $filename = "{$entityBase}-{$seq}.{$ext}";

        // putFileAs returns the relative path inside the disk
        return $file->storeAs($baseFolder, $filename, 'public');
    }
}
