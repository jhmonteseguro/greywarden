<?php

/**
 * NCSC Cyber Assessment Framework (CAF) 4.0: the 41 contributing outcomes
 * across 14 principles and four objectives (A managing security risk,
 * B protecting against cyber attack, C detecting cyber security events,
 * D minimising the impact of incidents). Each outcome's title and statement
 * are taken from the CAF 4.0 document (August 2025); A3.a and C2.a, the only
 * outcome under their principle, carry the principle's statement. The
 * indicators of good practice are not included: assess against them in the
 * document. Loaded by FrameworkCatalogue.
 *
 * Contains public sector information licensed under the Open Government
 * Licence v3.0 (© Crown copyright 2025, National Cyber Security Centre).
 */
return [
    'framework' => 'ncsc_caf',
    'version' => 'NCSC Cyber Assessment Framework 4.0 (August 2025)',
    'groups' => [
        'A1' => 'A1 Governance',
        'A2' => 'A2 Risk Management',
        'A3' => 'A3 Asset Management',
        'A4' => 'A4 Supply Chain',
        'B1' => 'B1 Service Protection Policies, Processes and Procedures',
        'B2' => 'B2 Identity and Access Control',
        'B3' => 'B3 Data Security',
        'B4' => 'B4 System Security',
        'B5' => 'B5 Resilient Networks and Systems',
        'B6' => 'B6 Staff Awareness and Training',
        'C1' => 'C1 Security Monitoring',
        'C2' => 'C2 Threat Hunting',
        'D1' => 'D1 Response and Recovery Planning',
        'D2' => 'D2 Lessons Learned',
    ],
    'requirements' => [
        ['A1.a', 'Board Direction', 'You have effective organisational security management led at board level and articulated clearly in corresponding policies.'],
        ['A1.b', 'Roles and Responsibilities', 'Your organisation has established roles and responsibilities for the security of network and information systems at all levels, with clear and well-understood channels for communicating and escalating risks.'],
        ['A1.c', 'Decision-making', 'You have senior-level accountability for the security of network and information systems, and delegate decision-making authority appropriately and effectively. Risks to network and information systems related to the operation of your essential function(s) are considered in the context of other organisational risks.'],
        ['A2.a', 'Risk Management Process', 'Your organisation has effective internal processes for managing risks to the security and resilience of network and information systems related to the operation of your essential function(s) and communicating associated activities.'],
        ['A2.b', 'Understanding Threat', 'You understand the capabilities, methods and techniques of threat actors and what network and information systems they may compromise to adversely impact your essential function(s). This information is used to inform security and resilience risk management decisions, adjusting, enhancing or adding security measures to better defend against threats.'],
        ['A2.c', 'Assurance', 'You have gained confidence in the effectiveness of the security of your technology, people, and processes relevant to the operation of network and information systems supporting your essential function(s).'],
        ['A3.a', 'Asset Management', 'Everything required to deliver, maintain or support network and information systems necessary for the operation of essential functions is determined and understood. This includes data, people and systems, as well as any supporting infrastructure (such as power or cooling).'],
        ['A4.a', 'Supply Chain', 'You understand and effectively manage the risks associated with suppliers to the security of network and information systems supporting the operation of your essential function(s).'],
        ['A4.b', 'Secure Software Development and Support', 'You actively maximise the use of secure and supported software, whether developed internally or sourced externally, within network and information systems supporting the operation of your essential function(s).'],
        ['B1.a', 'Policy, Process and Procedure Development', 'You have developed and continue to improve a set of cyber security and resilience policies, processes and procedures that manage and mitigate the risk of adverse impact to network and information systems supporting your essential function(s).'],
        ['B1.b', 'Policy, Process and Procedure Implementation', 'You have successfully implemented your security policies, processes and procedures and can demonstrate the security benefits achieved.'],
        ['B2.a', 'Identity Verification, Authentication and Authorisation', 'You robustly verify, authenticate and authorise access to network and information systems supporting your essential function(s).'],
        ['B2.b', 'Device Management', 'You fully know and have trust in the devices that are used to access your networks, information systems and data that support your essential function(s).'],
        ['B2.c', 'Privileged User Management', 'You closely manage privileged user access to network and information systems supporting your essential function(s).'],
        ['B2.d', 'Identity and Access Management (IdAM)', 'You closely manage and maintain identity and access control for users, devices and systems accessing network and information systems supporting your essential function(s).'],
        ['B3.a', 'Understanding Data', 'You have a good understanding of data important to the operation of network and information systems supporting your essential function(s), where it is stored, where it travels and how unavailability or unauthorised access, uncontrolled release, modification or deletion would adversely impact the essential function(s). This also applies to third parties storing or accessing data important to the operation of your essential function(s).'],
        ['B3.b', 'Data in Transit', 'You have protected the transit of data important to the operation of network and information systems supporting your essential function(s). This includes the transfer of data to third parties.'],
        ['B3.c', 'Stored Data', 'You have protected stored soft and hard copy data important to the operation of network and information systems supporting your essential function(s).'],
        ['B3.d', 'Mobile Data', 'You have protected data important to the operation of network and information systems supporting your essential function(s) on mobile devices (e.g. smartphones, tablets and laptops).'],
        ['B3.e', 'Media / Equipment Sanitisation', 'Before reuse and / or disposal you appropriately sanitise devices, equipment and removable media holding data important to the operation of network and information systems supporting your essential function(s).'],
        ['B4.a', 'Secure by Design', 'You design security into network and information systems that support the operation of your essential function(s). You minimise their attack surface and ensure that the operation of your essential function(s) should not be impacted by the exploitation of any single vulnerability.'],
        ['B4.b', 'Secure Configuration', 'You securely configure network and information systems that support the operation of your essential function(s).'],
        ['B4.c', 'Secure Management', 'You manage your organisation\'s network and information systems that support the operation of your essential function(s) to enable and maintain security.'],
        ['B4.d', 'Vulnerability Management', 'You manage known vulnerabilities in network and information systems to prevent adverse impact on your essential function(s).'],
        ['B5.a', 'Resilience Preparation', 'You are prepared to restore the operation of your essential function(s) following adverse impact to network and information systems.'],
        ['B5.b', 'Design for Resilience', 'You design network and information systems supporting your essential function(s) to be resilient to cyber security incidents. Systems are appropriately segregated and resource limitations are mitigated.'],
        ['B5.c', 'Backups', 'You hold accessible and secured current backups of data and information needed to recover operation of your essential function(s) following an adverse impact to network and information systems.'],
        ['B6.a', 'Cyber Security Culture', 'You develop and maintain a positive cyber security culture and a shared sense of responsibility.'],
        ['B6.b', 'Cyber Security Training', 'The people who support the operation of network and information systems supporting your essential function(s) are appropriately trained in cyber security.'],
        ['C1.a', 'Sources and Tools for Logging and Monitoring', 'The data sources and tools that you include in your logging and monitoring allow for timely identification of events which might adversely affect the security or resiliency of network and information system(s) supporting the operation of your essential function(s).'],
        ['C1.b', 'Securing Logs', 'You hold log data securely and grant appropriate user and system access only to accounts with a business need. Log data is held for a suitable retention period, after which it is deleted.'],
        ['C1.c', 'Generating Alerts', 'Evidence of potential security incidents contained in your monitoring data is reliably identified and where appropriate triggers alerts.'],
        ['C1.d', 'Triage of Security Alerts', 'You contextualise alerts with knowledge of the threat and your systems, to identify security incidents as well as responding to all alerts appropriately.'],
        ['C1.e', 'Personnel Skills for Monitoring and Detection', 'Monitoring and detection personnel skills and roles, including those outsourced, reflect governance and reporting requirements, expected threats and the complexities of the network or system data they need to use. Monitoring and detection personnel have sufficient knowledge of network and information systems and the essential function(s) they need to protect.'],
        ['C1.f', 'Understanding User\'s and System\'s Behaviour, and Threat Intelligence (within Security Monitoring)', 'Threats to the operation of network and information systems, and corresponding user and system behaviour, are sufficiently understood. These are used to detect cyber security incidents.'],
        ['C2.a', 'Threat Hunting', 'The organisation proactively seeks to detect, within networks and information systems, adverse activity affecting, or with the potential to affect, the operation of essential functions even when the activity evades standard security prevent / detect solutions (or when standard solutions are not deployable).'],
        ['D1.a', 'Response Plan', 'You have an up-to-date incident response plan that is grounded in a thorough risk assessment that takes account of network and information systems supporting the operation of your essential function(s) and covers a range of incident scenarios.'],
        ['D1.b', 'Response and Recovery Capability', 'You have the capability to enact your incident response plan, including effective limitation of impact on the operation of your essential function(s). During an incident, you have access to timely information on which to base your response decisions.'],
        ['D1.c', 'Testing and Exercising', 'Your organisation carries out exercises to test response plans, using past incidents that affected your (and other) organisation, and scenarios that draw on threat intelligence and your risk assessment.'],
        ['D2.a', 'Post Incident Analysis', 'When an incident occurs, your organisation takes steps to understand its causes, informing appropriate remediating action.'],
        ['D2.b', 'Using Incidents to Drive Improvements', 'Your organisation uses lessons learned from incidents to improve your security measures.'],
    ],
];
