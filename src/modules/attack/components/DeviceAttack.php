<?php

namespace app\modules\attack\components;

/**
 * ATT&CK view of a device: which Enterprise platforms it falls under. The
 * mitigations its device checks implement come from the checks' controls.
 */
final class DeviceAttack
{
    /** Cloud providers whose VMs also sit under the IaaS platform */
    private const CLOUD_VENDORS = ['amazon', 'aws', 'azure', 'microsoft azure', 'google', 'gcp', 'oracle cloud'];

    /**
     * Enterprise ATT&CK platforms for a device, e.g. ['Windows'] or ['Linux', 'IaaS'].
     * Mobile devices are covered by the separate Mobile ATT&CK matrix and return [].
     *
     * @return string[]
     */
    public static function platforms(string $deviceType, ?string $os, ?string $manufacturer = null): array
    {
        $os = strtolower((string) $os);
        $platforms = [];
        if (in_array($deviceType, ['network', 'firewall'], true)) {
            $platforms[] = 'Network Devices';
        } elseif (in_array($deviceType, ['mobile', 'tablet'], true) || preg_match('/\b(ios|ipados|android)\b/', $os)) {
            return [];
        } elseif (str_contains($os, 'windows')) {
            $platforms[] = 'Windows';
        } elseif (preg_match('/mac ?os|os x/', $os)) {
            $platforms[] = 'macOS';
        } elseif (str_contains($os, 'esxi')) {
            $platforms[] = 'ESXi';
        } elseif (preg_match('/linux|ubuntu|debian|red hat|rhel|centos|fedora|suse|alma|rocky/', $os)) {
            $platforms[] = 'Linux';
        }
        if ($deviceType === 'vm' && in_array(strtolower(trim((string) $manufacturer)), self::CLOUD_VENDORS, true)) {
            $platforms[] = 'IaaS';
        }
        return $platforms;
    }

    /** Explanation shown when platforms() is empty */
    public static function noPlatformReason(string $deviceType): string
    {
        return in_array($deviceType, ['mobile', 'tablet'], true)
            ? 'Mobile devices are covered by the separate Mobile ATT&CK matrix.'
            : 'No Enterprise ATT&CK platform matches this device type / OS.';
    }
}
