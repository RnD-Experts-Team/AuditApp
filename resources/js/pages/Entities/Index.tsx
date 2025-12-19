import { Head, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/app-layout';
import { Entity, Category } from '@/types/models';
import { PageProps } from '@/types';
import { Plus, Edit, Trash2, Save, X, FolderOpen, FileText } from 'lucide-react';

interface EntitiesProps extends Record<string, unknown> {
    entities: Entity[];
    categories: (Category & { entities_count?: number })[];
}

export default function Index({ auth, entities, categories }: PageProps<EntitiesProps>) {
    // Categories state
    const [isAddingCategory, setIsAddingCategory] = useState(false);
    const [editingCategoryId, setEditingCategoryId] = useState<number | null>(null);
    const [newCategory, setNewCategory] = useState({ label: '', sort_order: '' });
    const [editCategory, setEditCategory] = useState({ label: '', sort_order: '' });

    // Entities state
    const [isAddingEntity, setIsAddingEntity] = useState(false);
    const [editingEntityId, setEditingEntityId] = useState<number | null>(null);
    const [newEntity, setNewEntity] = useState({
        entity_label: '',
        category_id: '',
        date_range_type: 'daily',
        report_type: '',
        sort_order: '',
    });
    const [editEntity, setEditEntity] = useState({
        entity_label: '',
        category_id: '',
        date_range_type: 'daily',
        report_type: '',
        sort_order: '',
    });

    const [errors, setErrors] = useState<Record<string, string>>({});

    // Category handlers
    const handleAddCategory: FormEventHandler = (e) => {
        e.preventDefault();
        router.post('/categories', {
            label: newCategory.label,
            sort_order: newCategory.sort_order || null,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setNewCategory({ label: '', sort_order: '' });
                setIsAddingCategory(false);
                setErrors({});
            },
            onError: (errors) => setErrors(errors),
        });
    };

    const handleEditCategory = (category: Category) => {
        setEditingCategoryId(category.id);
        setEditCategory({
            label: category.label,
            sort_order: category.sort_order?.toString() || '',
        });
        setErrors({});
    };

    const handleUpdateCategory: FormEventHandler = (e) => {
        e.preventDefault();
        if (!editingCategoryId) return;

        router.put(`/categories/${editingCategoryId}`, {
            label: editCategory.label,
            sort_order: editCategory.sort_order || null,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingCategoryId(null);
                setEditCategory({ label: '', sort_order: '' });
                setErrors({});
            },
            onError: (errors) => setErrors(errors),
        });
    };

    const handleDeleteCategory = (id: number, label: string) => {
        if (confirm(`Delete category "${label}"?`)) {
            router.delete(`/categories/${id}`, {
                preserveScroll: true,
                onError: (errors) => alert(errors.error || 'Failed to delete'),
            });
        }
    };

    // Entity handlers
    const handleAddEntity: FormEventHandler = (e) => {
        e.preventDefault();
        router.post('/entities', {
            ...newEntity,
            category_id: newEntity.category_id || null,
            report_type: newEntity.report_type || null,
            sort_order: newEntity.sort_order || null,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setNewEntity({
                    entity_label: '',
                    category_id: '',
                    date_range_type: 'daily',
                    report_type: '',
                    sort_order: '',
                });
                setIsAddingEntity(false);
                setErrors({});
            },
            onError: (errors) => setErrors(errors),
        });
    };

    const handleEditEntity = (entity: Entity) => {
        setEditingEntityId(entity.id);
        setEditEntity({
            entity_label: entity.entity_label,
            category_id: entity.category_id?.toString() || '',
            date_range_type: entity.date_range_type,
            report_type: entity.report_type || '',
            sort_order: entity.sort_order?.toString() || '',
        });
        setErrors({});
    };

    const handleUpdateEntity: FormEventHandler = (e) => {
        e.preventDefault();
        if (!editingEntityId) return;

        router.put(`/entities/${editingEntityId}`, {
            ...editEntity,
            category_id: editEntity.category_id || null,
            report_type: editEntity.report_type || null,
            sort_order: editEntity.sort_order || null,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingEntityId(null);
                setEditEntity({
                    entity_label: '',
                    category_id: '',
                    date_range_type: 'daily',
                    report_type: '',
                    sort_order: '',
                });
                setErrors({});
            },
            onError: (errors) => setErrors(errors),
        });
    };

    const handleDeleteEntity = (id: number, label: string) => {
        if (confirm(`Delete entity "${label}"?`)) {
            router.delete(`/entities/${id}`, {
                preserveScroll: true,
                onError: (errors) => alert(errors.error || 'Failed to delete'),
            });
        }
    };

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Entities & Categories" />

            <div className="flex flex-1 flex-col gap-6 p-4 pt-0">
                {/* Header */}
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">Entities & Categories</h1>
                    <p className="text-sm text-muted-foreground mt-1">
                        Manage inspection entities and their categories
                    </p>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div className="rounded-lg border bg-card p-6">
                        <div className="flex items-center gap-3">
                            <div className="rounded-full bg-primary/10 p-3">
                                <FolderOpen className="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <p className="text-xs font-medium text-muted-foreground">Total Categories</p>
                                <p className="text-2xl font-bold">{categories.length}</p>
                            </div>
                        </div>
                    </div>
                    <div className="rounded-lg border bg-card p-6">
                        <div className="flex items-center gap-3">
                            <div className="rounded-full bg-accent/50 p-3">
                                <FileText className="h-5 w-5 text-accent-foreground" />
                            </div>
                            <div>
                                <p className="text-xs font-medium text-muted-foreground">Total Entities</p>
                                <p className="text-2xl font-bold">{entities.length}</p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Two Tables Side by Side */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Categories Table */}
                    <div className="rounded-lg border bg-card">
                        <div className="bg-muted/50 px-6 py-4 border-b flex items-center justify-between">
                            <h3 className="text-lg font-semibold">Categories</h3>
                            <button
                                onClick={() => setIsAddingCategory(true)}
                                disabled={isAddingCategory}
                                className="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-md bg-primary text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                            >
                                <Plus className="h-3 w-3" />
                                Add
                            </button>
                        </div>

                        <div className="overflow-auto max-h-[600px]">
                            <table className="w-full text-sm">
                                <thead className="border-b sticky top-0 bg-background">
                                    <tr>
                                        <th className="h-10 px-4 text-left font-medium text-muted-foreground">
                                            Category Name
                                        </th>
                                        <th className="h-10 px-4 text-center font-medium text-muted-foreground">
                                            Entities
                                        </th>
                                        <th className="h-10 px-4 text-right font-medium text-muted-foreground">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {isAddingCategory && (
                                        <tr className="border-b bg-accent/5">
                                            <td className="p-3" colSpan={2}>
                                                <div className="space-y-2">
                                                    <input
                                                        type="text"
                                                        placeholder="Category name"
                                                        value={newCategory.label}
                                                        onChange={(e) => setNewCategory({ ...newCategory, label: e.target.value })}
                                                        className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                        autoFocus
                                                    />
                                                    <input
                                                        type="number"
                                                        placeholder="Sort order (optional)"
                                                        value={newCategory.sort_order}
                                                        onChange={(e) => setNewCategory({ ...newCategory, sort_order: e.target.value })}
                                                        className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                        min="0"
                                                    />
                                                    {errors.label && (
                                                        <p className="text-xs text-destructive mt-1">{errors.label}</p>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="p-3 text-right">
                                                <div className="flex justify-end gap-1">
                                                    <button
                                                        onClick={handleAddCategory}
                                                        className="inline-flex items-center gap-1 px-2 py-1 text-xs rounded-md bg-primary text-primary-foreground hover:bg-primary/90"
                                                    >
                                                        <Save className="h-3 w-3" />
                                                    </button>
                                                    <button
                                                        onClick={() => {
                                                            setIsAddingCategory(false);
                                                            setNewCategory({ label: '', sort_order: '' });
                                                            setErrors({});
                                                        }}
                                                        className="inline-flex items-center gap-1 px-2 py-1 text-xs rounded-md border hover:bg-accent"
                                                    >
                                                        <X className="h-3 w-3" />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    )}

                                    {categories.map((category) => (
                                        <tr key={category.id} className="border-b hover:bg-muted/50">
                                            {editingCategoryId === category.id ? (
                                                <>
                                                    <td className="p-3" colSpan={2}>
                                                        <div className="space-y-2">
                                                            <input
                                                                type="text"
                                                                value={editCategory.label}
                                                                onChange={(e) => setEditCategory({ ...editCategory, label: e.target.value })}
                                                                className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                            />
                                                            <input
                                                                type="number"
                                                                placeholder="Sort order (optional)"
                                                                value={editCategory.sort_order}
                                                                onChange={(e) => setEditCategory({ ...editCategory, sort_order: e.target.value })}
                                                                className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                                min="0"
                                                            />
                                                        </div>
                                                    </td>
                                                    <td className="p-3 text-right">
                                                        <div className="flex justify-end gap-1">
                                                            <button
                                                                onClick={handleUpdateCategory}
                                                                className="inline-flex items-center gap-1 px-2 py-1 text-xs rounded-md bg-primary text-primary-foreground"
                                                            >
                                                                <Save className="h-3 w-3" />
                                                            </button>
                                                            <button
                                                                onClick={() => {
                                                                    setEditingCategoryId(null);
                                                                    setEditCategory({ label: '', sort_order: '' });
                                                                    setErrors({});
                                                                }}
                                                                className="inline-flex items-center gap-1 px-2 py-1 text-xs rounded-md border"
                                                            >
                                                                <X className="h-3 w-3" />
                                                            </button>
                                                        </div>
                                                    </td>
                                                </>
                                            ) : (
                                                <>
                                                    <td className="p-3 font-medium">{category.label}</td>
                                                    <td className="p-3 text-center">
                                                        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-muted">
                                                            {category.entities_count || 0}
                                                        </span>
                                                    </td>
                                                    <td className="p-3 text-right">
                                                        <div className="flex justify-end gap-1">
                                                            <button
                                                                onClick={() => handleEditCategory(category)}
                                                                className="inline-flex items-center px-2 py-1 text-xs rounded-md text-primary hover:bg-primary/10"
                                                            >
                                                                <Edit className="h-3 w-3" />
                                                            </button>
                                                            <button
                                                                onClick={() => handleDeleteCategory(category.id, category.label)}
                                                                className="inline-flex items-center px-2 py-1 text-xs rounded-md text-destructive hover:bg-destructive/10"
                                                            >
                                                                <Trash2 className="h-3 w-3" />
                                                            </button>
                                                        </div>
                                                    </td>
                                                </>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* Entities Table */}
                    <div className="rounded-lg border bg-card">
                        <div className="bg-muted/50 px-6 py-4 border-b flex items-center justify-between">
                            <h3 className="text-lg font-semibold">Entities</h3>
                            <button
                                onClick={() => setIsAddingEntity(true)}
                                disabled={isAddingEntity}
                                className="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-md bg-primary text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                            >
                                <Plus className="h-3 w-3" />
                                Add
                            </button>
                        </div>

                        <div className="overflow-auto max-h-[600px]">
                            <table className="w-full text-sm">
                                <thead className="border-b sticky top-0 bg-background">
                                    <tr>
                                        <th className="h-10 px-4 text-left font-medium text-muted-foreground">
                                            Entity Name
                                        </th>
                                        <th className="h-10 px-4 text-left font-medium text-muted-foreground">
                                            Category
                                        </th>
                                        <th className="h-10 px-4 text-right font-medium text-muted-foreground">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {isAddingEntity && (
                                        <tr className="border-b bg-accent/5">
                                            <td className="p-3" colSpan={3}>
                                                <div className="space-y-2">
                                                    <input
                                                        type="text"
                                                        placeholder="Entity name"
                                                        value={newEntity.entity_label}
                                                        onChange={(e) => setNewEntity({ ...newEntity, entity_label: e.target.value })}
                                                        className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                        autoFocus
                                                    />
                                                    <select
                                                        value={newEntity.category_id}
                                                        onChange={(e) => setNewEntity({ ...newEntity, category_id: e.target.value })}
                                                        className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                    >
                                                        <option value="">Select category</option>
                                                        {categories.map((cat) => (
                                                            <option key={cat.id} value={cat.id}>{cat.label}</option>
                                                        ))}
                                                    </select>
                                                    <div className="grid grid-cols-2 gap-2">
                                                        <select
                                                            value={newEntity.date_range_type}
                                                            onChange={(e) => setNewEntity({ ...newEntity, date_range_type: e.target.value as 'daily' | 'weekly' })}
                                                            className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                        >
                                                            <option value="daily">Daily</option>
                                                            <option value="weekly">Weekly</option>
                                                        </select>
                                                        <select
                                                            value={newEntity.report_type}
                                                            onChange={(e) => setNewEntity({ ...newEntity, report_type: e.target.value })}
                                                            className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                        >
                                                            <option value="">Report type</option>
                                                            <option value="main">Main</option>
                                                            <option value="secondary">Secondary</option>
                                                        </select>
                                                    </div>
                                                    <input
                                                        type="number"
                                                        placeholder="Sort order (optional)"
                                                        value={newEntity.sort_order}
                                                        onChange={(e) => setNewEntity({ ...newEntity, sort_order: e.target.value })}
                                                        className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                        min="0"
                                                    />
                                                    <div className="flex justify-end gap-1">
                                                        <button
                                                            onClick={handleAddEntity}
                                                            className="inline-flex items-center gap-1 px-2 py-1 text-xs rounded-md bg-primary text-primary-foreground"
                                                        >
                                                            <Save className="h-3 w-3" /> Save
                                                        </button>
                                                        <button
                                                            onClick={() => {
                                                                setIsAddingEntity(false);
                                                                setNewEntity({
                                                                    entity_label: '',
                                                                    category_id: '',
                                                                    date_range_type: 'daily',
                                                                    report_type: '',
                                                                    sort_order: '',
                                                                });
                                                                setErrors({});
                                                            }}
                                                            className="inline-flex items-center gap-1 px-2 py-1 text-xs rounded-md border"
                                                        >
                                                            <X className="h-3 w-3" /> Cancel
                                                        </button>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    )}

                                    {entities.map((entity) => (
                                        <tr key={entity.id} className="border-b hover:bg-muted/50">
                                            {editingEntityId === entity.id ? (
                                                <td className="p-3" colSpan={3}>
                                                    <div className="space-y-2">
                                                        <input
                                                            type="text"
                                                            value={editEntity.entity_label}
                                                            onChange={(e) => setEditEntity({ ...editEntity, entity_label: e.target.value })}
                                                            className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                        />
                                                        <select
                                                            value={editEntity.category_id}
                                                            onChange={(e) => setEditEntity({ ...editEntity, category_id: e.target.value })}
                                                            className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                        >
                                                            <option value="">Select category</option>
                                                            {categories.map((cat) => (
                                                                <option key={cat.id} value={cat.id}>{cat.label}</option>
                                                            ))}
                                                        </select>
                                                        <div className="grid grid-cols-2 gap-2">
                                                            <select
                                                                value={editEntity.date_range_type}
                                                                onChange={(e) => setEditEntity({ ...editEntity, date_range_type: e.target.value as 'daily' | 'weekly' })}
                                                                className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                            >
                                                                <option value="daily">Daily</option>
                                                                <option value="weekly">Weekly</option>
                                                            </select>
                                                            <select
                                                                value={editEntity.report_type}
                                                                onChange={(e) => setEditEntity({ ...editEntity, report_type: e.target.value })}
                                                                className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                            >
                                                                <option value="">Report type</option>
                                                                <option value="main">Main</option>
                                                                <option value="secondary">Secondary</option>
                                                            </select>
                                                        </div>
                                                        <input
                                                            type="number"
                                                            placeholder="Sort order (optional)"
                                                            value={editEntity.sort_order}
                                                            onChange={(e) => setEditEntity({ ...editEntity, sort_order: e.target.value })}
                                                            className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                            min="0"
                                                        />
                                                        <div className="flex justify-end gap-1">
                                                            <button
                                                                onClick={handleUpdateEntity}
                                                                className="inline-flex items-center gap-1 px-2 py-1 text-xs rounded-md bg-primary text-primary-foreground"
                                                            >
                                                                <Save className="h-3 w-3" />
                                                            </button>
                                                            <button
                                                                onClick={() => {
                                                                    setEditingEntityId(null);
                                                                    setEditEntity({
                                                                        entity_label: '',
                                                                        category_id: '',
                                                                        date_range_type: 'daily',
                                                                        report_type: '',
                                                                        sort_order: '',
                                                                    });
                                                                    setErrors({});
                                                                }}
                                                                className="inline-flex items-center gap-1 px-2 py-1 text-xs rounded-md border"
                                                            >
                                                                <X className="h-3 w-3" />
                                                            </button>
                                                        </div>
                                                    </div>
                                                </td>
                                            ) : (
                                                <>
                                                    <td className="p-3 font-medium">
                                                        {entity.entity_label}
                                                        <div className="flex gap-1 mt-1">
                                                            <span className="text-xs px-1.5 py-0.5 rounded bg-muted">
                                                                {entity.date_range_type}
                                                            </span>
                                                            {entity.report_type && (
                                                                <span className="text-xs px-1.5 py-0.5 rounded bg-accent/30">
                                                                    {entity.report_type}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="p-3">
                                                        {entity.category ? (
                                                            <span className="text-xs px-2 py-0.5 rounded-full bg-primary/10 text-primary">
                                                                {entity.category.label}
                                                            </span>
                                                        ) : (
                                                            <span className="text-xs text-muted-foreground">No category</span>
                                                        )}
                                                    </td>
                                                    <td className="p-3 text-right">
                                                        <div className="flex justify-end gap-1">
                                                            <button
                                                                onClick={() => handleEditEntity(entity)}
                                                                className="inline-flex items-center px-2 py-1 text-xs rounded-md text-primary hover:bg-primary/10"
                                                            >
                                                                <Edit className="h-3 w-3" />
                                                            </button>
                                                            <button
                                                                onClick={() => handleDeleteEntity(entity.id, entity.entity_label)}
                                                                className="inline-flex items-center px-2 py-1 text-xs rounded-md text-destructive hover:bg-destructive/10"
                                                            >
                                                                <Trash2 className="h-3 w-3" />
                                                            </button>
                                                        </div>
                                                    </td>
                                                </>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
