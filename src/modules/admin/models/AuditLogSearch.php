<?php

namespace app\modules\admin\models;

use app\models\AuditLog;
use yii\base\Model;
use yii\data\ActiveDataProvider;

class AuditLogSearch extends Model
{
    public $q;
    public $entity_type;
    public $action;
    public $user_id;
    public $date_from;
    public $date_to;
    public $ip;

    public function rules(): array
    {
        return [
            [['q', 'entity_type', 'action', 'ip'], 'string', 'max' => 100],
            ['user_id', 'integer'],
            [['date_from', 'date_to'], 'date', 'format' => 'php:Y-m-d'],
        ];
    }

    public function formName(): string
    {
        return '';
    }

    public function attributeLabels(): array
    {
        return [
            'q' => 'Record / reference', 'entity_type' => 'Type', 'action' => 'Action', 'user_id' => 'User',
            'date_from' => 'From', 'date_to' => 'To', 'ip' => 'IP address',
        ];
    }

    public function search(array $params): ActiveDataProvider
    {
        $query = AuditLog::find();
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 50],
            'sort' => ['defaultOrder' => ['id' => SORT_DESC], 'attributes' => ['id', 'created_at' => ['asc' => ['id' => SORT_ASC], 'desc' => ['id' => SORT_DESC]], 'entity_type', 'action', 'username']],
        ]);
        $this->load($params);
        if (!$this->validate()) {
            $query->where('0=1');
            return $dataProvider;
        }
        $query->andFilterWhere(['entity_type' => $this->entity_type, 'action' => $this->action, 'user_id' => $this->user_id])
            ->andFilterWhere(['like', 'entity_label', $this->q])
            ->andFilterWhere(['like', 'ip', $this->ip]);
        if ($this->date_from) {
            $query->andWhere(['>=', 'created_at', $this->date_from . ' 00:00:00']);
        }
        if ($this->date_to) {
            $query->andWhere(['<=', 'created_at', $this->date_to . ' 23:59:59']);
        }
        return $dataProvider;
    }
}
