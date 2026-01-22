<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Support\Facades\Cache;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'id',
        'name',
        'email',
    ];

    protected $hidden = [
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function storeRoles(): HasMany
    {
        return $this->hasMany(UserStoreRole::class);
    }

    /**
     * TRUE if request-scoped authz_roles includes "super-admin"
     * OR if Spatie roles has "super-admin"
     */
    public function isSuperAdmin(): bool
    {
        // 1) If you replicated/spatie-synced roles locally, honor it:
        try {
            if (method_exists($this, 'hasRole') && $this->hasRole('super-admin')) {
                return true;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // 2) If roles were attached to the user object dynamically (optional approach)
        // e.g. $user->authz_roles = [...]
        if (property_exists($this, 'authz_roles')) {
            $roles = $this->authz_roles;
            if (is_array($roles) && in_array('super-admin', $roles, true)) {
                return true;
            }
        }

        // 3) Request attributes (what you described)
        // Works in normal HTTP request lifecycle.
        try {
            $roles = request()?->attributes?->get('authz_roles', []);
            if (!is_array($roles)) $roles = [];
            return in_array('super-admin', $roles, true);
        } catch (\Throwable $e) {
            // e.g. running from CLI
        }

        return false;
    }

    /**
     * Returns store IDs the user can access.
     * - If super-admin => ALL stores.
     * - If they have a row with store_id NULL => access to ALL stores.
     * - Cached in Redis for performance.
     */
    public function allowedStoreIdsCached(int $ttlSeconds = 300): array
    {
        // ✅ super-admin: bypass everything
        if ($this->isSuperAdmin()) {
            return Store::query()
                ->orderBy('id')
                ->pluck('id')
                ->map(fn($v) => (int) $v)
                ->all();
        }

        $cache = Cache::store('redis');
        $key = "qa:allowed_store_ids:user:{$this->id}";

        return $cache->remember($key, $ttlSeconds, function () {
            $q = $this->storeRoles()
                ->where('active', true);

            $hasAllStores = (clone $q)
                ->whereNull('store_id')
                ->exists();

            if ($hasAllStores) {
                return Store::query()->orderBy('id')->pluck('id')->map(fn($v) => (int) $v)->all();
            }

            return (clone $q)
                ->whereNotNull('store_id')
                ->pluck('store_id')
                ->map(fn($v) => (int) $v)
                ->unique()
                ->values()
                ->all();
        });
    }

    public function canAccessStoreId(int $storeId): bool
    {
        // ✅ super-admin: can access any store_id
        if ($this->isSuperAdmin()) {
            return true;
        }

        $storeId = (int) $storeId;
        if ($storeId <= 0) return false;

        $allowed = $this->allowedStoreIdsCached();
        return in_array($storeId, $allowed, true);
    }

    /**
     * Used for audit checks through relationships (Audit has store_id).
     */
    public function canAccessAudit(Audit $audit): bool
    {
        // ✅ super-admin: can access any audit
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->canAccessStoreId((int) $audit->store_id);
    }

    /**
     * Hard rule: QA access is store-scoped now.
     * If you need role checks later:
     */
    public function hasStoreRole(string $roleName, ?int $storeId = null): bool
    {
        // ✅ super-admin: has everything
        if ($this->isSuperAdmin()) {
            return true;
        }

        $roleName = trim($roleName);
        if ($roleName === '') return false;

        $q = $this->storeRoles()
            ->where('active', true)
            ->where('role_name', $roleName);

        if ($storeId === null) {
            return $q->whereNull('store_id')->exists();
        }

        return $q->where(function ($qq) use ($storeId) {
            $qq->whereNull('store_id')->orWhere('store_id', (int) $storeId);
        })->exists();
    }
}
