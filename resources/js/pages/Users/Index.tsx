import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/app-layout';
import { PageProps } from '@/types';
import { IndexUserPageProps } from '@/types/user';

export default function Index({
    auth,
    users,
    flash,
}: PageProps<IndexUserPageProps>) {
    const handleDelete = (userId: number) => {
        if (confirm('Are you sure you want to delete this user?')) {
            router.delete(`/users/${userId}`, {
                preserveScroll: true,
            });
        }
    };

    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    const getRoleBadgeColor = (role: string) => {
        return role === 'Admin'
            ? 'bg-red-100 text-red-800'
            : 'bg-blue-100 text-blue-800';
    };

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Users" />

            <div className="flex flex-1 flex-col gap-4 p-4 pt-0">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Users Management</h1>
                        <p className="text-sm text-muted-foreground mt-1">
                            Manage system users and their roles
                        </p>
                    </div>
                    <Link
                        href="/users/create"
                        className="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 transition-colors"
                    >
                        + Create User
                    </Link>
                </div>

                {/* Success Message */}
                {flash?.success && (
                    <div className="mb-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded">
                        {flash.success}
                    </div>
                )}

                <div className="rounded-lg border bg-card">
                    {/* Users Table */}
                    {users.data.length > 0 ? (
                        <>
                            <div className="relative w-full overflow-auto">
                                <table className="w-full caption-bottom text-sm">
                                    <thead className="border-b">
                                        <tr className="border-b transition-colors hover:bg-muted/50">
                                            <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground whitespace-nowrap">
                                                ID
                                            </th>
                                            <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground whitespace-nowrap">
                                                Name
                                            </th>
                                            <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground whitespace-nowrap">
                                                Email
                                            </th>
                                            <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground whitespace-nowrap">
                                                Role
                                            </th>
                                            <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground whitespace-nowrap">
                                                Joined
                                            </th>
                                            <th className="h-12 px-4 text-center align-middle font-medium text-muted-foreground whitespace-nowrap">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {users.data.map((user) => (
                                            <tr
                                                key={user.id}
                                                className="border-b transition-colors hover:bg-muted/50"
                                            >
                                                <td className="p-4 align-middle font-medium whitespace-nowrap">
                                                    {user.id}
                                                </td>
                                                <td className="p-4 align-middle whitespace-nowrap">
                                                    {user.name}
                                                </td>
                                                <td className="p-4 align-middle text-muted-foreground whitespace-nowrap">
                                                    {user.email}
                                                </td>
                                                <td className="p-4 align-middle whitespace-nowrap">
                                                    <span className={`inline-flex items-center justify-center px-3 py-1 text-xs font-medium rounded-md ${getRoleBadgeColor(user.role)}`}>
                                                        {user.role}
                                                    </span>
                                                </td>
                                                <td className="p-4 align-middle text-muted-foreground whitespace-nowrap">
                                                    {formatDate(user.created_at)}
                                                </td>
                                                <td className="p-4 align-middle whitespace-nowrap">
                                                    <div className="flex gap-2">
                                                        <Link
                                                            href={`/users/${user.id}/edit`}
                                                            className="text-sm font-medium text-primary hover:underline"
                                                        >
                                                            Edit
                                                        </Link>
                                                        <button
                                                            onClick={() => handleDelete(user.id)}
                                                            className="text-sm font-medium text-destructive hover:underline"
                                                        >
                                                            Delete
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            {/* Pagination */}
                            {users.links && users.links.length > 3 && (
                                <div className="flex items-center justify-center gap-2 p-4 border-t">
                                    {users.links.map((link, index) => (
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
                        </>
                    ) : (
                        <div className="h-24 text-center text-muted-foreground flex items-center justify-center">
                            No users found.
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
