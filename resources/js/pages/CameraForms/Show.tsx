import { Head, Link, router } from "@inertiajs/react";
import AuthenticatedLayout from "@/layouts/app-layout";
import { Audit, Entity } from "@/types/models";
import { PageProps } from "@/types";
import {
  Calendar,
  Store as StoreIcon,
  User,
  FileText,
  Edit,
  ArrowLeft,
  Trash2,
  CheckCircle2,
  XCircle,
  Image as ImageIcon,
} from "lucide-react";

interface ShowProps extends Record<string, unknown> {
  audit: Audit;
}

export default function Show({ auth, audit }: PageProps<ShowProps>) {
  if (!audit) {
    return (
      <AuthenticatedLayout user={auth.user}>
        <Head title="View Camera Form" />
        <div className="flex flex-1 flex-col gap-4 p-4 pt-0">
          <div className="rounded-lg border bg-card p-6">
            <p className="text-muted-foreground">Loading...</p>
          </div>
        </div>
      </AuthenticatedLayout>
    );
  }

  const entitiesWithData = audit.camera_forms
    ?.map((cf) => cf.entity)
    .filter(Boolean) as Entity[];

  const groupedEntities = entitiesWithData.reduce(
    (acc, entity) => {
      const categoryLabel = entity.category?.label || "Uncategorized";
      if (!acc[categoryLabel]) {
        acc[categoryLabel] = [];
      }
      acc[categoryLabel].push(entity);
      return acc;
    },
    {} as Record<string, Entity[]>,
  );

  const getCameraFormForEntity = (entityId: number) => {
    return audit.camera_forms?.find((cf) => cf.entity_id === entityId);
  };

  const handleDelete = () => {
    if (confirm("Are you sure you want to delete this camera form?")) {
      router.delete(`/camera-forms/${audit.id}`, {
        onSuccess: () => router.visit("/camera-forms"),
      });
    }
  };

  return (
    <AuthenticatedLayout user={auth.user}>
      <Head title="View Camera Form" />

      <div className="flex flex-1 flex-col gap-6 p-4 pt-0">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div className="space-y-1">
            <div className="flex items-center gap-2">
              <Link
                href="/camera-forms"
                className="text-muted-foreground hover:text-foreground transition-colors"
              >
                <ArrowLeft className="h-4 w-4" />
              </Link>
              <h1 className="text-3xl font-bold tracking-tight">
                Camera Form Details
              </h1>
            </div>
            <p className="text-sm text-muted-foreground">
              Form ID: #{audit.id}
            </p>
          </div>
          <div className="flex gap-2">
            <Link
              href={`/camera-forms/${audit.id}/edit`}
              className="inline-flex items-center gap-2 justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors"
            >
              <Edit className="h-4 w-4" />
              Edit
            </Link>
            <button
              onClick={handleDelete}
              className="inline-flex items-center gap-2 justify-center rounded-md border border-destructive bg-destructive/10 px-4 py-2 text-sm font-medium text-destructive hover:bg-destructive/20 transition-colors"
            >
              <Trash2 className="h-4 w-4" />
              Delete
            </button>
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <div className="rounded-lg border bg-card p-6 hover:shadow-md transition-shadow">
            <div className="flex items-center gap-3">
              <div className="rounded-full bg-primary/10 p-3">
                <Calendar className="h-5 w-5 text-primary" />
              </div>
              <div className="space-y-1">
                <p className="text-xs font-medium text-muted-foreground">
                  Date
                </p>
                <p className="text-sm font-bold">
                  {new Date(audit.date).toLocaleDateString("en-US", {
                    month: "short",
                    day: "numeric",
                    year: "numeric",
                  })}
                </p>
              </div>
            </div>
          </div>

          <div className="rounded-lg border bg-card p-6 hover:shadow-md transition-shadow">
            <div className="flex items-center gap-3">
              <div className="rounded-full bg-accent/50 p-3">
                <StoreIcon className="h-5 w-5 text-accent-foreground" />
              </div>
              <div className="space-y-1">
                <p className="text-xs font-medium text-muted-foreground">
                  Store
                </p>
                <p className="text-sm font-bold">{audit.store?.store}</p>
              </div>
            </div>
          </div>

          <div className="rounded-lg border bg-card p-6 hover:shadow-md transition-shadow">
            <div className="flex items-center gap-3">
              <div className="rounded-full bg-secondary p-3">
                <User className="h-5 w-5 text-secondary-foreground" />
              </div>
              <div className="space-y-1">
                <p className="text-xs font-medium text-muted-foreground">
                  Created By
                </p>
                <p className="text-sm font-bold">{audit.user?.name}</p>
              </div>
            </div>
          </div>

          <div className="rounded-lg border bg-card p-6 hover:shadow-md transition-shadow">
            <div className="flex items-center gap-3">
              <div className="rounded-full bg-muted p-3">
                <FileText className="h-5 w-5 text-muted-foreground" />
              </div>
              <div className="space-y-1">
                <p className="text-xs font-medium text-muted-foreground">
                  Total Entities
                </p>
                <p className="text-sm font-bold">{entitiesWithData.length}</p>
              </div>
            </div>
          </div>
        </div>

        <div className="rounded-lg border bg-card overflow-hidden">
          <div className="bg-gradient-to-r from-primary/10 to-accent/10 px-6 py-4 border-b">
            <h3 className="text-lg font-semibold">Inspection Results</h3>
            <p className="text-sm text-muted-foreground mt-1">
              All entities grouped by category (with optional images)
            </p>
          </div>

          <div className="p-6 space-y-8">
            {Object.entries(groupedEntities).map(
              ([categoryLabel, categoryEntities], categoryIndex) => (
                <div key={categoryLabel} className="space-y-4">
                  <div className="flex items-center gap-3 pb-3 border-b">
                    <div className="flex items-center justify-center w-8 h-8 rounded-full bg-primary/20 text-primary text-sm font-bold">
                      {categoryIndex + 1}
                    </div>
                    <div>
                      <h4 className="text-base font-semibold">
                        {categoryLabel}
                      </h4>
                      <p className="text-xs text-muted-foreground">
                        {categoryEntities.length}{" "}
                        {categoryEntities.length === 1 ? "item" : "items"}
                      </p>
                    </div>
                  </div>

                  <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 pl-11">
                    {categoryEntities.map((entity) => {
                      const cameraForm: any = getCameraFormForEntity(entity.id);
                      const hasRating = cameraForm?.rating?.label;
                      const hasNote =
                        cameraForm?.note && cameraForm.note.trim().length > 0;
                      const hasImage = !!cameraForm?.image_url;

                      return (
                        <div
                          key={entity.id}
                          className="group rounded-lg border bg-background hover:bg-accent/5 p-4 transition-all hover:shadow-md"
                        >
                          <div className="flex items-start justify-between gap-2 mb-3">
                            <h5 className="text-sm font-medium leading-tight flex-1">
                              {entity.entity_label}
                            </h5>
                            {hasRating ? (
                              <CheckCircle2 className="h-4 w-4 text-primary flex-shrink-0" />
                            ) : (
                              <XCircle className="h-4 w-4 text-muted-foreground flex-shrink-0" />
                            )}
                          </div>

                          <div className="mb-2 flex items-center gap-2">
                            {hasRating ? (
                              <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-primary text-primary-foreground">
                                {cameraForm.rating.label}
                              </span>
                            ) : (
                              <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-muted text-muted-foreground">
                                No rating
                              </span>
                            )}

                            {hasImage && (
                              <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-muted text-muted-foreground">
                                <ImageIcon className="h-3 w-3" />
                                Image
                              </span>
                            )}
                          </div>

                          {hasNote && (
                            <div className="mt-3 pt-3 border-t border-dashed">
                              <p className="text-xs text-muted-foreground leading-relaxed">
                                {cameraForm.note}
                              </p>
                            </div>
                          )}

                          {hasImage && (
                            <div className="mt-3 pt-3 border-t border-dashed">
                              <img
                                src={cameraForm.image_url}
                                alt="Entity attachment"
                                className="max-h-48 rounded-md border"
                              />
                            </div>
                          )}
                        </div>
                      );
                    })}
                  </div>
                </div>
              ),
            )}
          </div>

          {entitiesWithData.length === 0 && (
            <div className="p-12 text-center">
              <FileText className="h-12 w-12 text-muted-foreground mx-auto mb-4 opacity-50" />
              <h3 className="text-lg font-semibold mb-2">No Entities Found</h3>
              <p className="text-sm text-muted-foreground">
                This form doesn't have any entity data yet.
              </p>
            </div>
          )}
        </div>

        <div className="rounded-lg border bg-muted/30 p-4">
          <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 text-xs text-muted-foreground">
            <div className="flex items-center gap-2">
              <span className="font-medium">Created:</span>
              <span>{new Date(audit.created_at).toLocaleString()}</span>
            </div>
            <div className="flex items-center gap-2">
              <span className="font-medium">Last Updated:</span>
              <span>{new Date(audit.updated_at).toLocaleString()}</span>
            </div>
          </div>
        </div>
      </div>
    </AuthenticatedLayout>
  );
}
