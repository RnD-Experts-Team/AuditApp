import { Head, Link, router } from "@inertiajs/react";
import { useMemo, useState } from "react";
import AuthenticatedLayout from "@/layouts/app-layout";

/**
 * Local page types (self-contained) to avoid mismatches with project-wide PageProps.
 * This eliminates TS errors like:
 * - flash.success does not exist on type {}
 * - user_groups does not exist on type User
 * - implicit any errors
 */

type AuthUser = {
  id: number;
  name: string;
  email: string;
  role: "Admin" | "User";
};

type FlashBag = {
  success?: string;
  error?: string;
};

type UserGroupRow = {
  id: number;
  group: number;
};

type UserRow = {
  id: number;
  name: string;
  email: string;
  role: "Admin" | "User";
  created_at: string;
  userGroups?: UserGroupRow[]; // IMPORTANT: backend uses with('userGroups')
};

type PaginationLink = {
  url: string | null;
  label: string;
  active: boolean;
};

type PaginatedUsers = {
  data: UserRow[];
  links?: PaginationLink[];
};

type PageProps = {
  auth: { user: AuthUser };
  users: PaginatedUsers;
  groups: number[];
  flash?: FlashBag;
};

export default function Index({ auth, users, groups, flash }: PageProps) {
  const [editingId, setEditingId] = useState<number | null>(null);
  const [role, setRole] = useState<"Admin" | "User">("User");
  const [selectedGroups, setSelectedGroups] = useState<number[]>([]);
  const [saving, setSaving] = useState<boolean>(false);

  const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString("en-US", {
      year: "numeric",
      month: "short",
      day: "numeric",
    });
  };

  const getRoleBadgeColor = (r: string) => {
    return r === "Admin"
      ? "bg-red-100 text-red-800"
      : "bg-blue-100 text-blue-800";
  };

  const startEdit = (user: UserRow) => {
    setEditingId(user.id);
    setRole(user.role);

    const currentGroups = (user.userGroups ?? []).map(
      (g: UserGroupRow) => g.group,
    );
    setSelectedGroups(currentGroups);
  };

  const cancelEdit = () => {
    setEditingId(null);
    setRole("User");
    setSelectedGroups([]);
    setSaving(false);
  };

  const toggleGroup = (groupNumber: number) => {
    setSelectedGroups((prev: number[]) =>
      prev.includes(groupNumber)
        ? prev.filter((g: number) => g !== groupNumber)
        : [...prev, groupNumber],
    );
  };

  const canSave = useMemo(() => {
    if (!editingId) return false;
    return true; // role is required; groups can be empty if you want
  }, [editingId]);

  const save = () => {
    if (!editingId || !canSave) return;

    setSaving(true);

    router.put(
      `/users/${editingId}`,
      {
        role,
        groups: role === "User" ? selectedGroups : [],
      },
      {
        preserveScroll: true,
        onFinish: () => setSaving(false),
        onSuccess: () => cancelEdit(),
      },
    );
  };

  return (
    <AuthenticatedLayout user={auth.user}>
      <Head title="Users" />

      <div className="flex flex-1 flex-col gap-4 p-4 pt-0">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold tracking-tight">
              Users Management
            </h1>
            <p className="text-sm text-muted-foreground mt-1">
              Read-only user list. You can only manage role and group access.
            </p>
          </div>

          {/* Create is disabled intentionally */}
          <Link
            href="#"
            onClick={(e) => e.preventDefault()}
            className="inline-flex items-center justify-center rounded-md bg-primary/40 px-4 py-2 text-sm font-medium text-primary-foreground shadow cursor-not-allowed"
            title="User creation is disabled in QA system"
          >
            + Create User (Disabled)
          </Link>
        </div>

        {/* Flash messages */}
        {flash?.success && (
          <div className="mb-2 p-4 bg-green-100 border border-green-400 text-green-700 rounded">
            {flash.success}
          </div>
        )}
        {flash?.error && (
          <div className="mb-2 p-4 bg-red-100 border border-red-400 text-red-700 rounded">
            {flash.error}
          </div>
        )}

        <div className="rounded-lg border bg-card">
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
                        Groups
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
                    {users.data.map((user: UserRow) => {
                      const isEditing = editingId === user.id;

                      const userGroups = (user.userGroups ?? [])
                        .map((g: UserGroupRow) => g.group)
                        .filter((g: number) => Number.isInteger(g));

                      return (
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
                            {isEditing ? (
                              <select
                                value={role}
                                onChange={(e) =>
                                  setRole(e.target.value as "Admin" | "User")
                                }
                                className="flex h-9 w-32 rounded-md border border-input bg-background px-3 py-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                              >
                                <option value="User">User</option>
                                <option value="Admin">Admin</option>
                              </select>
                            ) : (
                              <span
                                className={`inline-flex items-center justify-center px-3 py-1 text-xs font-medium rounded-md ${getRoleBadgeColor(
                                  user.role,
                                )}`}
                              >
                                {user.role}
                              </span>
                            )}
                          </td>

                          <td className="p-4 align-middle">
                            {isEditing ? (
                              <div className="min-w-[280px]">
                                {role === "Admin" ? (
                                  <span className="text-xs text-muted-foreground">
                                    Admin has access to all groups.
                                  </span>
                                ) : groups.length > 0 ? (
                                  <div className="flex flex-wrap gap-2">
                                    {groups.map((g: number) => (
                                      <label
                                        key={g}
                                        className="inline-flex items-center gap-2 rounded-md border px-2 py-1 text-xs cursor-pointer hover:bg-accent"
                                      >
                                        <input
                                          type="checkbox"
                                          checked={selectedGroups.includes(g)}
                                          onChange={() => toggleGroup(g)}
                                          className="rounded border-gray-300"
                                        />
                                        Group {g}
                                      </label>
                                    ))}
                                  </div>
                                ) : (
                                  <span className="text-xs text-muted-foreground">
                                    No groups available yet.
                                  </span>
                                )}
                              </div>
                            ) : (
                              <div className="flex flex-wrap gap-2">
                                {role === "Admin" ? (
                                  <span className="text-xs text-muted-foreground">
                                    Admin has access to all groups.
                                  </span>
                                ) : groups.length > 0 ? (
                                  <div className="flex flex-wrap gap-2">
                                    {groups.map((g: number) => (
                                      <label
                                        key={g}
                                        className="inline-flex items-center gap-2 rounded-md border px-2 py-1 text-xs cursor-pointer hover:bg-accent"
                                      >
                                        <input
                                          type="checkbox"
                                          checked={selectedGroups.includes(g)}
                                          onChange={() => toggleGroup(g)}
                                          className="rounded border-gray-300"
                                        />
                                        Group {g}
                                      </label>
                                    ))}
                                  </div>
                                ) : (
                                  <span className="text-xs text-muted-foreground">
                                    No groups available yet.
                                  </span>
                                )}
                              </div>
                            )}
                          </td>

                          <td className="p-4 align-middle text-muted-foreground whitespace-nowrap">
                            {formatDate(user.created_at)}
                          </td>

                          <td className="p-4 align-middle whitespace-nowrap">
                            <div className="flex gap-2 justify-center">
                              {isEditing ? (
                                <>
                                  <button
                                    onClick={save}
                                    disabled={saving || !canSave}
                                    className="inline-flex items-center justify-center rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground shadow hover:bg-primary/90 disabled:opacity-50"
                                  >
                                    {saving ? "Saving..." : "Save"}
                                  </button>
                                  <button
                                    onClick={cancelEdit}
                                    disabled={saving}
                                    className="inline-flex items-center justify-center rounded-md border border-input bg-background px-3 py-1.5 text-xs font-medium hover:bg-accent hover:text-accent-foreground disabled:opacity-50"
                                  >
                                    Cancel
                                  </button>
                                </>
                              ) : (
                                <button
                                  onClick={() => startEdit(user)}
                                  className="text-sm font-medium text-primary hover:underline"
                                >
                                  Edit Permissions
                                </button>
                              )}
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>

              {/* Pagination */}
              {users.links && users.links.length > 3 && (
                <div className="flex items-center justify-center gap-2 p-4 border-t">
                  {users.links.map((link: PaginationLink, index: number) => (
                    <button
                      key={index}
                      onClick={() => link.url && router.visit(link.url)}
                      disabled={!link.url}
                      className={`inline-flex items-center justify-center rounded-md px-3 py-1 text-sm font-medium transition-colors ${
                        link.active
                          ? "bg-primary text-primary-foreground"
                          : "hover:bg-accent hover:text-accent-foreground"
                      } ${!link.url ? "opacity-50 cursor-not-allowed" : ""}`}
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
