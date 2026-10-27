# MITRE ATT&CK data

`enterprise-attack.json.gz` is a compact snapshot of **MITRE ATT&CK® Enterprise** (tactics, techniques,
sub-techniques, mitigations and the mitigation → technique mappings). It was generated from the official STIX 2.1
bundle at <https://github.com/mitre-attack/attack-stix-data> by `app\modules\attack\components\StixParser`.
Citations are stripped from the descriptions, and markdown links are reduced to their text.

The migration `m261019_000002_import_attack_catalogue` loads it. To move to a newer ATT&CK release:

```bash
make yii ARGS="attack/update"          # download the latest release, rewrite this snapshot, import it
make yii ARGS="attack/status"          # show the loaded version
```

Commit the refreshed snapshot so fresh installs get the same version.

## Licence

© The MITRE Corporation. This work is reproduced and distributed with the permission of The MITRE Corporation.
MITRE ATT&CK and ATT&CK are registered trademarks of The MITRE Corporation.
See the ATT&CK Terms of Use: <https://attack.mitre.org/resources/legal-and-branding/terms-of-use/>
