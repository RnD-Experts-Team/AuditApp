import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/app-layout';
import { Audit, PaginatedData, Store } from '@/types/models';
import { PageProps } from '@/types';

interface IndexProps extends Record<string, unknown> {
    audits: PaginatedData<Audit>;
    stores: Store[];
    groups: number[];
    filters: {
        date_range_type?: 'daily' | 'weekly';
        date_from?: string;
        date_to?: string;
        store_id?: number;
        group?: number;
    };
}

export default function Index({ auth, audits, stores, groups, filters }: PageProps<IndexProps>) {
    const [activeTab, setActiveTab] = useState<'daily' | 'weekly'>(
        filters.date_range_type || 'daily'
    );
    const [dateFrom, setDateFrom] = useState(filters.date_from || '');
    const [dateTo, setDateTo] = useState(filters.date_to || '');
    const [storeId, setStoreId] = useState<number | string>(filters.store_id || '');
    const [group, setGroup] = useState<number | string>(filters.group || '');

    const handleTabChange = (tab: 'daily' | 'weekly') => {
        setActiveTab(tab);
        applyFilters({ date_range_type: tab });
    };

    const applyFilters = (additionalFilters = {}) => {
        router.get(
            '/camera-forms',
            {
                date_range_type: activeTab,
                date_from: dateFrom,
                date_to: dateTo,
                store_id: storeId,
                group: group,
                ...additionalFilters,
            },
            {
                preserveState: true,
                preserveScroll: true,
            }
        );
    };

    const resetFilters = () => {
        setDateFrom('');
        setDateTo('');
        setStoreId('');
        setGroup('');
        router.get('/camera-forms', { date_range_type: activeTab });
    };

    const handleDelete = (auditId: number) => {
        if (confirm('Are you sure you want to delete this camera form?')) {
            router.delete(`/camera-forms/${auditId}`, {
                preserveScroll: true,
            });
        }
    };

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Camera Forms" />

            <div className="flex flex-1 flex-col gap-4 p-4 pt-0">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Camera Forms</h1>
                        <p className="text-sm text-muted-foreground mt-1">
                            Manage and track camera inspection forms
                        </p>
                    </div>
                    <Link
                        href="/camera-forms/create"
                        className="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors"
                    >
                        Create New Form
                    </Link>
                </div>

                <div className="rounded-lg border bg-card">
                    {/* Tabs */}
                    <div className="border-b bg-muted/50">
                        <nav className="flex">
                            <button
                                onClick={() => handleTabChange('daily')}
                                className={`px-6 py-3 text-sm font-medium transition-colors border-b-2 ${
                                    activeTab === 'daily'
                                        ? 'border-primary text-primary'
                                        : 'border-transparent text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                Daily
                            </button>
                            <button
                                onClick={() => handleTabChange('weekly')}
                                className={`px-6 py-3 text-sm font-medium transition-colors border-b-2 ${
                                    activeTab === 'weekly'
                                        ? 'border-primary text-primary'
                                        : 'border-transparent text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                Weekly
                            </button>
                        </nav>
                    </div>

                    {/* Filters */}
                    <div className="p-6 border-b bg-muted/20">
                        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div className="space-y-2">
                                <label className="text-sm font-medium">Date From</label>
                                <input
                                    type="date"
                                    value={dateFrom}
                                    onChange={(e) => setDateFrom(e.target.value)}
                                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                />
                            </div>
                            <div className="space-y-2">
                                <label className="text-sm font-medium">Date To</label>
                                <input
                                    type="date"
                                    value={dateTo}
                                    onChange={(e) => setDateTo(e.target.value)}
                                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                />
                            </div>
                            <div className="space-y-2">
                                <label className="text-sm font-medium">Store</label>
                                <select
                                    value={storeId}
                                    onChange={(e) => setStoreId(e.target.value)}
                                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
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
                                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
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

                        <div className="flex gap-2 mt-4">
                            <button
                                onClick={() => applyFilters()}
                                className="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90"
                            >
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

                    {/* Table */}
                    <div className="relative w-full overflow-auto">
                        <table className="w-full caption-bottom text-sm">
                            <thead className="border-b">
                                <tr className="border-b transition-colors hover:bg-muted/50">
                                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                                        Date
                                    </th>
                                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                                        Store
                                    </th>
                                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                                        User
                                    </th>
                                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                                        Entities
                                    </th>
                                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {audits.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={5} className="h-24 text-center text-muted-foreground">
                                            No camera forms found.
                                        </td>
                                    </tr>
                                ) : (
                                    audits.data.map((audit) => (
                                        <tr
                                            key={audit.id}
                                            className="border-b transition-colors hover:bg-muted/50"
                                        >
                                            <td className="p-4 align-middle">{audit.date}</td>
                                            <td className="p-4 align-middle">{audit.store?.store}</td>
                                            <td className="p-4 align-middle">{audit.user?.name}</td>
                                            <td className="p-4 align-middle">
                                                {audit.camera_forms?.length || 0}
                                            </td>
                                            <td className="p-4 align-middle">
                                                <div className="flex gap-2">
                                                    <Link
                                                        href={`/camera-forms/${audit.id}/edit`}
                                                        className="text-sm font-medium text-primary hover:underline"
                                                    >
                                                        Edit
                                                    </Link>
                                                    <button
                                                        onClick={() => handleDelete(audit.id)}
                                                        className="text-sm font-medium text-destructive hover:underline"
                                                    >
                                                        Delete
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {audits.links && audits.links.length > 3 && (
                        <div className="flex items-center justify-center gap-2 p-4 border-t">
                            {audits.links.map((link, index) => (
                                <button
                                    key={index}
                                    onClick={() => link.url && router.visit(link.url)}
                                    disabled={!link.url}
                                    className={`inline-flex items-center justify-center rounded-md px-3 py-1 text-sm font-medium transition-colors ${
                                        link.active
                                            ? 'bg-primary text-primary-foreground'
                                            : 'hover:bg-accent hover:text-accent-foreground'
                                    } ${!link.url ? 'opacity-50 cursor-not-allowed' : ''}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
