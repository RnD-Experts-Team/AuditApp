import { Head } from '@inertiajs/react';
import { useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/app-layout';
import { PageProps } from '@/types';
import { EditUserPageProps, UpdateUserFormData } from '@/types/user';

interface EditPageProps extends PageProps<EditUserPageProps> {
    groups: number[];
    userGroups: number[];
}

export default function Edit({
    auth,
    user,
    groups,
    userGroups,
}: EditPageProps) {
    const { data, setData, put, errors, processing } = useForm<UpdateUserFormData & { groups: number[] }>({
        name: user.name,
        email: user.email,
        role: user.role,
        password: '',
        password_confirmation: '',
        groups: userGroups,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/users/${user.id}`, {
            preserveScroll: true,
        });
    };

    const toggleGroup = (group: number) => {
        setData('groups',
            data.groups.includes(group)
                ? data.groups.filter(g => g !== group)
                : [...data.groups, group]
        );
    };

    const showGroupsField = data.role === 'User';

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Edit User" />

            <div className="flex flex-1 flex-col gap-4 p-4 pt-0">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">Edit User</h1>
                    <p className="text-sm text-muted-foreground mt-1">
                        Update user information and permissions
                    </p>
                </div>

                <div className="rounded-lg border bg-card max-w-2xl">
                    <form onSubmit={handleSubmit} className="p-6 space-y-4">
                        {/* Name */}
                        <div className="space-y-2">
                            <label className="text-sm font-medium">Name</label>
                            <input
                                type="text"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="Enter user name"
                                className={`flex h-10 w-full rounded-md border bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 ${
                                    errors.name ? 'border-destructive' : 'border-input'
                                }`}
                            />
                            {errors.name && (
                                <p className="text-sm text-destructive">{errors.name}</p>
                            )}
                        </div>

                        {/* Email */}
                        <div className="space-y-2">
                            <label className="text-sm font-medium">Email</label>
                            <input
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="Enter email address"
                                className={`flex h-10 w-full rounded-md border bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 ${
                                    errors.email ? 'border-destructive' : 'border-input'
                                }`}
                            />
                            {errors.email && (
                                <p className="text-sm text-destructive">{errors.email}</p>
                            )}
                        </div>

                        {/* Role */}
                        <div className="space-y-2">
                            <label className="text-sm font-medium">Role</label>
                            <select
                                value={data.role}
                                onChange={(e) => setData('role', e.target.value as any)}
                                className={`flex h-10 w-full rounded-md border bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 ${
                                    errors.role ? 'border-destructive' : 'border-input'
                                }`}
                            >
                                <option value="">-- Select Role --</option>
                                <option value="User">User</option>
                                <option value="Admin">Admin</option>
                            </select>
                            {errors.role && (
                                <p className="text-sm text-destructive">{errors.role}</p>
                            )}
                        </div>

                        {/* Groups - Only show for User role */}
                        {showGroupsField && (
                            <div className="space-y-2">
                                <label className="text-sm font-medium">Assign Groups</label>
                                <p className="text-xs text-muted-foreground mb-3">
                                    Select one or more groups this user can access
                                </p>
                                {groups.length > 0 ? (
                                    <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
                                        {groups.map((group) => (
                                            <label key={group} className="flex items-center space-x-2 cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    checked={data.groups.includes(group)}
                                                    onChange={() => toggleGroup(group)}
                                                    className="rounded border-gray-300"
                                                />
                                                <span className="text-sm">Group {group}</span>
                                            </label>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">No groups available. Create stores with groups first.</p>
                                )}
                                {errors.groups && (
                                    <p className="text-sm text-destructive">{errors.groups}</p>
                                )}
                            </div>
                        )}

                        {/* Password Info */}
                        <div className="p-4 bg-muted rounded-md">
                            <p className="text-sm text-muted-foreground">
                                Leave password fields empty to keep the current password
                            </p>
                        </div>

                        {/* Password */}
                        <div className="space-y-2">
                            <label className="text-sm font-medium">New Password</label>
                            <input
                                type="password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="Leave empty to keep current password"
                                className={`flex h-10 w-full rounded-md border bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 ${
                                    errors.password ? 'border-destructive' : 'border-input'
                                }`}
                            />
                            {errors.password && (
                                <p className="text-sm text-destructive">{errors.password}</p>
                            )}
                        </div>

                        {/* Password Confirmation */}
                        <div className="space-y-2">
                            <label className="text-sm font-medium">Confirm New Password</label>
                            <input
                                type="password"
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                placeholder="Confirm new password"
                                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                            />
                        </div>

                        {/* Buttons */}
                        <div className="flex gap-3 pt-4">
                            <button
                                type="submit"
                                disabled={processing}
                                className="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 disabled:opacity-50"
                            >
                                {processing ? 'Updating...' : 'Update User'}
                            </button>
                            <button
                                type="button"
                                onClick={() => window.history.back()}
                                className="inline-flex items-center justify-center rounded-md border border-input bg-background px-4 py-2 text-sm font-medium hover:bg-accent hover:text-accent-foreground"
                            >
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
