import { Head } from "@inertiajs/react";
import AuthenticatedLayout from "@/layouts/app-layout";
import { Store } from "@/types/models";
import { PageProps } from "@/types";
import { Store as StoreIcon } from "lucide-react";

interface StoresProps extends Record<string, unknown> {
  stores: Store[];
  groups: number[];
}

export default function Index({
  auth,
  stores,
  groups,
}: PageProps<StoresProps>) {
  // Group stores by group number
  const groupedStores = stores.reduce(
    (acc, store) => {
      const groupKey = store.group?.toString() || "No Group";
      if (!acc[groupKey]) acc[groupKey] = [];
      acc[groupKey].push(store);
      return acc;
    },
    {} as Record<string, Store[]>,
  );

  return (
    <AuthenticatedLayout user={auth.user}>
      <Head title="Stores Management" />

      <div className="flex flex-1 flex-col gap-6 p-4 pt-0">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold tracking-tight">
              Stores Management
            </h1>
            <p className="text-sm text-muted-foreground mt-1">
              Stores are synchronized automatically. This page is read-only.
            </p>
          </div>
        </div>

        {/* Stats Cards */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div className="rounded-lg border bg-card p-6">
            <div className="flex items-center gap-3">
              <div className="rounded-full bg-primary/10 p-3">
                <StoreIcon className="h-5 w-5 text-primary" />
              </div>
              <div>
                <p className="text-xs font-medium text-muted-foreground">
                  Total Stores
                </p>
                <p className="text-2xl font-bold">{stores.length}</p>
              </div>
            </div>
          </div>

          <div className="rounded-lg border bg-card p-6">
            <div className="flex items-center gap-3">
              <div className="rounded-full bg-accent/50 p-3">
                <StoreIcon className="h-5 w-5 text-accent-foreground" />
              </div>
              <div>
                <p className="text-xs font-medium text-muted-foreground">
                  Groups
                </p>
                <p className="text-2xl font-bold">{groups.length}</p>
              </div>
            </div>
          </div>

          <div className="rounded-lg border bg-card p-6">
            <div className="flex items-center gap-3">
              <div className="rounded-full bg-muted p-3">
                <StoreIcon className="h-5 w-5 text-muted-foreground" />
              </div>
              <div>
                <p className="text-xs font-medium text-muted-foreground">
                  Without Group
                </p>
                <p className="text-2xl font-bold">
                  {stores.filter((s) => !s.group).length}
                </p>
              </div>
            </div>
          </div>
        </div>

        {/* Stores Table */}
        <div className="rounded-lg border bg-card">
          <div className="bg-muted/50 px-6 py-4 border-b">
            <h3 className="text-lg font-semibold">All Stores (Read-only)</h3>
          </div>

          <div className="overflow-auto">
            <table className="w-full caption-bottom text-sm">
              <thead className="border-b">
                <tr className="border-b transition-colors hover:bg-muted/50">
                  <th className="h-12 px-6 text-left align-middle font-medium text-muted-foreground">
                    Store Name
                  </th>
                  <th className="h-12 px-6 text-left align-middle font-medium text-muted-foreground">
                    Group
                  </th>
                </tr>
              </thead>

              <tbody>
                {stores.length === 0 ? (
                  <tr>
                    <td
                      colSpan={2}
                      className="h-24 text-center text-muted-foreground"
                    >
                      No stores found.
                    </td>
                  </tr>
                ) : (
                  stores.map((store) => (
                    <tr
                      key={store.id}
                      className="border-b transition-colors hover:bg-muted/50"
                    >
                      <td className="p-4 font-medium">{store.store}</td>
                      <td className="p-4">
                        {store.group ? (
                          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                            Group {store.group}
                          </span>
                        ) : (
                          <span className="text-muted-foreground text-xs">
                            No group
                          </span>
                        )}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {/* Optional: grouped debug section */}
          <div className="border-t p-4 text-xs text-muted-foreground">
            <div className="font-medium mb-2">
              Grouped count (for quick sanity check)
            </div>
            <div className="flex flex-wrap gap-2">
              {Object.keys(groupedStores).map((g) => (
                <span
                  key={g}
                  className="inline-flex items-center rounded-md border px-2 py-1"
                >
                  {g}: {groupedStores[g].length}
                </span>
              ))}
            </div>
          </div>
        </div>
      </div>
    </AuthenticatedLayout>
  );
}
