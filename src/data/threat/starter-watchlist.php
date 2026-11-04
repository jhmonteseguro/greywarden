<?php

/**
 * The watchlist a new organisation starts with: common products in the places attackers
 * go first. category => terms. Analysts then add, switch off or remove terms on the
 * Watchlist page; terms from the organisation's own assets are added by Watchlist::sync().
 */
return [
    'network' => [
        'Fortinet', 'FortiGate', 'FortiOS', 'Palo Alto', 'PAN-OS', 'GlobalProtect', 'Cisco ASA', 'Cisco Secure Firewall', 'SonicWall', 'Ivanti',
        'Check Point', 'WatchGuard', 'Citrix', 'NetScaler', 'Juniper', 'F5 BIG-IP', 'OpenVPN', 'WireGuard',
    ],
    'identity' => [
        'Okta', 'Microsoft Entra', 'Azure AD', 'Active Directory', 'Duo', 'Ping Identity', 'OneLogin', 'Google Workspace',
    ],
    'email' => [
        'Microsoft Exchange', 'Outlook', 'Microsoft 365', 'Gmail', 'Proofpoint', 'Mimecast', 'Barracuda', 'Zimbra',
    ],
    'endpoint' => [
        'CrowdStrike', 'SentinelOne', 'Microsoft Defender', 'Sophos', 'Trend Micro', 'Bitdefender', 'Carbon Black', 'Cortex XDR',
    ],
    'platform' => [
        'Cloudflare', 'AWS', 'Amazon Web Services', 'Google Cloud', 'Microsoft Azure',
    ],
];
