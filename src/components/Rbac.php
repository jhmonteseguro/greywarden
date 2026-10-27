<?php

namespace app\components;

use Yii;

/**
 * Central definition of roles and permissions.
 *
 *   viewer  -> records.view
 *   analyst -> viewer + records.manage
 *   admin   -> analyst + records.delete, users.manage, lookups.manage, audit.view, settings.manage
 */
final class Rbac
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_ANALYST = 'analyst';
    public const ROLE_VIEWER = 'viewer';

    public const PERM_VIEW = 'records.view';
    public const PERM_MANAGE = 'records.manage';
    public const PERM_DELETE = 'records.delete';
    public const PERM_USERS = 'users.manage';
    public const PERM_LOOKUPS = 'lookups.manage';
    public const PERM_AUDIT = 'audit.view';
    public const PERM_SETTINGS = 'settings.manage';

    public static function permissionDescriptions(): array
    {
        return [
            self::PERM_VIEW => 'View records in all modules',
            self::PERM_MANAGE => 'Create, edit, archive and restore records',
            self::PERM_DELETE => 'Permanently delete archived records',
            self::PERM_USERS => 'Manage users and role assignments',
            self::PERM_LOOKUPS => 'Manage lookup lists',
            self::PERM_AUDIT => 'View the global audit log',
            self::PERM_SETTINGS => 'Change instance settings and branding',
        ];
    }

    public static function roleLabels(): array
    {
        return [
            self::ROLE_ADMIN => 'Admin',
            self::ROLE_ANALYST => 'Analyst',
            self::ROLE_VIEWER => 'Viewer',
        ];
    }

    /** Convenience checks for views */
    public static function can(string $permission): bool
    {
        return Yii::$app->has('user') && !Yii::$app->user->isGuest && Yii::$app->user->can($permission);
    }

    public static function canManage(): bool
    {
        return self::can(self::PERM_MANAGE);
    }

    public static function canDelete(): bool
    {
        return self::can(self::PERM_DELETE);
    }
}
