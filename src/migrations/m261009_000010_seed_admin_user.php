<?php

use app\components\Migration;
use app\models\User;

/**
 * Creates the initial administrator from ADMIN_* environment values.
 * The admin must change the password at first login.
 */
class m261009_000010_seed_admin_user extends Migration
{
    public function safeUp()
    {
        $username = gw_env('ADMIN_USERNAME', 'admin');
        if (User::findByUsername($username)) {
            echo "    > admin user '{$username}' already exists, skipping\n";
            return;
        }
        $user = User::createUser(
            $username,
            gw_env('ADMIN_EMAIL', 'admin@example.com'),
            gw_env('ADMIN_FULL_NAME', 'System Administrator'),
            gw_env('ADMIN_PASSWORD', 'ChangeMe!2026'),
            app\components\Rbac::ROLE_ADMIN,
            true
        );
        echo "    > created admin user '{$user->username}' (password change required at first login)\n";
    }

    public function safeDown()
    {
        echo "    > leaving admin user in place\n";
    }
}
