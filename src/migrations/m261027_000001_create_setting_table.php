<?php

use app\components\Migration;
use app\components\Rbac;

/**
 * Instance settings edited in Admin › Settings (branding: display name,
 * organisation, logo, sign-in text, PDF marking), plus the settings.manage
 * permission for admins.
 *
 * The PDF letterhead and marking used to come from .env (PDF_ORGANISATION,
 * PDF_CLASSIFICATION); values still set there are copied in once, so existing
 * installs keep their branding.
 */
class m261027_000001_create_setting_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('setting', [
            'name' => $this->string(64)->notNull(),
            'value' => $this->text()->null(),
            'updated_at' => $this->dateTime()->notNull(),
            'updated_by' => $this->integer()->null(),
            'PRIMARY KEY ([[name]])',
        ], $this->tableOptions());
        $this->addUserForeignKeys('setting', ['updated_by']);

        foreach (['organisation' => 'PDF_ORGANISATION', 'pdf_classification' => 'PDF_CLASSIFICATION'] as $name => $env) {
            $value = trim((string) gw_env($env, ''));
            if ($value !== '') {
                $this->insert('setting', ['name' => $name, 'value' => $value, 'updated_at' => date('Y-m-d H:i:s')]);
            }
        }

        $auth = Yii::$app->authManager;
        $permission = $auth->getPermission(Rbac::PERM_SETTINGS);
        if (!$permission) {
            $permission = $auth->createPermission(Rbac::PERM_SETTINGS);
            $permission->description = Rbac::permissionDescriptions()[Rbac::PERM_SETTINGS];
            $auth->add($permission);
        }
        $admin = $auth->getRole(Rbac::ROLE_ADMIN);
        if ($admin && !$auth->hasChild($admin, $permission)) {
            $auth->addChild($admin, $permission);
        }
    }

    public function safeDown()
    {
        $auth = Yii::$app->authManager;
        if ($permission = $auth->getPermission(Rbac::PERM_SETTINGS)) {
            $auth->remove($permission);
        }
        $this->dropTable('setting');
    }
}
