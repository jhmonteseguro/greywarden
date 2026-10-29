<?php

namespace app\modules\intel\components\edge;

/**
 * What edge traffic analysis looks for: paths that scanners probe, scanner user
 * agents and attack payloads. Path patterns are case-insensitive substrings of
 * the request path; the `like` lists are what the Cloudflare query filters on
 * (its `like` is case-sensitive, so common spellings are listed).
 */
final class EdgeCatalogue
{
    /**
     * Probe path groups. `exposure`: a 2xx answer may mean the file is really
     * served (secrets, backups, debug pages), so it is fetched even when it succeeds.
     */
    public const PROBE_GROUPS = [
        'secrets' => [
            'label' => 'Secret, config and backup files',
            'exposure' => true,
            'paths' => [
                '/.env', '/.git/', '/.svn/', '/.hg/', '/.aws/', '/.ssh/', '/.ds_store', '/.npmrc', '/.htpasswd', '/.bash_history',
                '/.vscode/sftp.json', '/sftp-config.json', '/.docker/', '/docker-compose.yml', '/config.json', '/appsettings.json',
                '/web.config', '/wp-config.php.', '/id_rsa', '/backup.sql', '/dump.sql', '/database.sql', '/db.sql', '/backup.zip', '/backup.tar.gz',
                '/phpinfo.php', '/info.php', '/server-status', '/.well-known/../', '/debug/default/view', '/_profiler', '/telescope', '/_ignition',
            ],
        ],
        'shells' => [
            'label' => 'Web shells and uploaders',
            'exposure' => true,
            'paths' => ['/shell.php', '/cmd.php', '/wso.php', '/alfa.php', '/c99.php', '/r57.php', '/up.php', '/upload.php', '/uploader.php', '/xleet', '/wp-content/uploads/shell'],
        ],
        'wordpress' => [
            'label' => 'WordPress',
            'exposure' => false,
            'paths' => ['/wp-login.php', '/wp-admin', '/wp-content/', '/wp-includes/', '/xmlrpc.php', '/wp-json/', '/wordpress/'],
        ],
        'dbadmin' => [
            'label' => 'Database admin tools',
            'exposure' => false,
            'paths' => ['/phpmyadmin', '/pma/', '/myadmin', '/adminer', '/mysqladmin', '/dbadmin'],
        ],
        'exploits' => [
            'label' => 'Known exploit targets',
            'exposure' => false,
            'paths' => [
                '/vendor/phpunit', '/eval-stdin.php', '/cgi-bin/', '/boaform', '/hnap1', '/actuator', '/solr/', '/manager/html', '/jenkins', '/owa/auth',
                '/autodiscover/', '/remote/login', '/geoserver', '/druid/', '/containers/json', '/setup.cgi', '/hudson', '/console/login', '/global-protect',
                '/sslvpn', '/vpn/', '/api/v1/pods', '/_cat/indices', '/storage/logs/laravel.log',
            ],
        ],
    ];

    /** Offensive scanners and exploit tools */
    public const SCANNER_AGENTS = '/\b(sqlmap|nikto|nuclei|zgrab|masscan|nmap|wpscan|dirbuster|gobuster|dirb|ffuf|feroxbuster|wfuzz|acunetix|nessus|openvas|netsparker|qualys|burp|hydra|jaeles|whatweb|xray|fuzz faster|commix|joomscan|droopescan|arachni|w3af|skipfish|appscan|httpx|katana)\b/i';

    /** Internet survey scanners (mostly noise, rarely worth blocking) */
    public const RESEARCH_AGENTS = '/\b(censys|shodan|internet-measurement|expanse|paloaltonetworks|binaryedge|leakix|onyphe|netcraft|criminalip|internetdb|stretchoid|modat|zoomeye)/i';

    /** HTTP libraries and command-line clients, not browsers */
    public const LIBRARY_AGENTS = '/^(python-requests|python-urllib|python-httpx|aiohttp|curl|wget|go-http-client|libwww-perl|java\/|okhttp|axios|node-fetch|undici|scrapy|ruby|php\/|guzzlehttp|winhttp|powershell|httpie|apache-httpclient|fasthttp|reqwest|mechanize)/i';

    /** Unusual HTTP methods (WebDAV, debugging) */
    public const UNUSUAL_METHODS = ['PROPFIND', 'PROPPATCH', 'MKCOL', 'COPY', 'MOVE', 'LOCK', 'UNLOCK', 'TRACE', 'TRACK', 'CONNECT', 'DEBUG', 'SEARCH'];

    /** Attack payload families: label => case-insensitive substrings of the url-decoded path + query */
    public const PAYLOADS = [
        'SQL injection' => ['union select', 'union all select', "' or '1'='1", '" or "1"="1', ' or 1=1', 'sleep(', 'benchmark(', 'information_schema', 'waitfor delay', 'extractvalue('],
        'Path traversal' => ['../', '..\\', '/etc/passwd', 'win.ini', 'boot.ini', '/proc/self/'],
        'Cross-site scripting' => ['<script', 'javascript:', 'onerror=', 'onload=', '<svg', 'alert('],
        'Code / command injection' => ['${jndi:', '${${', ';wget ', '|wget ', '; curl ', '|curl ', '/bin/sh', '/bin/bash', 'cmd.exe', 'powershell', 'base64_decode(', 'eval(', 'system(', 'passthru(', 'shell_exec(', 'allow_url_include', 'auto_prepend_file', 'php://input', 'phpinfo()', 'die(md5('],
        'Server-side request forgery' => ['169.254.169.254', 'metadata.google.internal', 'file://', 'gopher://', 'dict://'],
    ];

    /** Query-string `like` filters for the payload query (raw, still url-encoded) */
    public const PAYLOAD_LIKES = [
        '%union%select%', '%UNION%SELECT%', '%sleep(%', '%sleep%28%', '%information_schema%', '%../%', '%..%2f%', '%..%2F%', '%%2e%2e%', '%%2E%2E%', '%etc/passwd%', '%etc%2fpasswd%', '%etc%2Fpasswd%',
        '%<script%', '%3cscript%', '%3Cscript%', '%${jndi%', '%24%7bjndi%', '%24%7Bjndi%', '%169.254.169.254%', '%php://%', '%php%3a%2f%2f%', '%base64_decode%', '%allow_url_include%', '%/bin/sh%', '%2fbin%2fsh%',
    ];

    /** Request groups: path => group key, or null when the path is not a known probe */
    public static function probeGroup(string $path): ?string
    {
        $lower = strtolower($path);
        foreach (self::PROBE_GROUPS as $key => $group) {
            foreach ($group['paths'] as $needle) {
                if (str_contains($lower, $needle)) {
                    return $key;
                }
            }
        }
        return null;
    }

    public static function groupLabel(string $group): string
    {
        return self::PROBE_GROUPS[$group]['label'] ?? $group;
    }

    public static function isExposureGroup(?string $group): bool
    {
        return $group !== null && (self::PROBE_GROUPS[$group]['exposure'] ?? false);
    }

    /** `like` filters for probe paths that matter even when they succeed (exposure groups), in common spellings */
    public static function exposureLikes(): array
    {
        $likes = [];
        foreach (self::PROBE_GROUPS as $group) {
            if (!$group['exposure']) {
                continue;
            }
            foreach ($group['paths'] as $path) {
                foreach (array_unique([$path, strtoupper($path), str_replace('.ds_store', '.DS_Store', $path)]) as $spelling) {
                    $likes[] = '%' . $spelling . '%';
                }
            }
        }
        return array_values(array_unique($likes));
    }

    /**
     * The kind of client a user agent belongs to.
     * @return array{kind: string, name: string}|null scanner | research | library | empty; null for browsers and the rest
     */
    public static function agentKind(string $agent): ?array
    {
        $agent = trim($agent);
        if ($agent === '' || $agent === '-') {
            return ['kind' => 'empty', 'name' => 'no user agent'];
        }
        foreach (['scanner' => self::SCANNER_AGENTS, 'research' => self::RESEARCH_AGENTS, 'library' => self::LIBRARY_AGENTS] as $kind => $pattern) {
            if (preg_match($pattern, $agent, $m)) {
                return ['kind' => $kind, 'name' => strtolower(rtrim($m[1] ?? $m[0], '/'))];
            }
        }
        return null;
    }

    /** @return string[] payload families found in a path / query string */
    public static function payloadFamilies(string $request): array
    {
        $decoded = strtolower(rawurldecode(rawurldecode(str_replace('+', ' ', $request))));
        $found = [];
        foreach (self::PAYLOADS as $family => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($decoded, $needle)) {
                    $found[] = $family;
                    break;
                }
            }
        }
        return $found;
    }

    /**
     * Cloudflare rule-language condition that blocks a probe group by path, for
     * the suggested WAF rule. Only `contains` and `eq`, which every plan supports.
     */
    public static function ruleCondition(string $group, array $ignoredPrefixes = []): ?string
    {
        $parts = [];
        foreach (self::PROBE_GROUPS[$group]['paths'] ?? [] as $path) {
            if (EdgeSettings::isIgnoredPath($path, $ignoredPrefixes) || str_contains($path, '..')) {
                continue;
            }
            $parts[] = sprintf('(lower(http.request.uri.path) contains "%s")', addcslashes($path, '"\\'));
        }
        return $parts ? implode(' or ', $parts) : null;
    }
}
