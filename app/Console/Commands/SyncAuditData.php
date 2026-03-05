<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Entity;
use App\Models\Store;
use App\Models\Audit;
use App\Models\CameraForm;
use App\Models\CameraFormNote;
use App\Models\CameraFormNoteAttachment;
use Carbon\Carbon;
use Http;

class SyncAuditData extends Command
{
    protected $signature = 'sync:audits {start_date} {end_date}';
    protected $description = 'Sync audit data from the old system to the new one.';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $startDate = $this->argument('start_date');
        $endDate = $this->argument('end_date');

        // Break the date range into 5-day chunks
        $dateRangeChunks = $this->getDateRangeChunks($startDate, $endDate);

        // Process each date range chunk
        foreach ($dateRangeChunks as $chunk) {
            $this->info("Fetching data for range: {$chunk['start']} to {$chunk['end']}");

            // Fetch data from the old system
            $audits = $this->getAuditsFromOldSystem($chunk['start'], $chunk['end']);

            // Sync the audits to the new system
            foreach ($audits as $auditData) {
                $this->syncAudit($auditData);
            }
        }

        $this->info('Sync process complete.');
    }

    public function getDateRangeChunks($startDate, $endDate)
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $chunks = [];

        while ($start <= $end) {
            $chunkStart = $start->format('Y-m-d');
            $chunkEnd = $start->copy()->addDays(4)->format('Y-m-d'); // Add 5 days

            $chunks[] = ['start' => $chunkStart, 'end' => $chunkEnd];

            $start->addDays(5); // Move to next chunk
        }

        return $chunks;
    }

    public function getAuditsFromOldSystem($startDate, $endDate)
    {
        // Simulate the functionality of the controller method to get audits
        $url = 'https://qa.pnefoods.com/api/get-audits'; // Replace with the real route to hit
        $response = Http::post($url, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        return $response->json();
    }

    public function syncAudit($auditData)
    {
        // Match user by email
        $user = User::where('email', strtolower($auditData['user_email']))->first();

        // Match entity by label
        $entity = Entity::where('entity_label', $auditData['entity_label'])->first();

        // Match store by name (field in the store model is `store`, not `store_name`)
        $store = Store::where('store', $auditData['store'])->first(); // Corrected here

        // Create or update the audit record
        $audit = Audit::updateOrCreate(
            ['source_audit_id' => $auditData['id']],
            [
                'store_id' => $store->id,
                'user_id' => $user->id,
                'date' => $auditData['date'],
                'rating_id' => $auditData['rating_id'],
            ]
        );

        // Sync associated camera forms
        foreach ($auditData['camera_forms'] as $cameraFormData) {
            $this->syncCameraForm($cameraFormData, $audit->id, $entity->id);
        }
    }

    public function syncCameraForm($cameraFormData, $auditId, $entityId)
    {
        // Create or update the camera form
        $cameraForm = CameraForm::updateOrCreate(
            ['source_camera_form_id' => $cameraFormData['id']],
            [
                'user_id' => $cameraFormData['user_id'],
                'entity_id' => $entityId,
                'audit_id' => $auditId,
                'rating_id' => $cameraFormData['rating_id'],
            ]
        );

        // Sync notes
        foreach ($cameraFormData['notes'] as $noteData) {
            $this->syncNote($noteData, $cameraForm->id);
        }
    }

    public function syncNote($noteData, $cameraFormId)
    {
        $note = CameraFormNote::updateOrCreate(
            ['source_note_id' => $noteData['id']],
            [
                'camera_form_id' => $cameraFormId,
                'note' => $noteData['note'],
            ]
        );

        // Sync attachments
        foreach ($noteData['attachments'] as $attachmentData) {
            $this->syncAttachment($attachmentData, $note->id);
        }
    }

    public function syncAttachment($attachmentData, $noteId)
    {
        $attachment = CameraFormNoteAttachment::updateOrCreate(
            ['source_attachment_id' => $attachmentData['id']],
            [
                'camera_form_note_id' => $noteId,
                'path' => $attachmentData['path'],
            ]
        );

        // Download and store the attachment
        $filePath = 'attachments/' . basename($attachmentData['path']);
        $content = Http::get($attachmentData['path']);
        \Storage::put($filePath, $content->body());
    }
}