import { Head, router } from '@inertiajs/react';
import { FormEventHandler, useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/app-layout';
import { Entity, Rating, Store } from '@/types/models';
import { PageProps } from '@/types';

interface CreateProps extends Record<string, unknown> {
    entities: Entity[];
    ratings: Rating[];
    stores: Store[];
}

interface EntityFormData {
    entity_id: number;
    rating_id: number | null;
    note: string;
    [key: string]: any;
}

export default function Create({
    auth,
    entities = [],
    ratings = [],
    stores = []
}: PageProps<CreateProps>) {
    const [dateRangeType, setDateRangeType] = useState<'daily' | 'weekly'>('daily');
    const [reportType, setReportType] = useState<'main' | 'secondary' | ''>('');
    const [entityData, setEntityData] = useState<Record<number, EntityFormData>>({});
    const [storeId, setStoreId] = useState('');
    const [date, setDate] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<any>({});

    const filteredEntities = entities.filter((entity) => {
        const matchesDateRange = entity.date_range_type === dateRangeType;
        const matchesReportType = !reportType || entity.report_type === reportType;
        return matchesDateRange && matchesReportType;
    });

    useEffect(() => {
        const initialData: Record<number, EntityFormData> = {};
        filteredEntities.forEach((entity) => {
            initialData[entity.id] = {
                entity_id: entity.id,
                rating_id: null,
                note: '',
            };
        });
        setEntityData(initialData);
    }, [dateRangeType, reportType]);

    const handleSubmit: FormEventHandler = (e) => {
        e.preventDefault();
        setProcessing(true);

        const entitiesArray = Object.values(entityData).filter(
            (entity) => entity.rating_id || entity.note
        );

        router.post('/camera-forms', {
            store_id: storeId,
            date: date,
            entities: entitiesArray,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setProcessing(false);
            },
            onError: (pageErrors) => {
                setErrors(pageErrors);
                setProcessing(false);
            },
        });
    };

    const updateEntityData = (entityId: number, field: 'rating_id' | 'note', value: any) => {
        setEntityData((prev) => ({
            ...prev,
            [entityId]: {
                ...prev[entityId],
                [field]: value,
            },
        }));
    };

    const groupedEntities = filteredEntities.reduce((acc, entity) => {
        const categoryLabel = entity.category?.label || 'Uncategorized';
        if (!acc[categoryLabel]) {
            acc[categoryLabel] = [];
        }
        acc[categoryLabel].push(entity);
        return acc;
    }, {} as Record<string, Entity[]>);

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Create Camera Form" />

            <div className="flex flex-1 flex-col gap-4 p-4 pt-0">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Create Camera Form</h1>
                        <p className="text-sm text-muted-foreground mt-1">
                            Fill out the inspection form for camera monitoring
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
                    {/* Basic Info Card */}
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

                    {/* Filters Card */}
                    <div className="rounded-lg border bg-accent/50 p-6">
                        <h3 className="text-lg font-semibold mb-4">Filter Entities</h3>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <label className="text-sm font-medium">Date Range Type</label>
                                <select
                                    value={dateRangeType}
                                    onChange={(e) => setDateRangeType(e.target.value as 'daily' | 'weekly')}
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
                                    onChange={(e) => setReportType(e.target.value as 'main' | 'secondary' | '')}
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

                    {/* Entities */}
                    <div className="space-y-4">
                        {Object.entries(groupedEntities).map(([categoryLabel, categoryEntities]) => (
                            <div key={categoryLabel} className="rounded-lg border bg-card">
                                <div className="border-b bg-muted/50 px-6 py-4">
                                    <h3 className="text-lg font-semibold">{categoryLabel}</h3>
                                </div>
                                <div className="p-6 space-y-4">
                                    {categoryEntities.map((entity) => (
                                        <div
                                            key={entity.id}
                                            className="grid grid-cols-1 md:grid-cols-3 gap-4 p-4 rounded-md bg-muted/20"
                                        >
                                            <div className="flex items-center">
                                                <label className="text-sm font-medium">
                                                    {entity.entity_label}
                                                </label>
                                            </div>

                                            <div className="space-y-1">
                                                <select
                                                    value={entityData[entity.id]?.rating_id || ''}
                                                    onChange={(e) =>
                                                        updateEntityData(
                                                            entity.id,
                                                            'rating_id',
                                                            e.target.value ? Number(e.target.value) : null
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
                                                    value={entityData[entity.id]?.note || ''}
                                                    onChange={(e) =>
                                                        updateEntityData(entity.id, 'note', e.target.value)
                                                    }
                                                    className="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                />
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>

                    {errors.entities && (
                        <p className="text-sm text-destructive">{errors.entities}</p>
                    )}

                    {/* Actions */}
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
                            {processing ? 'Creating...' : 'Create Form'}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
