<?php

namespace App\Http\Controllers;

use App\Models\Audit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\CameraForm;
use App\Models\CameraFormNote;
use App\Models\CameraFormNoteAttachment;
use Carbon\Carbon;
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


    public function getDataByDateRange(Request $request)
    {
        // Get date range from the request
        $startDate = Carbon::parse($request->input('start_date'));
        $endDate = Carbon::parse($request->input('end_date'));

        // Get all audits within the date range
        $audits = Audit::whereBetween('date', [$startDate, $endDate])->get();

        $data = [];

        foreach ($audits as $audit) {
            // Get associated camera forms for each audit
            $cameraForms = CameraForm::where('audit_id', $audit->id)->get();

            foreach ($cameraForms as $cameraForm) {
                // Get associated notes for each camera form
                $notes = CameraFormNote::where('camera_form_id', $cameraForm->id)->get();

                foreach ($notes as $note) {
                    // Get associated attachments for each note
                    $attachments = CameraFormNoteAttachment::where('camera_form_note_id', $note->id)->get();

                    // Append attachments to the note
                    $note->attachments = $attachments;
                }

                // Append notes to the camera form
                $cameraForm->notes = $notes;
            }

            // Append camera forms to the audit
            $audit->camera_forms = $cameraForms;

            // Append the audit to the data array
            $data[] = $audit;
        }

        return response()->json($data);
    }
}
