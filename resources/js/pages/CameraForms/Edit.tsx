import { Head, router } from "@inertiajs/react";
import { FormEventHandler, useEffect, useMemo, useState } from "react";
import AuthenticatedLayout from "@/layouts/app-layout";
import { Audit, Entity, Rating, Store } from "@/types/models";
import { PageProps } from "@/types";

interface EditProps extends Record<string, unknown> {
  audit: Audit;
  entities: Entity[];
  ratings: Rating[];
  stores: Store[];
}

type ExistingAttachment = { id: number; url: string | null };

interface NoteFormData {
  id?: number; // existing note id if any
  note: string;

  image_files: File[];
  image_preview_urls: string[];

  existing_attachments: ExistingAttachment[];
  remove_attachment_ids: number[];
}

interface EntityFormData {
  entity_id: number;
  rating_id: number | null;

  notes: NoteFormData[];
  remove_note_ids: number[];
}

function fileFromClipboard(e: React.ClipboardEvent): File | null {
  const items = e.clipboardData?.items;
  if (!items) return null;

  for (const item of items) {
    if (item.type.startsWith("image/")) {
      const blob = item.getAsFile();
      if (!blob) return null;

      const ext =
        item.type === "image/png"
          ? "png"
          : item.type === "image/webp"
            ? "webp"
            : "jpg";

      return new File([blob], `pasted-${Date.now()}.${ext}`, {
        type: item.type,
      });
    }
  }
  return null;
}

function makeEmptyNote(): NoteFormData {
  return {
    note: "",
    image_files: [],
    image_preview_urls: [],
    existing_attachments: [],
    remove_attachment_ids: [],
  };
}

export default function Edit({
  auth,
  audit,
  entities = [],
  ratings = [],
  stores = [],
}: PageProps<EditProps>) {
  if (!audit) {
    return (
      <AuthenticatedLayout user={auth.user}>
        <Head title="Edit Camera Form" />
        <div className="flex flex-1 flex-col gap-4 p-4 pt-0">
          <div className="rounded-lg border bg-card p-6">
            <p className="text-muted-foreground">Loading...</p>
          </div>
        </div>
      </AuthenticatedLayout>
    );
  }

  const [dateRangeType, setDateRangeType] = useState<"daily" | "weekly">(() => {
    const first = audit.camera_forms?.find(
      (cf: any) => cf?.entity?.date_range_type,
    )?.entity?.date_range_type;
    return first === "weekly" ? "weekly" : "daily";
  });

  const [reportType, setReportType] = useState<"main" | "secondary" | "">(
    () => {
      const first = audit.camera_forms?.find(
        (cf: any) => cf?.entity?.report_type,
      )?.entity?.report_type;
      return first === "main" || first === "secondary" ? first : "";
    },
  );

  const [entityData, setEntityData] = useState<Record<number, EntityFormData>>(
    {},
  );

  const [storeId, setStoreId] = useState(audit.store_id?.toString() || "");
  const [date, setDate] = useState(() => {
    if (audit.date) {
      const dateObj = new Date(audit.date);
      return dateObj.toISOString().split("T")[0];
    }
    return "";
  });

  const [processing, setProcessing] = useState(false);
  const [errors, setErrors] = useState<any>({});

  const filteredEntities = useMemo(() => {
    return entities.filter((entity) => {
      const matchesDateRange = entity.date_range_type === dateRangeType;
      const matchesReportType =
        !reportType || entity.report_type === reportType;
      return matchesDateRange && matchesReportType;
    });
  }, [entities, dateRangeType, reportType]);

  useEffect(() => {
    setEntityData((prev) => {
      const next: Record<number, EntityFormData> = { ...prev };

      // Ensure entries exist
      filteredEntities.forEach((entity) => {
        if (!next[entity.id]) {
          next[entity.id] = {
            entity_id: entity.id,
            rating_id: null,
            notes: [],
            remove_note_ids: [],
          };
        }
      });

      // Hydrate from audit existing camera_forms
      audit.camera_forms?.forEach((cf: any) => {
        const eid = cf.entity_id;
        if (!eid) return;

        const existing = next[eid];

        const existingNotes: NoteFormData[] =
          (cf.notes ?? []).map((n: any) => ({
            id: n.id,
            note: n.note ?? "",
            image_files:
              existing?.notes?.find((x) => x.id === n.id)?.image_files ?? [],
            image_preview_urls:
              existing?.notes?.find((x) => x.id === n.id)?.image_preview_urls ??
              [],
            existing_attachments:
              (n.attachments ?? []).map((a: any) => ({
                id: a.id,
                url: a.url ?? null,
              })) ?? [],
            remove_attachment_ids:
              existing?.notes?.find((x) => x.id === n.id)
                ?.remove_attachment_ids ?? [],
          })) ?? [];

        next[eid] = {
          entity_id: eid,
          rating_id: cf.rating_id ?? null,
          notes: existing?.notes?.length ? existing.notes : existingNotes,
          remove_note_ids: existing?.remove_note_ids ?? [],
        };
      });

      return next;
    });
  }, [filteredEntities, audit.camera_forms]);

  const sortedEntities = useMemo(() => {
    return [...filteredEntities].sort((a, b) => {
      const orderA = a.sort_order ?? Number.MAX_SAFE_INTEGER;
      const orderB = b.sort_order ?? Number.MAX_SAFE_INTEGER;
      if (orderA !== orderB) return orderA - orderB;
      return a.entity_label.localeCompare(b.entity_label);
    });
  }, [filteredEntities]);

  const groupedEntities = useMemo(() => {
    return sortedEntities.reduce(
      (acc, entity) => {
        const categoryLabel = entity.category?.label || "Uncategorized";
        if (!acc[categoryLabel]) acc[categoryLabel] = [];
        acc[categoryLabel].push(entity);
        return acc;
      },
      {} as Record<string, Entity[]>,
    );
  }, [sortedEntities]);

  const sortedCategoryKeys = useMemo(() => {
    const keys = Object.keys(groupedEntities);
    return keys.sort((a, b) => {
      const catA = sortedEntities.find(
        (e) => (e.category?.label || "Uncategorized") === a,
      )?.category;
      const catB = sortedEntities.find(
        (e) => (e.category?.label || "Uncategorized") === b,
      )?.category;

      const orderA = catA?.sort_order ?? Number.MAX_SAFE_INTEGER;
      const orderB = catB?.sort_order ?? Number.MAX_SAFE_INTEGER;
      if (orderA !== orderB) return orderA - orderB;
      return a.localeCompare(b);
    });
  }, [groupedEntities, sortedEntities]);

  const updateEntity = (entityId: number, patch: Partial<EntityFormData>) => {
    setEntityData((prev) => {
      const current: EntityFormData =
        prev[entityId] ??
        ({
          entity_id: entityId,
          rating_id: null,
          notes: [],
          remove_note_ids: [],
        } as EntityFormData);

      return {
        ...prev,
        [entityId]: {
          ...current,
          ...patch,
        },
      };
    });
  };

  const updateNoteAt = (
    entityId: number,
    noteIndex: number,
    patch: Partial<NoteFormData>,
  ) => {
    setEntityData((prev) => {
      const current = prev[entityId];
      if (!current) return prev;

      const notes = [...current.notes];
      const existing = notes[noteIndex] ?? makeEmptyNote();

      notes[noteIndex] = {
        ...existing,
        ...patch,
      };

      return {
        ...prev,
        [entityId]: {
          ...current,
          notes,
        },
      };
    });
  };

  const addNote = (entityId: number) => {
    const current = entityData[entityId] ?? {
      entity_id: entityId,
      rating_id: null,
      notes: [],
      remove_note_ids: [],
    };

    updateEntity(entityId, {
      notes: [...current.notes, makeEmptyNote()],
    });
  };

  const removeNote = (entityId: number, noteIndex: number) => {
    const current = entityData[entityId];
    if (!current) return;

    const note = current.notes[noteIndex];
    if (!note) return;

    // If existing note -> mark for removal
    if (note.id) {
      if (!current.remove_note_ids.includes(note.id)) {
        updateEntity(entityId, {
          remove_note_ids: [...current.remove_note_ids, note.id],
        });
      }
    }

    // Revoke new previews
    if (note.image_preview_urls?.length) {
      note.image_preview_urls.forEach((u) => URL.revokeObjectURL(u));
    }

    // Remove from UI immediately
    const nextNotes = current.notes.filter((_, i) => i !== noteIndex);
    updateEntity(entityId, { notes: nextNotes });
  };

  const addFilesToNote = (
    entityId: number,
    noteIndex: number,
    files: File[],
  ) => {
    if (!files.length) return;

    const current = entityData[entityId];
    if (!current) return;

    const note = current.notes[noteIndex] ?? makeEmptyNote();
    const newUrls = files.map((f) => URL.createObjectURL(f));

    updateNoteAt(entityId, noteIndex, {
      image_files: [...note.image_files, ...files],
      image_preview_urls: [...note.image_preview_urls, ...newUrls],
    });
  };

  const removeNewPreviewAt = (
    entityId: number,
    noteIndex: number,
    fileIndex: number,
  ) => {
    const current = entityData[entityId];
    if (!current) return;

    const note = current.notes[noteIndex];
    if (!note) return;

    const url = note.image_preview_urls[fileIndex];
    if (url) URL.revokeObjectURL(url);

    updateNoteAt(entityId, noteIndex, {
      image_files: note.image_files.filter((_, i) => i !== fileIndex),
      image_preview_urls: note.image_preview_urls.filter(
        (_, i) => i !== fileIndex,
      ),
    });
  };

  const handlePaste = (
    entityId: number,
    noteIndex: number,
    e: React.ClipboardEvent,
  ) => {
    const file = fileFromClipboard(e);
    if (!file) return;

    e.preventDefault();
    addFilesToNote(entityId, noteIndex, [file]);
  };

  const removeExistingAttachment = (
    entityId: number,
    noteIndex: number,
    attachmentId: number,
  ) => {
    const current = entityData[entityId];
    if (!current) return;

    const note = current.notes[noteIndex];
    if (!note) return;

    updateNoteAt(entityId, noteIndex, {
      existing_attachments: note.existing_attachments.filter(
        (a) => a.id !== attachmentId,
      ),
      remove_attachment_ids: note.remove_attachment_ids.includes(attachmentId)
        ? note.remove_attachment_ids
        : [...note.remove_attachment_ids, attachmentId],
    });
  };

  const handleSubmit: FormEventHandler = (e) => {
    e.preventDefault();
    setProcessing(true);
    setErrors({});

    const fd = new FormData();
    fd.append("store_id", storeId);
    fd.append("date", date);

    // Send all entityData so filter toggles don’t lose data
    const all = Object.values(entityData);

    const rows = all.filter((x) => {
      const hasRating = x.rating_id !== null;

      const hasRemovals = x.remove_note_ids.length > 0;

      const hasNotesOrImagesOrAttachmentRemovals =
        x.notes?.some((n) => {
          const hasNoteText = n.note && n.note.trim() !== "";
          const hasNewImages = n.image_files.length > 0;
          const hasAttachmentRemovals = n.remove_attachment_ids.length > 0;
          const hasExistingAttachments = n.existing_attachments.length > 0;
          return (
            hasNoteText ||
            hasNewImages ||
            hasAttachmentRemovals ||
            hasExistingAttachments
          );
        }) ?? false;

      return hasRating || hasNotesOrImagesOrAttachmentRemovals || hasRemovals;
    });

    rows.forEach((x, idx) => {
      fd.append(`entities[${idx}][entity_id]`, String(x.entity_id));
      if (x.rating_id !== null) {
        fd.append(`entities[${idx}][rating_id]`, String(x.rating_id));
      }

      // remove whole notes
      x.remove_note_ids.forEach((nid, k) => {
        fd.append(`entities[${idx}][remove_note_ids][${k}]`, String(nid));
      });

      // notes
      x.notes.forEach((n, j) => {
        if (n.id) fd.append(`entities[${idx}][notes][${j}][id]`, String(n.id));
        if (n.note) fd.append(`entities[${idx}][notes][${j}][note]`, n.note);

        n.image_files.forEach((file, k) => {
          fd.append(`entities[${idx}][notes][${j}][images][${k}]`, file);
        });

        n.remove_attachment_ids.forEach((aid, k) => {
          fd.append(
            `entities[${idx}][notes][${j}][remove_attachment_ids][${k}]`,
            String(aid),
          );
        });
      });
    });

    router.post(`/camera-forms/${audit.id}?_method=PUT`, fd, {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => setProcessing(false),
      onError: (pageErrors) => {
        setErrors(pageErrors);
        setProcessing(false);
      },
    });
  };

  return (
    <AuthenticatedLayout user={auth.user}>
      <Head title="Edit Camera Form" />

      <div className="flex flex-1 flex-col gap-4 p-4 pt-0">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold tracking-tight">
              Edit Camera Form
            </h1>
            <p className="text-sm text-muted-foreground mt-1">
              Multiple notes per entity, each with optional attachments (Ctrl+V)
            </p>
          </div>
          <a
            href="/camera-forms"
            className="text-sm font-medium text-muted-foreground hover:text-foreground"
          >
            ← Back
          </a>
        </div>

        <form onSubmit={handleSubmit} className="space-y-6">
          {/* Filters Card */}
          <div className="rounded-lg border bg-accent/50 p-6">
            <h3 className="text-lg font-semibold mb-4">Filter Entities</h3>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <label className="text-sm font-medium">Date Range Type</label>
                <select
                  value={dateRangeType}
                  onChange={(e) =>
                    setDateRangeType(e.target.value as "daily" | "weekly")
                  }
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  <option value="daily">Daily</option>
                  <option value="weekly">Weekly</option>
                </select>
              </div>

              <div className="space-y-2">
                <label className="text-sm font-medium">Report Type</label>
                <select
                  value={reportType}
                  onChange={(e) =>
                    setReportType(e.target.value as "main" | "secondary" | "")
                  }
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  <option value="">All Types</option>
                  <option value="main">Main</option>
                  <option value="secondary">Secondary</option>
                </select>
              </div>
            </div>

            <p className="text-xs text-muted-foreground mt-2">
              Showing {filteredEntities.length} entities
            </p>
          </div>

          {/* Basic Info */}
          <div className="rounded-lg border bg-card p-6">
            <h3 className="text-lg font-semibold mb-4">Basic Information</h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <label className="text-sm font-medium">
                  Store <span className="text-destructive">*</span>
                </label>
                <select
                  value={storeId}
                  onChange={(e) => setStoreId(e.target.value)}
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  required
                >
                  <option value="">Select Store</option>
                  {stores.map((store) => (
                    <option key={store.id} value={store.id}>
                      {store.store}
                    </option>
                  ))}
                </select>
                {errors.store_id && (
                  <p className="text-sm text-destructive">{errors.store_id}</p>
                )}
              </div>

              <div className="space-y-2">
                <label className="text-sm font-medium">
                  Date <span className="text-destructive">*</span>
                </label>
                <input
                  type="date"
                  value={date}
                  onChange={(e) => setDate(e.target.value)}
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  required
                />
                {errors.date && (
                  <p className="text-sm text-destructive">{errors.date}</p>
                )}
              </div>
            </div>
          </div>

          {/* Entities */}
          <div className="space-y-4">
            {sortedCategoryKeys.map((categoryLabel) => {
              const categoryEntities = groupedEntities[categoryLabel];
              return (
                <div key={categoryLabel} className="rounded-lg border bg-card">
                  <div className="border-b bg-muted/50 px-6 py-4">
                    <h3 className="text-lg font-semibold">{categoryLabel}</h3>
                  </div>

                  <div className="p-6 space-y-4">
                    {categoryEntities.map((entity) => {
                      const data = entityData[entity.id];
                      const notes = data?.notes ?? [];

                      return (
                        <div
                          key={entity.id}
                          className="p-4 rounded-md bg-muted/20 space-y-4"
                        >
                          <div className="grid grid-cols-1 lg:grid-cols-4 gap-4">
                            <div className="flex items-center">
                              <label className="text-sm font-medium">
                                {entity.entity_label}
                              </label>
                            </div>

                            <div className="space-y-1">
                              <select
                                value={data?.rating_id ?? ""}
                                onChange={(e) =>
                                  updateEntity(entity.id, {
                                    rating_id: e.target.value
                                      ? Number(e.target.value)
                                      : null,
                                  })
                                }
                                className="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                              >
                                <option value="">Select Rating</option>
                                {ratings.map((rating) => (
                                  <option key={rating.id} value={rating.id}>
                                    {rating.label}
                                  </option>
                                ))}
                              </select>
                            </div>

                            <div className="lg:col-span-2 flex justify-end">
                              <button
                                type="button"
                                onClick={() => addNote(entity.id)}
                                className="text-xs rounded-md border px-3 py-2 hover:bg-accent"
                              >
                                + Add Note / Attachment
                              </button>
                            </div>
                          </div>

                          {/* Notes blocks */}
                          {notes.length ? (
                            <div className="space-y-3">
                              {notes.map((n, noteIndex) => (
                                <div
                                  key={n.id ?? `new-${noteIndex}`}
                                  className="rounded-md border bg-background p-4 space-y-3"
                                >
                                  <div className="flex items-center justify-between gap-2">
                                    <div className="text-xs font-semibold text-muted-foreground">
                                      Note #{noteIndex + 1}{" "}
                                      {n.id ? (
                                        <span className="text-[10px] ml-1">
                                          (ID: {n.id})
                                        </span>
                                      ) : null}
                                    </div>

                                    <button
                                      type="button"
                                      onClick={() =>
                                        removeNote(entity.id, noteIndex)
                                      }
                                      className="text-xs rounded-md border px-2 py-1 hover:bg-accent"
                                    >
                                      Remove Note
                                    </button>
                                  </div>

                                  <textarea
                                    value={n.note}
                                    onChange={(e) =>
                                      updateNoteAt(entity.id, noteIndex, {
                                        note: e.target.value,
                                      })
                                    }
                                    placeholder="Write a note (optional)"
                                    className="min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                  />

                                  <div
                                    onPaste={(e) =>
                                      handlePaste(entity.id, noteIndex, e)
                                    }
                                    className="rounded-md border border-dashed bg-background p-3"
                                    tabIndex={0}
                                  >
                                    <div className="text-xs text-muted-foreground">
                                      Click here then{" "}
                                      <span className="font-semibold">
                                        Ctrl+V
                                      </span>{" "}
                                      to paste images, or choose files:
                                    </div>

                                    <div className="mt-2 flex items-center gap-2">
                                      <input
                                        type="file"
                                        accept="image/*"
                                        multiple
                                        onChange={(e) => {
                                          const files = Array.from(
                                            e.target.files ?? [],
                                          );
                                          addFilesToNote(
                                            entity.id,
                                            noteIndex,
                                            files,
                                          );
                                          e.currentTarget.value = "";
                                        }}
                                        className="block w-full text-xs"
                                      />
                                    </div>

                                    {/* Existing attachments */}
                                    {n.existing_attachments.length ? (
                                      <div className="mt-3 space-y-2">
                                        <div className="text-[11px] text-muted-foreground">
                                          Existing attachments:
                                        </div>
                                        {n.existing_attachments.map((att) => (
                                          <div
                                            key={att.id}
                                            className="flex items-center gap-2"
                                          >
                                            {att.url ? (
                                              <img
                                                src={att.url}
                                                alt="Existing"
                                                className="max-h-24 rounded-md border"
                                              />
                                            ) : (
                                              <div className="text-xs text-muted-foreground">
                                                (missing url)
                                              </div>
                                            )}
                                            <button
                                              type="button"
                                              onClick={() =>
                                                removeExistingAttachment(
                                                  entity.id,
                                                  noteIndex,
                                                  att.id,
                                                )
                                              }
                                              className="text-xs rounded-md border px-2 py-1 hover:bg-accent"
                                            >
                                              Remove
                                            </button>
                                          </div>
                                        ))}
                                      </div>
                                    ) : null}

                                    {/* New previews */}
                                    {n.image_preview_urls.length ? (
                                      <div className="mt-3 space-y-2">
                                        <div className="text-[11px] text-muted-foreground">
                                          New uploads:
                                        </div>
                                        {n.image_preview_urls.map(
                                          (url, idx) => (
                                            <div
                                              key={url}
                                              className="flex items-center gap-2"
                                            >
                                              <img
                                                src={url}
                                                alt="Preview"
                                                className="max-h-24 rounded-md border"
                                              />
                                              <button
                                                type="button"
                                                onClick={() =>
                                                  removeNewPreviewAt(
                                                    entity.id,
                                                    noteIndex,
                                                    idx,
                                                  )
                                                }
                                                className="text-xs rounded-md border px-2 py-1 hover:bg-accent"
                                              >
                                                Remove
                                              </button>
                                            </div>
                                          ),
                                        )}
                                      </div>
                                    ) : null}
                                  </div>
                                </div>
                              ))}
                            </div>
                          ) : (
                            <div className="text-xs text-muted-foreground">
                              No notes yet. Use “Add Note / Attachment”.
                            </div>
                          )}
                        </div>
                      );
                    })}
                  </div>
                </div>
              );
            })}
          </div>

          {errors.entities && (
            <p className="text-sm text-destructive">{errors.entities}</p>
          )}

          <div className="flex justify-end gap-3 pt-6 border-t">
            <a
              href="/camera-forms"
              className="inline-flex items-center justify-center rounded-md border border-input bg-background px-4 py-2 text-sm font-medium hover:bg-accent hover:text-accent-foreground"
            >
              Cancel
            </a>
            <button
              type="submit"
              disabled={processing}
              className="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 disabled:opacity-50"
            >
              {processing ? "Updating..." : "Update Form"}
            </button>
          </div>
        </form>
      </div>
    </AuthenticatedLayout>
  );
}
