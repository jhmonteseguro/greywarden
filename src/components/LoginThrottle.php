<?php

namespace app\components;

use Yii;
use yii\db\Query;

/**
 * Login rate limiting / temporary lockout backed by the login_attempt table.
 *
 * A username is locked when it has >= auth.maxFailedAttempts failures within
 * auth.attemptWindow seconds (since its last success). A source IP is locked at
 * auth.ipMaxFailedAttempts. Locks last auth.lockoutDuration seconds after the
 * most recent failure.
 */
class LoginThrottle
{
    public int $maxAttempts;
    public int $ipMaxAttempts;
    public int $window;
    public int $lockout;

    public function __construct()
    {
        $p = Yii::$app->params;
        $this->maxAttempts = (int) ($p['auth.maxFailedAttempts'] ?? 5);
        $this->ipMaxAttempts = (int) ($p['auth.ipMaxFailedAttempts'] ?? 20);
        $this->window = (int) ($p['auth.attemptWindow'] ?? 900);
        $this->lockout = (int) ($p['auth.lockoutDuration'] ?? 900);
    }

    /** Seconds remaining on a lock for this username or IP (0 = not locked). */
    public function lockedFor(string $username, ?string $ip, ?int $now = null): int
    {
        $now ??= time();
        $username = mb_strtolower(trim($username));
        $remaining = $this->remaining(['username' => $username], $this->maxAttempts, $now);
        if ($ip) {
            $remaining = max($remaining, $this->remaining(['ip' => $ip], $this->ipMaxAttempts, $now));
        }
        return $remaining;
    }

    private function remaining(array $where, int $max, int $now): int
    {
        $since = date('Y-m-d H:i:s', $now - max($this->window, $this->lockout));
        $lastSuccess = (new Query())->from('login_attempt')->where($where)->andWhere(['success' => 1])
            ->max('attempted_at');
        if ($lastSuccess && $lastSuccess > $since) {
            $since = $lastSuccess;
        }
        $failures = (new Query())->select('attempted_at')->from('login_attempt')->where($where)
            ->andWhere(['success' => 0])->andWhere(['>', 'attempted_at', $since])
            ->orderBy(['attempted_at' => SORT_DESC])->limit($max)->column();
        if (count($failures) < $max) {
            return 0;
        }
        // Lock starts at the Nth failure inside the window
        $windowStart = strtotime(end($failures));
        $latest = strtotime($failures[0]);
        if ($latest - $windowStart > $this->window) {
            return 0;
        }
        return max(0, $latest + $this->lockout - $now);
    }

    public function record(string $username, ?string $ip, bool $success, ?string $userAgent = null): void
    {
        Yii::$app->db->createCommand()->insert('login_attempt', [
            'username' => mb_substr(mb_strtolower(trim($username)), 0, 190),
            'ip' => $ip ?? 'unknown',
            'success' => $success ? 1 : 0,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
            'attempted_at' => date('Y-m-d H:i:s'),
        ])->execute();

        if (random_int(1, 50) === 1) {
            Yii::$app->db->createCommand()->delete('login_attempt', ['<', 'attempted_at', date('Y-m-d H:i:s', strtotime('-90 days'))])->execute();
        }
    }

    /** Clear recent failures for a username (admin "unlock"). */
    public function clear(string $username): int
    {
        return Yii::$app->db->createCommand()->delete('login_attempt', [
            'and', ['username' => mb_strtolower(trim($username))], ['success' => 0],
        ])->execute();
    }
}
