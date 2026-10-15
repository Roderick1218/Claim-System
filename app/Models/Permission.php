<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'description'])]
class Permission extends Model
{
    // 每个人本来就能新建、查看、修改自己的报销单，不需要权限。
    // 下面这些是在“自己的”之外额外开放的范围，通过 role 分配，Settings → Roles & Permissions 里可以调。
    public const CLAIMS_VIEW_DEPARTMENT = 'claims.view_department'; // 看同部门同事的单

    public const CLAIMS_VIEW_ALL = 'claims.view_all'; // 看全公司的单，默认只有 admin 有

    public const CLAIMS_MANAGE_ALL = 'claims.manage_all'; // 改 / 删别人的单（前提是看得到）

    public const CLAIMS_APPROVE = 'claims.approve'; // 审批，但永远不能批自己的

    public const REPORTS_VIEW = 'reports.view'; // 报表和 CSV 导出，范围同样受 visibleTo 限制

    public const SETTINGS_USERS = 'settings.users';

    public const SETTINGS_DEPARTMENTS = 'settings.departments';

    public const SETTINGS_ROLES = 'settings.roles';

    public const SETTINGS_CATEGORIES = 'settings.categories';

    public const SETTINGS_PROJECTS = 'settings.projects';

    public const AUDIT_VIEW = 'audit.view';

    // seeder 按这份清单建权限，描述会显示在角色设置页面
    /** @return array<string, string> name => description */
    public static function definitions(): array
    {
        return [
            self::CLAIMS_VIEW_DEPARTMENT => 'View claims of users in own department',
            self::CLAIMS_VIEW_ALL => 'View all claims in the company',
            self::CLAIMS_MANAGE_ALL => 'Edit / delete claims of other users (that they can view)',
            self::CLAIMS_APPROVE => 'Approve or reject submitted claims (that they can view; never their own)',
            self::REPORTS_VIEW => 'Open reports and export CSV (within own view scope)',
            self::SETTINGS_USERS => 'Manage users and their roles',
            self::SETTINGS_DEPARTMENTS => 'Manage departments',
            self::SETTINGS_ROLES => 'Manage roles and role permissions',
            self::SETTINGS_CATEGORIES => 'Manage claim categories',
            self::SETTINGS_PROJECTS => 'Manage projects',
            self::AUDIT_VIEW => 'View audit logs',
        ];
    }

    // 后台设置类的全部权限，seeder 给 admin 角色时整包分配
    public static function settingsPermissions(): array
    {
        return [
            self::SETTINGS_USERS, self::SETTINGS_DEPARTMENTS, self::SETTINGS_ROLES,
            self::SETTINGS_CATEGORIES, self::SETTINGS_PROJECTS, self::AUDIT_VIEW,
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')->withTimestamps();
    }
}
