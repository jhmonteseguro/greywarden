<?php

namespace tests;

use app\components\Actor;
use app\components\Rbac;
use app\models\User;
use app\modules\cloud\components\CloudChecks;
use app\modules\intel\components\Http;
use app\modules\intel\components\Network;
use app\modules\inventory\components\DeviceChecks;
use tests\support\OutputCapture;
use tests\support\TestSession;
use Yii;
use yii\helpers\ArrayHelper;
use yii\web\Application as WebApplication;
use yii\web\HttpException;
use yii\web\Response;

/**
 * Drives the web application in-process: every request() builds a fresh
 * yii\web\Application from config/web.php (sharing the test DB connection, so
 * the per-test transaction still rolls everything back), routes the URL through
 * the real UrlManager, filters (RBAC, verbs, password guard) and views, and
 * records the status code, body and redirect.
 *
 * Per class, one outer transaction holds a user per role (fn.admin, fn.analyst,
 * fn.viewer) and, when $seed is true, the demo data from `yii seed/demo`; each
 * test then runs in a savepoint inside it.
 */
abstract class FunctionalTestCase extends DbTestCase
{
    public const PASSWORD = 'Functional!Test-2026';

    /** Load the demo data (seed/demo) for this class */
    protected static bool $seed = false;

    /** @var array<string,int> role => user id */
    protected static array $users = [];
    private static ?array $config = null;
    private static array $serverBackup = [];

    private ?\yii\base\Application $consoleApp = null;
    protected ?WebApplication $app = null;
    protected int $status = 0;
    protected string $body = '';
    protected ?string $location = null;
    protected ?Response $response = null;
    protected ?\Throwable $exception = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$serverBackup = $_SERVER;
        DeviceChecks::reset();
        CloudChecks::reset();
        Yii::$app->db->beginTransaction();
        if (static::$seed) {
            [$code, $out] = OutputCapture::run('seed/demo');
            if ($code !== 0 || !str_contains($out, 'Seeded')) {
                Yii::$app->db->getTransaction()?->rollBack();
                self::fail("seed/demo failed (is greywarden_test free of domain data?):\n$out");
            }
        }
        self::$users = [];
        foreach ([Rbac::ROLE_ADMIN, Rbac::ROLE_ANALYST, Rbac::ROLE_VIEWER] as $role) {
            self::$users[$role] = User::createUser("fn.$role", "fn.$role@example.com", 'Functional ' . ucfirst($role), self::PASSWORD, $role, false)->id;
        }
    }

    public static function tearDownAfterClass(): void
    {
        while (($tx = Yii::$app->db->getTransaction()) && $tx->isActive) {
            $tx->rollBack();
        }
        Actor::override(null);
        DeviceChecks::reset();
        CloudChecks::reset();
        $_SERVER = self::$serverBackup;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->consoleApp = Yii::$app;
        $_SESSION = [];
        $_COOKIE = [];
        $_FILES = [];
        // No request may reach the network (intel sources, feeds, Cloudflare)
        Http::$fake = static fn(array $r) => ['status' => 404, 'body' => '{}', 'error' => '', 'time' => 0];
        Network::$fakeDns = static fn(string $name, string $type) => [];
        Network::$fakeWhois = static fn(string $server, string $query) => null;
    }

    protected function tearDown(): void
    {
        Yii::$app = $this->consoleApp;
        $this->app = null;
        $_SESSION = [];
        $_GET = $_POST = $_FILES = [];
        $_SERVER = self::$serverBackup;
        Http::$fake = null;
        Network::$fakeDns = null;
        Network::$fakeWhois = null;
        Actor::override(null);
        parent::tearDown();
    }

    // ---- Requests ---------------------------------------------------------------

    private static function config(): array
    {
        if (self::$config === null) {
            $web = require Yii::getAlias('@app/config/web.php');
            $web = ArrayHelper::merge($web, [
                'components' => [
                    'request' => [
                        'cookieValidationKey' => 'functional-tests',
                        'enableCsrfValidation' => false,
                        'scriptFile' => Yii::getAlias('@app/web/index.php'),
                        'scriptUrl' => '/index.php',
                        'baseUrl' => '',
                        'hostInfo' => 'http://localhost',
                    ],
                    'cache' => ['class' => \yii\caching\ArrayCache::class],
                    'assetManager' => ['bundles' => false],
                    'log' => ['targets' => []],
                ],
                'params' => ['attachments.path' => Yii::$app->params['attachments.path'], 'branding.path' => Yii::$app->params['branding.path']],
            ]);
            $web['components']['session'] = ['class' => TestSession::class];
            unset($web['components']['db']);
            self::$config = $web;
        }
        return self::$config;
    }

    /**
     * Sends a request; sets $status, $body, $location and $response.
     * @param array $post body params (POST)
     * @param array $files $_FILES-shaped array
     */
    protected function request(string $method, string $url, array $post = [], array $files = []): static
    {
        $parts = parse_url($url);
        $path = ltrim($parts['path'] ?? '/', '/');
        parse_str($parts['query'] ?? '', $get);

        $_SERVER = array_merge(self::$serverBackup, [
            'REQUEST_METHOD' => strtoupper($method),
            'REQUEST_URI' => $url,
            'QUERY_STRING' => $parts['query'] ?? '',
            'SCRIPT_NAME' => '/index.php',
            'PHP_SELF' => '/index.php',
            'SCRIPT_FILENAME' => Yii::getAlias('@app/web/index.php'),
            'HTTP_HOST' => 'localhost',
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'PHPUnit functional',
        ]);
        $_GET = $get;
        $_POST = strtoupper($method) === 'POST' ? $post : [];
        $_FILES = $files;
        \yii\web\UploadedFile::reset();

        // Fresh application per request, sharing the console app's DB connection (and its transaction)
        $config = self::config();
        $config['components']['db'] = $this->consoleApp->db;
        $this->app = new WebApplication($config);
        $request = $this->app->getRequest();
        $request->setPathInfo(rawurldecode($path));
        $request->setQueryParams($get);
        $request->setBodyParams($_POST);

        $this->exception = null;
        $this->location = null;
        try {
            $this->response = $this->app->handleRequest($request);
            $this->status = $this->response->statusCode;
            $this->location = $this->response->headers->get('location');
            $this->body = $this->responseBody($this->response);
        } catch (HttpException $e) {
            $this->exception = $e;
            $this->response = null;
            $this->status = $e->statusCode;
            $this->body = $e->getMessage();
        } catch (\yii\base\InvalidRouteException $e) {
            $this->exception = $e;
            $this->response = null;
            $this->status = 404;
            $this->body = $e->getMessage();
        }
        return $this;
    }

    private function responseBody(Response $response): string
    {
        if (is_array($response->stream)) {
            [$handle, $begin, $end] = $response->stream;
            fseek($handle, $begin);
            return (string) fread($handle, $end - $begin + 1);
        }
        if (is_resource($response->stream)) {
            return (string) stream_get_contents($response->stream);
        }
        return is_string($response->data) ? $response->data : (string) $response->content;
    }

    protected function get(string $url): static
    {
        return $this->request('GET', $url);
    }

    protected function post(string $url, array $data = [], array $files = []): static
    {
        return $this->request('POST', $url, $data, $files);
    }

    /** Signs in as one of the class users (admin / analyst / viewer) without a request */
    protected function loginAs(string $role): User
    {
        $user = User::findOne(self::$users[$role]);
        $_SESSION = ['__id' => $user->id, '__authKey' => $user->auth_key];
        return $user;
    }

    protected function logout(): void
    {
        $_SESSION = [];
    }

    protected function userIdFor(string $role): int
    {
        return self::$users[$role];
    }

    /** Flash message set by the last request (kept in the session) */
    protected function flash(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;
        return is_array($value) ? implode(' ', $value) : $value;
    }

    // ---- Assertions -------------------------------------------------------------

    protected function assertStatus(int $expected, string $message = ''): void
    {
        $detail = $this->exception ? ' (' . get_class($this->exception) . ': ' . $this->exception->getMessage() . ')' : '';
        $this->assertSame($expected, $this->status, trim($message . " got {$this->status}$detail"));
    }

    protected function assertOk(): void
    {
        $this->assertStatus(200);
    }

    protected function assertRedirect(?string $contains = null): void
    {
        $this->assertStatus(302);
        if ($contains !== null) {
            $this->assertStringContainsString($contains, (string) $this->location);
        }
    }

    protected function assertSee(string $text): void
    {
        // A short message instead of PHPUnit dumping the whole page
        $this->assertTrue(str_contains($this->body, $text), "Response ({$this->status}) does not contain \"$text\"");
    }

    protected function assertNotSee(string $text): void
    {
        $this->assertFalse(str_contains($this->body, $text), "Response ({$this->status}) unexpectedly contains \"$text\"");
    }
}
