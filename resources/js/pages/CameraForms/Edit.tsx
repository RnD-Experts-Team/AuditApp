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

interface EntityFormData {
  entity_id: number;
  rating_id: number | null;
  note: string;

  // NEW
  image_file: File | null;
  image_preview_url: string | null; // preview for newly chosen/pasted file
  existing_image_url: string | null; // backend image
  remove_image: boolean;
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

      filteredEntities.forEach((entity) => {
        if (!next[entity.id]) {
          next[entity.id] = {
            entity_id: entity.id,
            rating_id: null,
            note: "",
            image_file: null,
            image_preview_url: null,
            existing_image_url: null,
            remove_image: false,
          };
        }
      });

      audit.camera_forms?.forEach((cf: any) => {
        const eid = cf.entity_id;
        if (!eid) return;

        // Don't destroy a new preview if user already pasted something
        const existing = next[eid];

        next[eid] = {
          entity_id: eid,
          rating_id: cf.rating_id ?? null,
          note: cf.note ?? "",
          image_file: existing?.image_file ?? null,
          image_preview_url: existing?.image_preview_url ?? null,
          existing_image_url: cf.image_url ?? null,
          remove_image: existing?.remove_image ?? false,
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

  const updateEntityData = (
    entityId: number,
    field: keyof EntityFormData,
    value: any,
  ) => {
    setEntityData((prev) => ({
      ...prev,
      [entityId]: {
        ...(prev[entityId] ?? {
          entity_id: entityId,
          rating_id: null,
          note: "",
          image_file: null,
          image_preview_url: null,
          existing_image_url: null,
          remove_image: false,
        }),
        [field]: value,
      },
    }));
  };

  const handlePickFile = (entityId: number, file: File | null) => {
    const current = entityData[entityId];
    if (current?.image_preview_url)
      URL.revokeObjectURL(current.image_preview_url);

    if (!file) {
      updateEntityData(entityId, "image_file", null);
      updateEntityData(entityId, "image_preview_url", null);
      return;
    }

    updateEntityData(entityId, "image_file", file);
    updateEntityData(entityId, "image_preview_url", URL.createObjectURL(file));
    updateEntityData(entityId, "remove_image", false);
  };

  const handlePaste = (entityId: number, e: React.ClipboardEvent) => {
    const file = fileFromClipboard(e);
    if (!file) return;

    e.preventDefault();
    handlePickFile(entityId, file);
  };

  const handleRemoveExistingImage = (entityId: number) => {
    // clear new preview too
    handlePickFile(entityId, null);
    updateEntityData(entityId, "remove_image", true);
    updateEntityData(entityId, "existing_image_url", null);
  };

  const handleSubmit: FormEventHandler = (e) => {
    e.preventDefault();
    setProcessing(true);
    setErrors({});

    // Build FormData so we can include files
    const fd = new FormData();
    fd.append("store_id", storeId);
    fd.append("date", date);

    // ✅ Send all current entityData (not only filtered) so toggling filters doesn’t lose data
    const all = Object.values(entityData);

    // keep only items that matter (rating OR note OR image OR remove flag OR existing image)
    const rows = all.filter((x) => {
      const hasRating = x.rating_id !== null;
      const hasNote = x.note && x.note.trim() !== "";
      const hasNewImage = !!x.image_file;
      const hasExisting = !!x.existing_image_url;
      const wantsRemove = !!x.remove_image;
      return hasRating || hasNote || hasNewImage || hasExisting || wantsRemove;
    });

    rows.forEach((x, idx) => {
      fd.append(`entities[${idx}][entity_id]`, String(x.entity_id));
      if (x.rating_id !== null)
        fd.append(`entities[${idx}][rating_id]`, String(x.rating_id));
      if (x.note) fd.append(`entities[${idx}][note]`, x.note);

      if (x.image_file) fd.append(`entities[${idx}][image]`, x.image_file);
      if (x.remove_image) fd.append(`entities[${idx}][remove_image]`, "1");
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
              Update inspection values and paste images per entity (Ctrl+V)
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
                      return (
                        <div
                          key={entity.id}
                          className="grid grid-cols-1 lg:grid-cols-4 gap-4 p-4 rounded-md bg-muted/20"
                        >
                          <div className="flex items-center">
                            <label className="text-sm font-medium">
                              {entity.entity_label}
                            </label>
                          </div>

                          <div className="space-y-1">
                            <select
                              value={data?.rating_id ?? ""}
                              onChange={(e) =>
                                updateEntityData(
                                  entity.id,
                                  "rating_id",
                                  e.target.value
                                    ? Number(e.target.value)
                                    : null,
                                )
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

                          <div className="space-y-1">
                            <input
                              type="text"
                              placeholder="Note (optional)"
                              value={data?.note ?? ""}
                              onChange={(e) =>
                                updateEntityData(
                                  entity.id,
                                  "note",
                                  e.target.value,
                                )
                              }
                              className="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            />
                          </div>

                          {/* Image */}
                          <div className="space-y-2">
                            <div
                              onPaste={(e) => handlePaste(entity.id, e)}
                              className="rounded-md border border-dashed bg-background p-3"
                              tabIndex={0}
                            >
                              <div className="text-xs text-muted-foreground">
                                Click here then{" "}
                                <span className="font-semibold">Ctrl+V</span> to
                                paste an image, or choose a file:
                              </div>

                              <div className="mt-2 flex items-center gap-2">
                                <input
                                  type="file"
                                  accept="image/*"
                                  onChange={(e) =>
                                    handlePickFile(
                                      entity.id,
                                      e.target.files?.[0] ?? null,
                                    )
                                  }
                                  className="block w-full text-xs"
                                />

                                {(data?.image_preview_url ||
                                  data?.existing_image_url) && (
                                  <button
                                    type="button"
                                    onClick={() =>
                                      handleRemoveExistingImage(entity.id)
                                    }
                                    className="text-xs rounded-md border px-2 py-1 hover:bg-accent"
                                  >
                                    Remove
                                  </button>
                                )}
                              </div>

                              {/* Preview new image */}
                              {data?.image_preview_url && (
                                <div className="mt-3">
                                  <div className="text-[11px] text-muted-foreground mb-1">
                                    New image preview:
                                  </div>
                                  <img
                                    src={data.image_preview_url}
                                    alt="Preview"
                                    className="max-h-32 rounded-md border"
                                  />
                                </div>
                              )}

                              {/* Existing image */}
                              {!data?.image_preview_url &&
                                data?.existing_image_url && (
                                  <div className="mt-3">
                                    <div className="text-[11px] text-muted-foreground mb-1">
                                      Existing image:
                                    </div>
                                    <img
                                      src={data.existing_image_url}
                                      alt="Existing"
                                      className="max-h-32 rounded-md border"
                                    />
                                  </div>
                                )}
                            </div>
                          </div>
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
