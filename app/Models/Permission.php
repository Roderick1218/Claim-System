<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'description'])]
class Permission extends Model
{
    // Every user can always create / view / edit their OWN claims; these grant access beyond that.
    public const CLAIMS_VIEW_DEPARTMENT = 'claims.view_department';

    public const CLAIMS_VIEW_ALL = 'claims.view_all';

    public const CLAIMS_MANAGE_ALL = 'claims.manage_all';

    public const CLAIMS_APPROVE = 'claims.approve';

    public const REPORTS_VIEW = 'reports.view';

    public const SETTINGS_USERS = 'settings.users';

    public const SETTINGS_DEPARTMENTS = 'settings.departments';

    public const SETTINGS_ROLES = 'settings.roles';

    public const SETTINGS_CATEGORIES = 'settings.categories';

    public const SETTINGS_PROJECTS = 'settings.projects';

    public const AUDIT_VIEW = 'audit.view';

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
