<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 1) camera_form_notes
        Schema::create('camera_form_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('camera_form_id')
                ->constrained('camera_forms')
                ->cascadeOnDelete();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['camera_form_id']);
        });

        // 2) camera_form_note_attachments
        Schema::create('camera_form_note_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('camera_form_note_id')
                ->constrained('camera_form_notes')
                ->cascadeOnDelete();

            $table->string('path');
            $table->timestamps();

            $table->index(['camera_form_note_id']);
        });

        // 3) Migrate old camera_forms.note -> camera_form_notes.note
        if (Schema::hasColumn('camera_forms', 'note')) {
            $forms = DB::table('camera_forms')
                ->select('id', 'note', 'created_at', 'updated_at')
                ->get();

            foreach ($forms as $form) {
                $noteText = is_string($form->note) ? trim($form->note) : null;
                if ($noteText !== null && $noteText !== '') {
                    DB::table('camera_form_notes')->insert([
                        'camera_form_id' => $form->id,
                        'note' => $form->note,
                        'created_at' => $form->created_at ?? now(),
                        'updated_at' => $form->updated_at ?? now(),
                    ]);
                }
            }
        }

        // 4) Migrate old camera_form_attachments -> camera_form_note_attachments
        //    Attach them to an existing migrated note if exists, else create an empty note (note = null)
        if (Schema::hasTable('camera_form_attachments')) {
            $atts = DB::table('camera_form_attachments')
                ->select('id', 'camera_form_id', 'path', 'created_at', 'updated_at')
                ->orderBy('id')
                ->get();

            foreach ($atts as $att) {
                $existingNoteId = DB::table('camera_form_notes')
                    ->where('camera_form_id', $att->camera_form_id)
                    ->orderBy('id')
                    ->value('id');

                if (!$existingNoteId) {
                    // create attachment-only note
                    $form = DB::table('camera_forms')
                        ->select('created_at', 'updated_at')
                        ->where('id', $att->camera_form_id)
                        ->first();

                    $existingNoteId = DB::table('camera_form_notes')->insertGetId([
                        'camera_form_id' => $att->camera_form_id,
                        'note' => null,
                        'created_at' => $form->created_at ?? now(),
                        'updated_at' => $form->updated_at ?? now(),
                    ]);
                }

                DB::table('camera_form_note_attachments')->insert([
                    'camera_form_note_id' => $existingNoteId,
                    'path' => $att->path,
                    'created_at' => $att->created_at ?? now(),
                    'updated_at' => $att->updated_at ?? now(),
                ]);
            }

            Schema::dropIfExists('camera_form_attachments');
        }

        // 5) Drop old note column from camera_forms
        if (Schema::hasColumn('camera_forms', 'note')) {
            Schema::table('camera_forms', function (Blueprint $table) {
                $table->dropColumn('note');
            });
        }
    }

    public function down(): void
    {
        // Restore old note column
        if (!Schema::hasColumn('camera_forms', 'note')) {
            Schema::table('camera_forms', function (Blueprint $table) {
                $table->text('note')->nullable()->after('rating_id');
            });
        }

        // Recreate old attachments table
        if (!Schema::hasTable('camera_form_attachments')) {
            Schema::create('camera_form_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('camera_form_id')
                    ->constrained('camera_forms')
                    ->cascadeOnDelete();
                $table->string('path');
                $table->timestamps();
                $table->index(['camera_form_id']);
            });
        }

        // Best-effort: move first note.note back to camera_forms.note
        if (Schema::hasTable('camera_form_notes')) {
            $firstNotes = DB::table('camera_form_notes')
                ->select('camera_form_id', DB::raw('MIN(id) as min_id'))
                ->groupBy('camera_form_id')
                ->get();

            foreach ($firstNotes as $fn) {
                $note = DB::table('camera_form_notes')->where('id', $fn->min_id)->first();
                if ($note) {
                    DB::table('camera_forms')
                        ->where('id', $note->camera_form_id)
                        ->update(['note' => $note->note]);
                }
            }
        }

        // Best-effort: move all note attachments back onto camera_form_attachments
        if (Schema::hasTable('camera_form_note_attachments') && Schema::hasTable('camera_form_notes')) {
            $rows = DB::table('camera_form_note_attachments as a')
                ->join('camera_form_notes as n', 'n.id', '=', 'a.camera_form_note_id')
                ->select('n.camera_form_id', 'a.path', 'a.created_at', 'a.updated_at')
                ->get();

            foreach ($rows as $r) {
                DB::table('camera_form_attachments')->insert([
                    'camera_form_id' => $r->camera_form_id,
                    'path' => $r->path,
                    'created_at' => $r->created_at ?? now(),
                    'updated_at' => $r->updated_at ?? now(),
                ]);
            }
        }

        Schema::dropIfExists('camera_form_note_attachments');
        Schema::dropIfExists('camera_form_notes');
    }
};
