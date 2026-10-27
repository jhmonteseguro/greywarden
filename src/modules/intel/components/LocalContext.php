<?php

namespace app\modules\intel\components;

use app\modules\incident\models\Incident;
use app\modules\intel\models\BlocklistEntry;
use app\modules\inventory\models\Device;
use yii\db\Query;

/**
 * What Greywarden itself knows about an indicator: blocklist entries covering
 * it, own devices with the address, and incidents that mention it.
 */
final class LocalContext
{
    /** @var BlocklistEntry[] */
    public array $blocks = [];
    /** @var Device[] */
    public array $devices = [];
    /** @var Incident[] */
    public array $incidents = [];

    public static function for(Indicator $indicator, array $facts): self
    {
        $c = new self();
        $asn = $indicator->isAsn() ? $indicator->asn : (isset($facts['asn']) ? (int) $facts['asn'] : null);
        $c->blocks = $indicator->isIp() ? BlocklistEntry::findForIp($indicator->value, $asn) : BlocklistEntry::findForAsn($indicator->asn);

        if ($indicator->isIp()) {
            $c->devices = array_values(array_filter(
                Device::find()->where(['like', 'ip_addresses', $indicator->value])->orderBy(['archived_at' => SORT_ASC, 'asset_tag' => SORT_ASC])->limit(20)->all(),
                static fn(Device $d) => in_array($indicator->value, array_map(static fn($ip) => IpAddress::normalize($ip), $d->getIpList()), true)
            ));
        }
        $c->incidents = self::incidentsMentioning($indicator->value);
        return $c;
    }

    /** Incidents whose text fields or timeline mention the value (whole token, so 10.0.0.1 does not match 10.0.0.12) */
    public static function incidentsMentioning(string $value): array
    {
        $fields = ['title', 'description', 'containment_actions', 'recovery_actions', 'root_cause', 'lessons_learned'];
        $or = ['or'];
        foreach ($fields as $f) {
            $or[] = ['like', 'incident.' . $f, $value];
        }
        $or[] = ['incident.id' => (new Query())->select('incident_id')->from('incident_timeline')->where(['like', 'body', $value])];
        $candidates = Incident::find()->where($or)->orderBy(['detected_at' => SORT_DESC])->limit(50)->all();
        $pattern = '/(?<![\w.:])' . preg_quote($value, '/') . '(?![\w:]|\.\d)/i';
        return array_values(array_filter($candidates, static function (Incident $i) use ($fields, $pattern) {
            foreach ($fields as $f) {
                if ($i->$f && preg_match($pattern, $i->$f)) {
                    return true;
                }
            }
            foreach ($i->getTimeline()->select('body')->column() as $body) {
                if (preg_match($pattern, $body)) {
                    return true;
                }
            }
            return false;
        }));
    }

    /** Blocks in force (not expired) */
    public function activeBlocks(): array
    {
        return array_values(array_filter($this->blocks, static fn(BlocklistEntry $b) => $b->getIsInForce()));
    }
}
