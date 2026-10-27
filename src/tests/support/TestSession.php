<?php

namespace tests\support;

use yii\web\Session;

/**
 * In-memory session for in-process web requests: $_SESSION is a plain array
 * that survives between requests of one test (so a sign-in sticks), and no PHP
 * session functions or headers are touched.
 */
class TestSession extends Session
{
    private bool $opened = false;

    public function open(): void
    {
        if (!$this->opened) {
            $this->opened = true;
            $_SESSION ??= [];
            $this->updateFlashCounters();
        }
    }

    public function close(): void
    {
    }

    public function destroy(): void
    {
        $_SESSION = [];
    }

    public function regenerateID($deleteOldSession = false): void
    {
    }

    public function getIsActive(): bool
    {
        return $this->opened;
    }

    public function getHasSessionId(): bool
    {
        return true;
    }

    public function getId(): string
    {
        return 'phpunit';
    }
}
