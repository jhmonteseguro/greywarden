<?php

namespace app\modules\cloud\components;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A provider's resource inventory export (see AwsExport, AzureExport,
 * GcpExport), as the CLI's JSON or the console's CSV download: the format is
 * detected from the content. CSV headers are matched loosely (case, spaces,
 * dashes and underscores are ignored), so "Resource type", "resourceType"
 * and "resource_type" are the same column.
 *
 * Each resource becomes a record for CloudImporter:
 *   line             CSV line, or position in the JSON list
 *   provider_type    the provider's own type, e.g. "ec2:instance"
 *   account          account identifier (AWS account ID, subscription ID, project ID)
 *   account_aliases  other identifiers of the same account (a Google Cloud project number)
 *   account_name     the account's name, when the export has it
 *   attributes       CloudAsset attributes the source owns (name, resource_id, source_id, region, seen times, OS, public IPs)
 *   create           attributes set only when the asset is created
 *   state            the provider's state for the resource, if any (shown in the summary)
 *   error            why the row cannot be imported (the rest is then empty)
 */
abstract class CloudExport
{
    abstract public static function provider(): string;

    /** Short description of the formats with its article ("an AWS ... export"), for error messages */
    abstract protected static function formats(): string;

    /**
     * @param mixed $json the decoded JSON document
     * @return list<array> raw items, each with a 'line' key
     */
    abstract protected function jsonItems(mixed $json): array;

    /** @param array<string,mixed> $row CSV row keyed by normalised header */
    abstract protected function csvItem(array $row): array;

    /** @return string[] normalised CSV headers that must be present (any one of each group) */
    abstract protected function requiredColumns(): array;

    /** @return array a record (see the class comment) */
    abstract protected function record(array $item): array;

    public static function for(string $provider): self
    {
        return match ($provider) {
            'aws' => new AwsExport(),
            'azure' => new AzureExport(),
            'gcp' => new GcpExport(),
            default => throw new InvalidArgumentException("Unknown provider '{$provider}': use aws, azure or gcp."),
        };
    }

    /** @return string[] providers with an export parser */
    public static function providers(): array
    {
        return ['aws', 'azure', 'gcp'];
    }

    /**
     * @return list<array> records (see the class comment)
     * @throws InvalidArgumentException when the content is not a supported export
     */
    public function parse(string $content): array
    {
        $content = trim(preg_replace('/^\xEF\xBB\xBF/', '', $content)); // UTF-8 BOM
        if ($content === '') {
            throw new InvalidArgumentException('The file is empty.');
        }
        if ($content[0] === '{' || $content[0] === '[') {
            $json = json_decode($content, true);
            if (!is_array($json)) {
                throw new InvalidArgumentException('The file is not valid JSON (' . json_last_error_msg() . ').');
            }
            $items = $this->jsonItems($json);
        } else {
            $items = $this->csvItems($content);
        }
        $items = $this->prepare($items);
        return array_map(fn(array $item) => ['line' => $item['line']] + $this->record($item) + [
            'provider' => static::provider(), 'account_aliases' => [], 'account_name' => null, 'create' => [], 'state' => null, 'error' => null,
        ], $items);
    }

    /** Hook to look across all items before records are built (Google Cloud pairs project numbers with IDs) */
    protected function prepare(array $items): array
    {
        return $items;
    }

    /** @return list<array> */
    private function csvItems(string $content): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $header = array_map([self::class, 'key'], fgetcsv($handle, null, ',', '"', '') ?: []);
        foreach ($this->requiredColumns() as $group) {
            if (!array_intersect((array) $group, $header)) {
                fclose($handle);
                throw new InvalidArgumentException(sprintf('Not %s: no %s column.', static::formats(), implode(' / ', (array) $group)));
            }
        }
        $items = [];
        $line = 1;
        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;
            if ($cells === [null] || implode('', $cells) === '') {
                continue;
            }
            $row = [];
            foreach ($header as $i => $key) {
                if ($key !== '' && !isset($row[$key])) {
                    $row[$key] = trim((string) ($cells[$i] ?? ''));
                }
            }
            $items[] = ['line' => $line] + $this->csvItem($row);
        }
        fclose($handle);
        return $items;
    }

    /** Normalised CSV header / lookup key: "Resource type" => "resourcetype" */
    public static function key(string $label): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($label));
    }

    /** First non-empty value among the keys */
    protected static function pick(array $row, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }
        return null;
    }

    /** Value at a dotted path in nested arrays, e.g. "properties.ipAddress" */
    protected static function path(mixed $data, string $path): mixed
    {
        foreach (explode('.', $path) as $part) {
            if (!is_array($data) || !array_key_exists($part, $data)) {
                return null;
            }
            $data = $data[$part];
        }
        return $data;
    }

    /** A JSON object kept as text in a CSV cell (Azure "properties"), or the array itself */
    protected static function object(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    /** ISO 8601 timestamp => "Y-m-d H:i:s" in $tz (default: PHP's timezone); null if missing or unreadable */
    public static function time(?string $value, ?DateTimeZone $tz = null): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            $time = new DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return null;
        }
        return $time->setTimezone($tz ?? new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    /** Asset names are at most 128 characters */
    protected static function name(string $name): string
    {
        return mb_strimwidth($name, 0, 128, '…');
    }

    /** A record for a row that cannot be imported */
    protected static function failed(string $providerType, string $error): array
    {
        return ['provider_type' => $providerType, 'account' => null, 'attributes' => [], 'error' => $error];
    }
}
