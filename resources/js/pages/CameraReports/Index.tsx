import { Head, router } from "@inertiajs/react";
import { useMemo, useState } from "react";
import AuthenticatedLayout from "@/layouts/app-layout";
import { Store, Entity, Category } from "@/types/models";
import { PageProps } from "@/types";
import { Filter, Download, RefreshCw } from "lucide-react";

interface RatingOption {
  id: number;
  label: string | null;
}

interface EntityData {
  entity_id: number;
  entity_label: string;
  rating_counts: {
    rating_label: string | null;
    count: number;
  }[];
  notes: string[]; // ✅ added
  category?: Category | null;
}

interface StoreSummary {
  store_id: number;
  store_name: string;
  store_group: number | null;
  entities: Record<number, EntityData>;
}

interface ReportData {
  summary: StoreSummary[];
  entities: Entity[];
  total_stores: number;
  scoreData: Record<
    string,
    {
      score_without_auto_fail: number | null;
      score_with_auto_fail: number | null;
    }
  >;
}

interface CameraReportsProps extends Record<string, unknown> {
  reportData: ReportData;
  stores: Store[];
  groups: number[];
  ratings: RatingOption[]; // ✅ added
  filters: {
    store_id?: number;
    group?: number;
    report_type?: "main" | "secondary" | "";
    date_from?: string;
    date_to?: string;
    rating_id?: number; // ✅ added
  };
}

export default function Index({
  auth,
  reportData,
  stores,
  groups,
  ratings,
  filters,
}: PageProps<CameraReportsProps>) {
  const [reportType, setReportType] = useState<"main" | "secondary" | "">(
    filters.report_type || "",
  );
  const [storeId, setStoreId] = useState<number | string>(
    filters.store_id || "",
  );
  const [group, setGroup] = useState<number | string>(filters.group || "");
  const [dateFrom, setDateFrom] = useState<string>(filters.date_from || "");
  const [dateTo, setDateTo] = useState<string>(filters.date_to || "");
  const [ratingId, setRatingId] = useState<number | string>(
    filters.rating_id || "",
  ); // ✅ added

  const applyFilters = () => {
    router.get(
      "/camera-reports",
      {
        report_type: reportType,
        store_id: storeId,
        group: group,
        date_from: dateFrom,
        date_to: dateTo,
        rating_id: ratingId, // ✅ added
      },
      {
        preserveState: true,
        preserveScroll: true,
      },
    );
  };

  const resetFilters = () => {
    setReportType("");
    setStoreId("");
    setGroup("");
    setDateFrom("");
    setDateTo("");
    setRatingId(""); // ✅ added
    router.get("/camera-reports");
  };

  // ✅ Backend export (respects filters)
  const exportToCSV = () => {
    const params = new URLSearchParams();

    if (reportType) params.set("report_type", reportType);
    if (storeId) params.set("store_id", String(storeId));
    if (group) params.set("group", String(group));
    if (dateFrom) params.set("date_from", dateFrom);
    if (dateTo) params.set("date_to", dateTo);
    if (ratingId) params.set("rating_id", String(ratingId));

    const url = `/camera-reports/export?${params.toString()}`;
    window.location.href = url;
  };

  const formatEntitySummary = (
    storeSummary: StoreSummary,
    entityId: number,
  ): string => {
    const entityData = storeSummary.entities[entityId];
    if (!entityData) return "";
    return entityData.rating_counts
      .filter((rc) => rc.count > 0)
      .map((rc) => `${rc.count} ${rc.rating_label || "No Rating"}`)
      .join(", ");
  };

  const { summary, entities, total_stores, scoreData } = reportData;

  // Group entities by category for header rows
  const categoryGroups = useMemo(() => {
    const categories: Record<
      string,
      { id: number | null; label: string; entities: Entity[] }
    > = {};
    entities.forEach((entity) => {
      const catId = entity.category?.id ?? 0;
      const catLabel = entity.category?.label ?? "Uncategorized";
      if (!categories[catLabel]) {
        categories[catLabel] = { id: catId, label: catLabel, entities: [] };
      }
      categories[catLabel].entities.push(entity);
    });
    return Object.values(categories);
  }, [entities]);

  return (
    <AuthenticatedLayout user={auth.user}>
      <Head title="Camera Reports" />
      <div className="flex flex-1 flex-col gap-6 p-4 pt-0">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold tracking-tight">
              Camera Reports
            </h1>
            <p className="text-sm text-muted-foreground mt-1">
              Summary of ratings per store and entity
            </p>
          </div>
          <button
            onClick={exportToCSV}
            className="inline-flex items-center gap-2 justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors"
          >
            <Download className="h-4 w-4" />
            Export
          </button>
        </div>

        <div className="rounded-lg border bg-card">
          <div className="bg-gradient-to-r from-primary/10 to-accent/10 px-6 py-4 border-b">
            <div className="flex items-center gap-2">
              <Filter className="h-5 w-5" />
              <h3 className="text-lg font-semibold">Filters</h3>
            </div>
          </div>

          <div className="p-6 space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
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

              <div className="space-y-2">
                <label className="text-sm font-medium">Store</label>
                <select
                  value={storeId}
                  onChange={(e) => setStoreId(e.target.value)}
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  <option value="">All Stores</option>
                  {stores.map((store) => (
                    <option key={store.id} value={store.id}>
                      {store.store}
                    </option>
                  ))}
                </select>
              </div>

              <div className="space-y-2">
                <label className="text-sm font-medium">Group</label>
                <select
                  value={group}
                  onChange={(e) => setGroup(e.target.value)}
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  <option value="">All Groups</option>
                  {groups.map((g) => (
                    <option key={g} value={g}>
                      Group {g}
                    </option>
                  ))}
                </select>
              </div>

              {/* ✅ Rating filter */}
              <div className="space-y-2">
                <label className="text-sm font-medium">Rating</label>
                <select
                  value={ratingId}
                  onChange={(e) => setRatingId(e.target.value)}
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  <option value="">All Ratings</option>
                  {ratings.map((r) => (
                    <option key={r.id} value={r.id}>
                      {r.label ?? `Rating #${r.id}`}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <label className="text-sm font-medium">Date From</label>
                <input
                  type="date"
                  value={dateFrom}
                  onChange={(e) => setDateFrom(e.target.value)}
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium">Date To</label>
                <input
                  type="date"
                  value={dateTo}
                  onChange={(e) => setDateTo(e.target.value)}
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
              </div>
            </div>

            <div className="flex gap-2 pt-2">
              <button
                onClick={applyFilters}
                className="inline-flex items-center gap-2 justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90"
              >
                <RefreshCw className="h-4 w-4" />
                Apply Filters
              </button>
              <button
                onClick={resetFilters}
                className="inline-flex items-center justify-center rounded-md border border-input bg-background px-4 py-2 text-sm font-medium hover:bg-accent hover:text-accent-foreground"
              >
                Reset
              </button>
            </div>
          </div>
        </div>

        <div className="rounded-lg border bg-card p-4">
          <p className="text-sm text-muted-foreground">
            Showing{" "}
            <span className="font-semibold text-foreground">
              {total_stores}
            </span>{" "}
            stores •{" "}
            <span className="font-semibold text-foreground">
              {entities.length}
            </span>{" "}
            entities
          </p>
        </div>

        <div className="rounded-lg border bg-card overflow-x-auto">
          <table className="w-full caption-bottom text-sm border-separate border-spacing-0">
            <thead className="border-b bg-muted/50">
              <tr>
                <th
                  rowSpan={2}
                  className="h-12 px-4 text-left align-middle font-medium text-muted-foreground sticky left-0 bg-muted/50 z-10"
                  style={{ minWidth: 120 }}
                >
                  Store
                </th>
                {categoryGroups.map((group) => (
                  <th
                    key={group.label}
                    className="text-center align-middle font-bold bg-muted/50 text-[15px]"
                    colSpan={group.entities.length}
                  >
                    {group.label}
                  </th>
                ))}
                <th rowSpan={2} className="text-center align-middle font-bold">
                  Score Without Auto Fail
                </th>
                <th rowSpan={2} className="text-center align-middle font-bold">
                  Total Score
                </th>
              </tr>

              <tr>
                {categoryGroups.map((group) =>
                  group.entities.map((entity) => (
                    <th
                      key={entity.id}
                      className="h-12 px-4 text-center align-middle font-medium text-muted-foreground"
                    >
                      <span>{entity.entity_label}</span>
                    </th>
                  )),
                )}
              </tr>
            </thead>

            <tbody>
              {summary.length === 0 ? (
                <tr>
                  <td
                    colSpan={entities.length + 3}
                    className="h-24 text-center text-muted-foreground"
                  >
                    No data found. Try adjusting your filters.
                  </td>
                </tr>
              ) : (
                summary.map((storeSummary) => (
                  <tr
                    key={storeSummary.store_id}
                    className="border-b transition-colors hover:bg-muted/50"
                  >
                    <td className="p-4 align-middle sticky left-0 bg-background z-10">
                      <div className="font-medium">
                        {storeSummary.store_name}
                      </div>
                      {storeSummary.store_group && (
                        <div className="text-xs text-muted-foreground mt-0.5">
                          Group {storeSummary.store_group}
                        </div>
                      )}
                    </td>

                    {categoryGroups.map((group) =>
                      group.entities.map((entity) => {
                        const summaryText = formatEntitySummary(
                          storeSummary,
                          entity.id,
                        );
                        return (
                          <td
                            key={entity.id}
                            className="p-4 align-middle text-center"
                          >
                            {summaryText ? (
                              <div className="text-xs whitespace-pre-line">
                                {summaryText}
                              </div>
                            ) : (
                              <span className="text-muted-foreground">-</span>
                            )}
                          </td>
                        );
                      }),
                    )}

                    <td className="p-4 align-middle text-center">
                      {scoreData[storeSummary.store_id]
                        ?.score_without_auto_fail !== null ? (
                        scoreData[storeSummary.store_id]
                          ?.score_without_auto_fail
                      ) : (
                        <span className="text-muted-foreground">-</span>
                      )}
                    </td>

                    <td className="p-4 align-middle text-center">
                      {typeof scoreData[storeSummary.store_id]
                        ?.score_with_auto_fail === "number" ? (
                        scoreData[storeSummary.store_id].score_with_auto_fail
                      ) : (
                        <span className="text-muted-foreground">-</span>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </AuthenticatedLayout>
  );
}
