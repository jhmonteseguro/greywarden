<?php

use app\components\Migration;

/**
 * Two-step sign-in: an authenticator app (TOTP) per user, passkeys (WebAuthn) and one-time
 * recovery codes. user.passkey_handle is the random id passkeys store for the user, so the
 * database id never leaves the server.
 */
class m261109_100001_create_mfa_tables extends Migration
{
    public function safeUp()
    {
        $this->createTable('user_totp', [
            'user_id' => $this->integer()->notNull(),
            'secret' => $this->string(255)->notNull()->comment('Encrypted with SecretBox'),
            'confirmed_at' => $this->dateTime()->null()->comment('Null while being set up'),
            'last_counter' => $this->bigInteger()->null()->comment('Last time step used, so a code works once'),
            'created_at' => $this->dateTime()->notNull(),
            'PRIMARY KEY ([[user_id]])',
        ], $this->tableOptions());
        $this->addForeignKey('fk_user_totp_user', 'user_totp', 'user_id', 'user', 'id', 'CASCADE', 'CASCADE');

        $this->createTable('user_passkey', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'name' => $this->string(64)->notNull(),
            'credential_hash' => $this->char(64)->notNull()->unique()->comment('SHA-256 of the credential id, for lookups'),
            'credential_id' => $this->text()->notNull()->comment('base64url'),
            'public_key' => $this->text()->notNull()->comment('PEM'),
            'sign_count' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'aaguid' => $this->string(36)->null(),
            'backed_up' => $this->boolean()->notNull()->defaultValue(false),
            'created_at' => $this->dateTime()->notNull(),
            'last_used_at' => $this->dateTime()->null(),
        ], $this->tableOptions());
        $this->addForeignKey('fk_user_passkey_user', 'user_passkey', 'user_id', 'user', 'id', 'CASCADE', 'CASCADE');

        $this->createTable('user_recovery_code', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'code_hash' => $this->string(255)->notNull(),
            'used_at' => $this->dateTime()->null(),
            'created_at' => $this->dateTime()->notNull(),
        ], $this->tableOptions());
        $this->addForeignKey('fk_user_recovery_code_user', 'user_recovery_code', 'user_id', 'user', 'id', 'CASCADE', 'CASCADE');

        $this->addColumn('user', 'passkey_handle', $this->string(64)->null()->unique()->after('auth_key'));
    }

    public function safeDown()
    {
        $this->dropColumn('user', 'passkey_handle');
        $this->dropTable('user_recovery_code');
        $this->dropTable('user_passkey');
        $this->dropTable('user_totp');
    }
}
