<?php

namespace tests\support;

/**
 * Captures what console commands write to STDOUT / STDERR (Console::stdout()
 * writes to the raw streams, which output buffering cannot see).
 */
final class OutputCapture extends \php_user_filter
{
    public static string $buffer = '';
    private static array $filters = [];

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            self::$buffer .= $bucket->data;
            $consumed += $bucket->datalen;
        }
        return PSFS_PASS_ON;
    }

    public static function start(): void
    {
        if (!in_array('gw.capture', stream_get_filters(), true)) {
            stream_filter_register('gw.capture', self::class);
        }
        self::$buffer = '';
        self::$filters = [
            stream_filter_append(\STDOUT, 'gw.capture', STREAM_FILTER_WRITE),
            stream_filter_append(\STDERR, 'gw.capture', STREAM_FILTER_WRITE),
        ];
    }

    public static function stop(): string
    {
        foreach (self::$filters as $f) {
            stream_filter_remove($f);
        }
        self::$filters = [];
        return self::$buffer;
    }

    /**
     * Runs a console route with output captured.
     * @return array{0:int, 1:string} exit code and output
     */
    public static function run(string $route, array $params = []): array
    {
        self::start();
        try {
            $code = \Yii::$app->runAction($route, $params + ['interactive' => 0]);
        } finally {
            $out = self::stop();
        }
        return [(int) $code, $out];
    }
}
