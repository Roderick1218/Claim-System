<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'employee_code', 'email', 'password', 'department_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // 跟数据库默认值保持一致，否则刚 new 出来还没重新读取的实例 is_active 会是 null
    protected $attributes = ['is_active' => true];

    // 一个请求里会多次判断权限，查一次就缓存起来
    /** @var array<int, string>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withTimestamps();
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    // 用户所有角色的权限合集
    /** @return array<int, string> */
    public function permissionNames(): array
    {
        if ($this->permissionCache === null) {
            $this->permissionCache = Permission::query()
                ->whereHas('roles.users', fn ($q) => $q->whereKey($this->getKey()))
                ->pluck('name')
                ->all();
        }

        return $this->permissionCache;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissionNames(), true);
    }

    public function hasAnyPermission(array $permissions): bool
    {
        return count(array_intersect($permissions, $this->permissionNames())) > 0;
    }

    public function hasRole(string $role): bool
    {
        return $this->roles->contains('name', $role);
    }

    // 同一个请求里改了这个用户的角色后要调用，不然后面拿到的还是旧权限
    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
        $this->unsetRelation('roles');
    }
}
