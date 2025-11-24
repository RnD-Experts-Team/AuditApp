import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/app-layout';
import { Store, Entity, Category } from '@/types/models';
import { PageProps } from '@/types';
import { Filter, Download, RefreshCw } from 'lucide-react';


interface EntityData {
    entity_id: number;
    entity_label: string;
    rating_counts: {
        rating_label: string | null;
        count: number;
    }[];
    category?: Category;
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
    scoreData: Record<string, { score_without_auto_fail: number | null; score_with_auto_fail: number | null }>;
}


interface CameraReportsProps extends Record<string, unknown> {
    reportData: ReportData;
    stores: Store[];
    groups: number[];
    filters: {
        store_id?: number;
        group?: number;
        year?: number;
        week?: number;
        report_type?: 'main' | 'secondary' | '';
    };
}


export default function Index({
    auth,
    reportData,
    stores,
    groups,
    filters,
}: PageProps<CameraReportsProps>) {
    const [reportType, setReportType] = useState<'main' | 'secondary' | ''>(
        filters.report_type || ''
    );
    const [storeId, setStoreId] = useState<number | string>(filters.store_id || '');
    const [group, setGroup] = useState<number | string>(filters.group || '');
    const [year, setYear] = useState<number>(filters.year || new Date().getFullYear());
    const [week, setWeek] = useState<number>(filters.week || 1);


    const applyFilters = () => {
        router.get('/camera-reports', {
            report_type: reportType,
            store_id: storeId,
            group: group,
            year,
            week,
        }, {
            preserveState: true,
            preserveScroll: true,
        });
    };


    const resetFilters = () => {
        setReportType('');
        setStoreId('');
        setGroup('');
        setYear(new Date().getFullYear());
        setWeek(1);
        router.get('/camera-reports');
    };


    const handleExport = () => {
        alert('Export feature coming soon!');
    };


    const formatEntitySummary = (storeSummary: StoreSummary, entityId: number): string => {
        const entityData = storeSummary.entities[entityId];
        if (!entityData) {
            return '';
        }
        return entityData.rating_counts
            .filter((rc) => rc.count > 0)
            .map((rc) => `${rc.count} ${rc.rating_label || 'No Rating'}`)
            .join(', ');
    };


    const { summary, entities, total_stores, scoreData } = reportData;
    const categories: Record<string, { id: number | null; label: string; entities: Entity[] }> = {};


    entities.forEach((entity) => {
        const catId = entity.category?.id ?? 0;
        const catLabel = entity.category?.label ?? 'Uncategorized';
        if (!categories[catLabel]) {
            categories[catLabel] = {
                id: catId,
                label: catLabel,
                entities: [],
            };
        }
        categories[catLabel].entities.push(entity);
    });


    const categoryGroups = Object.values(categories);


    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Camera Reports" />
            <div className="flex flex-1 flex-col gap-6 p-4 pt-0">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Camera Reports</h1>
                        <p className="text-sm text-muted-foreground mt-1">
                            Summary of ratings per store and entity
                        </p>
                    </div>
                    <button
                        onClick={handleExport}
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
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div className="space-y-2">
                                <label className="text-sm font-medium">Report Type</label>
                                <select
                                    value={reportType}
                                    onChange={(e) => setReportType(e.target.value as 'main' | 'secondary' | '')}
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
                        </div>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <label className="text-sm font-medium">Year</label>
                                <input
                                    type="number"
                                    value={year}
                                    onChange={e => setYear(Number(e.target.value))}
                                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                />
                            </div>
                            <div className="space-y-2">
                                <label className="text-sm font-medium">Week</label>
                                <input
                                    type="number"
                                    min={1}
                                    max={53}
                                    value={week}
                                    onChange={e => setWeek(Number(e.target.value))}
                                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                />
                                <p className="text-xs text-muted-foreground">Weeks start on Tuesday</p>
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
                        Showing{' '}
                        <span className="font-semibold text-foreground">
                            {total_stores}
                        </span>{' '}
                        stores •{' '}
                        <span className="font-semibold text-foreground">
                            {entities.length}
                        </span>{' '}
                        entities
                    </p>
                </div>


                <div className="rounded-lg border bg-card overflow-x-auto">
                    <table className="w-full caption-bottom text-sm border-separate border-spacing-0">
                        <thead className="border-b bg-muted/50">
                            {/* Row 1: Category headers */}
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
                                <th rowSpan={2} className="text-center align-middle font-bold">Score Without Auto Fail</th>
                                <th rowSpan={2} className="text-center align-middle font-bold">Total Score</th>
                            </tr>
                            {/* Row 2: Entity labels */}
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
                                        {/* Entity values */}
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
                                        {/* Score columns */}
                                        <td className="p-4 align-middle text-center">
                                            {scoreData[storeSummary.store_id]?.score_without_auto_fail !== null ?
                                                (scoreData[storeSummary.store_id]?.score_without_auto_fail)
                                                : <span className="text-muted-foreground">-</span>
                                            }
                                        </td>
                                        <td className="p-4 align-middle text-center">
                                            {typeof scoreData[storeSummary.store_id]?.score_with_auto_fail === "number"
                                                ? scoreData[storeSummary.store_id].score_with_auto_fail
                                                : <span className="text-muted-foreground">-</span>
                                            }
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
