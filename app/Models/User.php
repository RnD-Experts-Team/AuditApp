<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'role',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Get all audits for this user.
     */
    public function audits(): HasMany
    {
        return $this->hasMany(Audit::class);
    }

    /**
     * Get all camera forms for this user.
     */
    public function cameraForms(): HasMany
    {
        return $this->hasMany(CameraForm::class);
    }

    /**
     * Get all groups assigned to this user.
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            Store::class,
            'user_groups',
            'user_id',
            'group',
            'id',
            'group'
        );
    }

    /**
     * Get the groups as array of numbers.
     */
    public function getGroupsAttribute(): array
    {
        return $this->userGroups()->pluck('group')->toArray();
    }

    /**
     * Relationship for user groups.
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
     * Check if user has access to a specific group.
     */
    public function hasGroupAccess(int $group): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->userGroups()
            ->where('group', $group)
            ->exists();
    }

    /**
     * Check if user has access to a store.
     */
    public function canAccessStore(Store $store): bool
    {
        return $this->hasGroupAccess($store->group);
    }
}
