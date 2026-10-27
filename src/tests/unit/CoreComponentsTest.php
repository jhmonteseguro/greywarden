<?php

namespace tests\unit;

use app\components\Actor;
use app\components\LoginThrottle;
use app\components\Migration;
use app\components\Rbac;
use app\helpers\Badge;
use app\helpers\Dt;
use app\helpers\RiskMatrix;
use app\models\AuditLog;
use app\models\Lookup;
use app\models\PasswordPolicy;
use app\models\User;
use app\modules\intel\components\Http;
use tests\DbTestCase;
use Yii;

/**
 * Shared components and helpers: RBAC helpers, login throttling, the migration
 * base class, the HTTP client (against a local server), badges and dates.
 */
class CoreComponentsTest extends DbTestCase
{
    /** @var resource|null */
    private $server = null;
    private ?string $router = null;

    protected function tearDown(): void
    {
        if ($this->server) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ($this->router) {
            @unlink($this->router);
        }
        Actor::override(null);
        parent::tearDown();
    }

    // ---- RBAC ---------------------------------------------------------------------

    public function testRolesInheritPermissions(): void
    {
        $auth = Yii::$app->authManager;
        $expect = [
            Rbac::ROLE_VIEWER => [Rbac::PERM_VIEW],
            Rbac::ROLE_ANALYST => [Rbac::PERM_VIEW, Rbac::PERM_MANAGE],
            Rbac::ROLE_ADMIN => array_keys(Rbac::permissionDescriptions()),
        ];
        foreach ($expect as $role => $permissions) {
            $granted = array_keys($auth->getPermissionsByRole($role));
            sort($granted);
            sort($permissions);
            $this->assertSame($permissions, $granted, $role);
        }
        $this->assertSame(['admin', 'analyst', 'viewer'], array_keys(Rbac::roleLabels()));
        // No web user in the console: the view helpers say no
        $this->assertFalse(Rbac::can(Rbac::PERM_VIEW));
        $this->assertFalse(Rbac::canManage());
        $this->assertFalse(Rbac::canDelete());
    }

    public function testUserRolesAndHelpers(): void
    {
        $user = User::createUser('core.user', 'core.user@example.com', 'Core  Test User', 'Core!Password-1', Rbac::ROLE_VIEWER, false);
        $this->assertSame('Viewer', $user->getRoleLabel());
        $user->assignRole(Rbac::ROLE_ANALYST);
        $user->assignRole(Rbac::ROLE_ANALYST);
        $this->assertSame([Rbac::ROLE_ANALYST], array_keys(Yii::$app->authManager->getRolesByUser($user->id)), 'one role at a time');
        $this->assertSame(1, (int) AuditLog::find()->where(['entity_type' => 'user', 'entity_id' => $user->id, 'action' => 'update'])->count(), 'unchanged role is not re-logged');

        $this->assertSame('CT', $user->getInitials());
        $this->assertSame('?', (new User(['username' => ' ', 'full_name' => '']))->getInitials());
        $this->assertNull((new User())->getRoleName());
        $this->assertSame('None', (new User())->getRoleLabel());
        $this->assertNull(User::findIdentityByAccessToken('anything'));
        $this->assertSame($user->id, User::findIdentity($user->id)->id);
        $user->updateAttributes(['status' => User::STATUS_DISABLED]);
        $this->assertNull(User::findIdentity($user->id), 'disabled users have no identity');
        $this->assertSame($user->id, User::findByLogin('CORE.USER@example.com')?->id ?? User::findByLogin('core.user@example.com')->id);
        $this->assertArrayHasKey($user->id, User::options($user->id));
        $this->assertArrayNotHasKey($user->id, User::options());
        $this->assertNull(User::nameOf(null));
        $this->assertSame('Core  Test User', User::nameOf($user->id));
        $this->assertSame('Analyst', $user->formatAuditValue('role', 'analyst'));
        $this->assertSame('Disabled', $user->formatAuditValue('status', 0));
        $this->assertSame('a, b', $user->formatAuditValue('x', ['a', 'b']));
        $this->assertFalse($user->validateAuthKey('wrong'));

        $this->expectException(\RuntimeException::class);
        User::createUser('core.user', 'other@example.com', 'Duplicate', 'Core!Password-1', Rbac::ROLE_VIEWER);
    }

    public function testPasswordPolicy(): void
    {
        $this->assertNull(PasswordPolicy::check('Long!enough1'));
        $this->assertStringContainsString('at least 10', PasswordPolicy::check('Sh0rt!'));
        $this->assertStringContainsString('at most 128', PasswordPolicy::check(str_repeat('a1', 70)));
        $this->assertStringContainsString('letter and a number or symbol', PasswordPolicy::check('onlyletterslong'));
        $this->assertStringContainsString('letter and a number or symbol', PasswordPolicy::check('1234567890123'));
        $this->assertStringContainsString('10 characters', PasswordPolicy::describe());
    }

    // ---- Login throttle -----------------------------------------------------------

    public function testLoginThrottleCountsFailuresInsideTheWindow(): void
    {
        $insert = static fn(string $user, string $ip, int $ago, int $success = 0) => Yii::$app->db->createCommand()->insert('login_attempt',
            ['username' => $user, 'ip' => $ip, 'success' => $success, 'attempted_at' => date('Y-m-d H:i:s', time() - $ago)])->execute();
        $t = new LoginThrottle();
        foreach ([400, 300, 200, 100, 10] as $ago) {
            $insert('throttled', '192.0.2.20', $ago);
        }
        $this->assertGreaterThan(800, $t->lockedFor(' Throttled ', null));
        $this->assertSame(0, $t->lockedFor('someone-else', '192.0.2.21'));

        // A success resets the count
        $insert('throttled', '192.0.2.20', 5, 1);
        $this->assertSame(0, $t->lockedFor('throttled', null));

        // Failures spread over more than the window do not lock
        $t->window = 60;
        foreach ([800, 600, 400, 200, 3] as $ago) {
            $insert('slow', '192.0.2.22', $ago);
        }
        $this->assertSame(0, $t->lockedFor('slow', null));

        // Per-IP limit
        $t = new LoginThrottle();
        $t->ipMaxAttempts = 3;
        foreach ([30, 20, 10] as $i => $ago) {
            $insert("user$i", '192.0.2.23', $ago);
        }
        $this->assertGreaterThan(0, $t->lockedFor('fresh-user', '192.0.2.23'));

        $t->record('Recorded', '192.0.2.24', false, str_repeat('x', 300));
        $row = (new \yii\db\Query())->from('login_attempt')->where(['username' => 'recorded'])->one();
        $this->assertSame(255, strlen($row['user_agent']));
        $this->assertSame(1, $t->clear('RECORDED'));
    }

    // ---- Migration base class -----------------------------------------------------

    public function testMigrationHelpersBuildStandardColumnsAndLinks(): void
    {
        // Records the DDL calls instead of running them (DDL would commit the test transaction)
        $m = new class(['db' => Yii::$app->db, 'compact' => true]) extends Migration {
            public array $calls = [];
            public function createTable($table, $columns, $options = null) { $this->calls[] = ['createTable', $table, array_keys($columns), $options]; }
            public function createIndex($name, $table, $columns, $unique = false) { $this->calls[] = ['createIndex', $name]; }
            public function addForeignKey($name, $table, $columns, $refTable, $refColumns, $delete = null, $update = null) { $this->calls[] = ['addForeignKey', $name, $refTable, $delete]; }
            public function options(): ?string { return $this->tableOptions(); }
            public function audit(): array { return $this->auditColumns(); }
            public function archive(): array { return $this->archiveColumns(); }
            public function userKeys(string $t): void { $this->addUserForeignKeys($t, ['created_by', 'updated_by', 'archived_by']); }
            public function link(): void { $this->createLinkTable('risk_thing', 'risk_id', 'risk', 'thing_id', 'thing'); }
        };
        $this->assertStringContainsString('utf8mb4', $m->options());
        $this->assertSame(['created_at', 'updated_at', 'created_by', 'updated_by'], array_keys($m->audit()));
        $this->assertSame(['archived_at', 'archived_by'], array_keys($m->archive()));

        $m->userKeys('thing');
        $this->assertSame([['addForeignKey', 'fk_thing_created_by', 'user', 'SET NULL'], ['addForeignKey', 'fk_thing_updated_by', 'user', 'SET NULL'],
            ['addForeignKey', 'fk_thing_archived_by', 'user', 'SET NULL']], $m->calls);

        $m->calls = [];
        $m->link();
        $this->assertSame('createTable', $m->calls[0][0]);
        $this->assertSame(['risk_id', 'thing_id', 'created_at', 'created_by', 0], $m->calls[0][2]);
        $this->assertSame(['createIndex', 'idx_risk_thing_thing_id'], $m->calls[1]);
        $this->assertSame(['fk_risk_thing_risk_id', 'fk_risk_thing_thing_id', 'fk_risk_thing_created_by'], array_column(array_slice($m->calls, 2), 1));
    }

    // ---- HTTP client (loopback only) ------------------------------------------------

    private function startServer(): int
    {
        $this->router = Yii::getAlias('@runtime') . '/phpunit-http-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($this->router, '<?php
            $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
            if ($path === "/missing") { http_response_code(404); echo "nope"; return; }
            header("Content-Type: application/json");
            echo json_encode(["method" => $_SERVER["REQUEST_METHOD"], "body" => file_get_contents("php://input"), "ua" => $_SERVER["HTTP_USER_AGENT"] ?? "", "x" => $_SERVER["HTTP_X_TEST"] ?? null]);');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $this->server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $this->router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }
        return $port;
    }

    public function testHttpClientFetchesInParallelOverLoopback(): void
    {
        $this->assertNull(Http::$fake);
        $this->assertSame([], Http::fetchAll([]));
        $port = $this->startServer();
        $base = "http://127.0.0.1:$port";
        $closed = stream_socket_server('tcp://127.0.0.1:0');
        $closedPort = (int) substr(strrchr(stream_socket_get_name($closed, false), ':'), 1);
        fclose($closed);

        $r = Http::fetchAll([
            'get' => ['url' => "$base/ok", 'headers' => ['X-Test: yes']],
            'post' => ['url' => "$base/ok", 'method' => 'POST', 'body' => 'a=1', 'timeout' => 3],
            'missing' => ['url' => "$base/missing"],
            'refused' => ['url' => "http://127.0.0.1:$closedPort/"],
        ], 3);
        $this->assertSame(200, $r['get']['status']);
        $get = Http::json($r['get']);
        $this->assertSame(['GET', 'yes', Http::USER_AGENT], [$get['method'], $get['x'], $get['ua']]);
        $this->assertSame(['POST', 'a=1'], [Http::json($r['post'])['method'], Http::json($r['post'])['body']]);
        $this->assertSame([404, 'HTTP 404'], [$r['missing']['status'], Http::errorText($r['missing'])]);
        $this->assertNull(Http::json($r['missing']), 'not JSON');
        $this->assertSame(0, $r['refused']['status']);
        $this->assertNotEmpty(Http::errorText($r['refused']));

        $this->assertSame('No response', Http::errorText(null));
        $this->assertSame('Connection failed', Http::errorText(['status' => 0, 'error' => null]));
        $this->assertStringContainsString('check the API key', Http::errorText(['status' => 403]));
        $this->assertNull(Http::json(['status' => 200, 'body' => '']));
    }

    // ---- Helpers ------------------------------------------------------------------

    public function testBadgesDatesAndRatings(): void
    {
        $this->assertStringContainsString('N/A', Badge::compliance(null));
        $this->assertStringContainsString('shield-check', Badge::compliance(true));
        $this->assertStringContainsString('title="2 failing"', Badge::compliance(false, '2 failing'));
        $this->assertStringContainsString('Yes', Badge::bool(1));
        $this->assertStringContainsString('Off', Badge::bool(0, 'On', 'Off'));
        $this->assertStringContainsString('gw-null', Badge::intelRating(null), 'not assessed');
        $this->assertStringContainsString('87', Badge::intelRating('malicious', 87));
        $this->assertStringContainsString('mitigation-state', Badge::mitigationState('gap', 'No control'));

        $this->assertSame(date('Y-m-d'), Dt::today());
        $this->assertNull(Dt::daysFromToday(null));
        $this->assertSame(0, Dt::daysFromToday(date('Y-m-d H:i:s')));
        $this->assertSame(-3, Dt::daysFromToday(date('Y-m-d', strtotime('-3 days'))));
        $this->assertSame(10, Dt::daysFromToday(date('Y-m-d', strtotime('+10 days'))));

        $this->assertNull(RiskMatrix::rating(0));
        $this->assertSame('critical', RiskMatrix::rating(99), 'above the top band');
        $this->assertNull(RiskMatrix::ratingLabel(null));
        $this->assertSame('Extreme', RiskMatrix::ratingLabel('extreme'));
    }

    public function testActorAndAuditLogDetails(): void
    {
        $this->assertNull(Actor::id());
        $this->assertSame('console', Actor::name());
        $this->assertNull(Actor::ip());
        $this->assertNull(Actor::userAgent());
        $uid = $this->userId();
        Actor::override($uid, 'importer');
        $this->assertSame([$uid, 'importer'], [Actor::id(), Actor::name()]);

        AuditLog::record('lookup', 1, 'Location: X', 'update', ['is_active' => [1, 0], 'type' => ['location', 'location']]);
        $entry = AuditLog::find()->orderBy(['id' => SORT_DESC])->one();
        $this->assertSame('importer', $entry->username);
        $rows = array_column($entry->getFormattedChanges(), null, 'field');
        $this->assertSame(['Yes', 'No'], [$rows['Active']['old'] ?? null, $rows['Active']['new'] ?? null]);
        $this->assertSame($uid, $entry->user->id);

        $entry->changes = '{not json';
        $this->assertSame([], $entry->getChangesArray());
        $entry->entity_type = 'no-such-type';
        $this->assertNull($entry->getEntityUrl());
        $this->assertSame('No-such-type', $entry->getEntityLabelText());

        $lookup = new Lookup(['type' => 'location', 'name' => 'Site']);
        $this->assertSame('Locations', $lookup->formatAuditValue('type', 'location'));
        $this->assertSame('No', $lookup->formatAuditValue('is_active', 0));
        $this->assertSame('x', $lookup->formatAuditValue('name', 'x'));
        $this->assertNull(Lookup::nameOf(null));
        $this->assertSame('Locations: Site', $lookup->getAuditLabel());
    }
}
