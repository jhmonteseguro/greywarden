<?php

namespace tests\unit;

use app\components\SecretBox;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;

class SecretBoxTest extends TestCase
{
    private string $saved;
    private string $dir;

    protected function setUp(): void
    {
        $this->saved = Yii::$app->params['mfa.keyPath'];
        $this->dir = sys_get_temp_dir() . '/gw-secretbox-' . bin2hex(random_bytes(4));
        Yii::$app->params['mfa.keyPath'] = $this->dir . '/keys/mfa.key';
    }

    protected function tearDown(): void
    {
        Yii::$app->params['mfa.keyPath'] = $this->saved;
        FileHelper::removeDirectory($this->dir);
    }

    public function testRoundTripCreatesAPrivateKeyOnce(): void
    {
        $sealed = SecretBox::encrypt('JBSWY3DPEHPK3PXP');
        $this->assertStringStartsWith('sb1:', $sealed);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $sealed);
        $this->assertNotSame($sealed, SecretBox::encrypt('JBSWY3DPEHPK3PXP'), 'a new nonce each time');
        $this->assertSame('JBSWY3DPEHPK3PXP', SecretBox::decrypt($sealed));
        $this->assertSame(SecretBox::keyPath(), $this->dir . '/keys/mfa.key');
        $this->assertSame('600', substr(sprintf('%o', fileperms(SecretBox::keyPath())), -3));
        $key = file_get_contents(SecretBox::keyPath());
        SecretBox::encrypt('again');
        $this->assertSame($key, file_get_contents(SecretBox::keyPath()), 'the key is never replaced');
    }

    public function testTamperedOrForeignValuesDoNotDecrypt(): void
    {
        $sealed = SecretBox::encrypt('secret');
        $raw = base64_decode(substr($sealed, 4));
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        $this->assertNull(SecretBox::decrypt('sb1:' . base64_encode($raw)), 'tampered');
        $this->assertNull(SecretBox::decrypt('JBSWY3DPEHPK3PXP'), 'not sealed');
        $this->assertNull(SecretBox::decrypt('sb1:!!!'), 'not base64');
        $this->assertNull(SecretBox::decrypt('sb1:' . base64_encode('short')), 'too short');

        // Another installation's key
        unlink(SecretBox::keyPath());
        $this->assertNull(SecretBox::decrypt($sealed));
    }

    public function testADamagedKeyIsAnError(): void
    {
        SecretBox::encrypt('x');
        file_put_contents(SecretBox::keyPath(), "not a key\n");
        $this->expectException(InvalidConfigException::class);
        SecretBox::encrypt('x');
    }
}
