<?php

use app\components\Migration;
use app\components\Rbac;

/**
 * Creates the permission hierarchy: viewer < analyst < admin.
 */
class m261009_000009_seed_rbac extends Migration
{
    public function safeUp()
    {
        $auth = Yii::$app->authManager;
        $perm = [];
        foreach (Rbac::permissionDescriptions() as $name => $description) {
            $p = $auth->createPermission($name);
            $p->description = $description;
            $auth->add($p);
            $perm[$name] = $p;
        }

        $viewer = $auth->createRole(Rbac::ROLE_VIEWER);
        $viewer->description = 'Read-only access to all registers';
        $auth->add($viewer);
        $auth->addChild($viewer, $perm[Rbac::PERM_VIEW]);

        $analyst = $auth->createRole(Rbac::ROLE_ANALYST);
        $analyst->description = 'Create and edit records in all modules';
        $auth->add($analyst);
        $auth->addChild($analyst, $viewer);
        $auth->addChild($analyst, $perm[Rbac::PERM_MANAGE]);

        $admin = $auth->createRole(Rbac::ROLE_ADMIN);
        $admin->description = 'Full access incl. users, lookups, audit log and hard delete';
        $auth->add($admin);
        $auth->addChild($admin, $analyst);
        foreach ([Rbac::PERM_DELETE, Rbac::PERM_USERS, Rbac::PERM_LOOKUPS, Rbac::PERM_AUDIT] as $name) {
            $auth->addChild($admin, $perm[$name]);
        }
    }

    public function safeDown()
    {
        $auth = Yii::$app->authManager;
        foreach ([Rbac::ROLE_ADMIN, Rbac::ROLE_ANALYST, Rbac::ROLE_VIEWER] as $r) {
            if ($role = $auth->getRole($r)) {
                $auth->remove($role);
            }
        }
        foreach (array_keys(Rbac::permissionDescriptions()) as $p) {
            if ($perm = $auth->getPermission($p)) {
                $auth->remove($perm);
            }
        }
    }
}
