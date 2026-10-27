<?php

/**
 * Cyber Essentials technical controls, paraphrased by theme from the NCSC
 * "Cyber Essentials: Requirements for IT infrastructure". Check the current
 * version of that document (and the IASME question set) before an assessment:
 * wording and thresholds change between releases. Loaded by FrameworkCatalogue.
 */
return [
    'framework' => 'cyber_essentials',
    'version' => 'Cyber Essentials: Requirements for IT infrastructure (paraphrased)',
    'groups' => [
        'FW' => 'Firewalls',
        'SC' => 'Secure configuration',
        'SU' => 'Security update management',
        'UA' => 'User access control',
        'MP' => 'Malware protection',
    ],
    'requirements' => [
        ['CE-FW-1', 'Every in-scope device is protected by a firewall', 'A correctly configured boundary firewall, or a host-based firewall, protects every device in scope.'],
        ['CE-FW-2', 'Firewall default passwords changed', 'Default administrative passwords on firewalls and routers are replaced with strong, unique ones.'],
        ['CE-FW-3', 'Firewall admin interface not exposed', 'The administrative interface is not reachable from the internet unless there is a documented need, and then it is protected by MFA or an IP allow list.'],
        ['CE-FW-4', 'Unauthenticated inbound connections blocked', 'Inbound connections are blocked by default.'],
        ['CE-FW-5', 'Inbound rules approved and documented', 'Each inbound rule is approved by an authorised person, with the business need documented.'],
        ['CE-FW-6', 'Unneeded firewall rules removed', 'Rules that are no longer needed are removed or disabled promptly.'],
        ['CE-FW-7', 'Software firewall on untrusted networks', 'Devices used on untrusted networks, such as public Wi-Fi, run a software firewall.'],
        ['CE-SC-1', 'Unnecessary accounts removed', 'Unnecessary user accounts, including guest and default accounts, are removed or disabled.'],
        ['CE-SC-2', 'Default and guessable passwords changed', 'Default or guessable account passwords are changed.'],
        ['CE-SC-3', 'Unnecessary software removed', 'Unnecessary software, including applications, utilities and network services, is removed or disabled.'],
        ['CE-SC-4', 'Auto-run disabled', 'Auto-run, or any feature that runs files without user authorisation, is disabled.'],
        ['CE-SC-5', 'Users authenticated before access', 'Users are authenticated before they can access organisational data or services.'],
        ['CE-SC-6', 'Device unlocking', 'Devices are unlocked with a biometric, a password or a PIN, and repeated guessing is throttled or locks the device.'],
        ['CE-SU-1', 'Software licensed and supported', 'All software on in-scope devices is licensed and supported by its vendor.'],
        ['CE-SU-2', 'Unsupported software removed', 'Software that no longer receives security updates is removed, or the device is moved out of scope.'],
        ['CE-SU-3', 'Automatic updates enabled', 'Automatic updates are enabled where possible.'],
        ['CE-SU-4', 'High and critical updates applied within 14 days', 'Updates the vendor rates critical or high risk, or that fix vulnerabilities with a CVSS v3 score of 7 or more, are applied within 14 days of release.'],
        ['CE-UA-1', 'Account creation and approval process', 'There is a process to create and approve user accounts.'],
        ['CE-UA-2', 'Unique credentials', 'Users are authenticated with unique credentials before they get access to applications or devices.'],
        ['CE-UA-3', 'Unneeded accounts removed promptly', 'Accounts are removed or disabled when they are no longer required, e.g. when someone leaves.'],
        ['CE-UA-4', 'MFA where available', 'Multi-factor authentication is used wherever it is available, and always for cloud services and administrative accounts.'],
        ['CE-UA-5', 'Separate administrative accounts', 'Administrative tasks use separate accounts that are not used for email or web browsing.'],
        ['CE-UA-6', 'Special access privileges removed', 'Administrative and other special access privileges are removed or disabled when they are no longer required.'],
        ['CE-UA-7', 'Password-based authentication protected', 'Password-based authentication is protected against brute force and enforces the minimum length requirements.'],
        ['CE-MP-1', 'Malware protection on every in-scope device', 'Anti-malware software or application allow-listing is in place on every in-scope device that supports it.'],
        ['CE-MP-2', 'Anti-malware kept up to date and active', 'Anti-malware is kept up to date, scans files on access, and blocks access to malicious websites.'],
        ['CE-MP-3', 'Application allow-listing', 'Where allow-listing is used, only approved, code-signed applications can run.'],
    ],
];
