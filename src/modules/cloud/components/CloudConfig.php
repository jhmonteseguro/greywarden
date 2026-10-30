<?php

namespace app\modules\cloud\components;

/**
 * The configuration an inventory export carries for an asset or an account,
 * read per provider (only AWS so far, see AwsConfig): the provider's type
 * label, the settings to show, the asset fields they answer and the findings.
 */
final class CloudConfig
{
    /** severity => label, worst first */
    public const SEVERITIES = ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];

    /** Provider type of the row holding an account's own settings */
    public const ACCOUNT_TYPE = 'account';

    public static function typeLabel(?string $provider, ?string $providerType): ?string
    {
        return match ($provider) {
            'aws' => AwsConfig::typeLabel($providerType),
            default => $providerType !== null && $providerType !== '' ? $providerType : null,
        };
    }

    /** @return list<array{0:string, 1:string}> [label, value] */
    public static function facts(?string $provider, array $properties): array
    {
        return match ($provider) {
            'aws' => AwsConfig::facts($properties),
            default => array_map(static fn($k, $v) => [ucfirst(str_replace('_', ' ', (string) $k)), is_scalar($v) ? (string) $v : json_encode($v)],
                array_keys($properties), $properties),
        };
    }

    /** @return array attributes the settings answer (null values say nothing) */
    public static function derive(?string $provider, string $providerType, array $properties, ?string $publicIp = null): array
    {
        return $provider === 'aws' ? AwsConfig::derive($providerType, $properties, $publicIp) : [];
    }

    /**
     * Findings, worst first
     * @param string $account the account identifier (to tell cross-account access)
     * @return list<array{severity:string, title:string}>
     */
    public static function findings(?string $provider, string $providerType, array $properties, ?string $today = null, string $account = ''): array
    {
        $findings = $provider === 'aws' ? AwsConfig::findings($providerType, $properties, $today ?? date('Y-m-d'), $account) : [];
        $rank = array_flip(array_keys(self::SEVERITIES));
        usort($findings, static fn($a, $b) => ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9));
        return $findings;
    }

    /** The worst severity among the findings, or null */
    public static function worst(array $findings): ?string
    {
        foreach (array_keys(self::SEVERITIES) as $severity) {
            if (in_array($severity, array_column($findings, 'severity'), true)) {
                return $severity;
            }
        }
        return null;
    }

    /** @return string[] severities at least as bad as $severity */
    public static function atLeast(string $severity): array
    {
        $keys = array_keys(self::SEVERITIES);
        $at = array_search($severity, $keys, true);
        return $at === false ? [] : array_slice($keys, 0, $at + 1);
    }
}
