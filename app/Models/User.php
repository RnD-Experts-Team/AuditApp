<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Get all groups assigned to this user.
     */
    public function userGroups(): HasMany
    {
        return $this->hasMany(UserGroup::class);
    }

    /**
     * Check if user is admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'Admin';
    }

    /**
     * Get all group numbers for this user.
     */
    public function getGroupNumbers(): array
    {
        if ($this->isAdmin()) {
            return [];
        }
        return $this->userGroups()->pluck('group')->toArray();
    }

    /**
     * Check if user has access to a specific group.
     */
    public function hasGroupAccess(int $group): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        return $this->userGroups()->where('group', $group)->exists();
    }

    /**
     * Check if user can access a store.
     */
    public function canAccessStore(Store $store): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        if (!$store->group) {
            return false;
        }
        return $this->hasGroupAccess($store->group);
    }

    /**
     * Check if user can access audit (via store).
     */
    public function canAccessAudit(Audit $audit): bool
    {
        return $this->canAccessStore($audit->store);
    }
}
