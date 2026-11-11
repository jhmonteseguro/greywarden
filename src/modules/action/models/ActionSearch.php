<?php

namespace app\modules\action\models;

use app\components\Actor;
use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use yii\db\Expression;

class ActionSearch extends Model
{
    public const FILTERS = [
        'open' => 'Open',
        'mine' => 'Mine',
        'overdue' => 'Overdue',
        'due-soon' => 'Due in 14 days',
        'closed' => 'Done or cancelled',
        'all' => 'All',
        'archived' => 'Archived',
    ];

    public $q;
    public $filter = 'open';
    public $reference;
    public $title;
    public $status;
    public $priority;
    public $owner_id;
    public $record_type;

    public function rules(): array
    {
        return [
            [['q', 'reference', 'title'], 'string', 'max' => 100],
            ['status', 'in', 'range' => array_keys(Action::statusOptions())],
            ['priority', 'in', 'range' => array_keys(Action::priorityOptions())],
            ['record_type', 'in', 'range' => array_merge(array_keys(Action::RECORD_CLASSES), ['none'])],
            ['owner_id', 'integer'],
            ['filter', 'in', 'range' => array_keys(self::FILTERS)],
        ];
    }

    public function attributeLabels(): array
    {
        return (new Action())->attributeLabels();
    }

    public function formName(): string
    {
        return '';
    }

    public function search(array $params): ActiveDataProvider
    {
        $query = Action::find()->with(['owner']);
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => Yii::$app->params['pageSize'] ?? 25],
            'sort' => [
                'defaultOrder' => ['due_date' => SORT_ASC],
                'attributes' => [
                    'reference' => ['asc' => ['action.id' => SORT_ASC], 'desc' => ['action.id' => SORT_DESC]],
                    'title', 'status', 'owner_id', 'completed_at', 'updated_at',
                    'priority' => [
                        'asc' => [new Expression("FIELD(action.priority, 'critical', 'high', 'medium', 'low')")],
                        'desc' => [new Expression("FIELD(action.priority, 'low', 'medium', 'high', 'critical')")],
                    ],
                    // No due date sorts last either way
                    'due_date' => [
                        'asc' => [new Expression('action.due_date IS NULL'), 'action.due_date' => SORT_ASC, 'action.id' => SORT_ASC],
                        'desc' => [new Expression('action.due_date IS NULL'), 'action.due_date' => SORT_DESC, 'action.id' => SORT_DESC],
                    ],
                ],
            ],
        ]);

        $this->load($params);
        if (!$this->validate()) {
            $query->where('0=1');
            return $dataProvider;
        }

        $today = date('Y-m-d');
        switch ($this->filter ?: 'open') {
            case 'archived':
                $query->archived();
                break;
            case 'mine':
                $query->active()->open()->ownedBy(Actor::id());
                break;
            case 'overdue':
                $query->active()->overdue($today);
                break;
            case 'due-soon':
                $query->active()->dueSoon($today);
                break;
            case 'closed':
                $query->active()->closed();
                $dataProvider->sort->defaultOrder = ['completed_at' => SORT_DESC];
                break;
            case 'all':
                $query->active();
                break;
            default:
                $query->active()->open();
        }

        if ($this->q !== null && $this->q !== '') {
            $query->andWhere(['or',
                ['like', 'action.reference', $this->q],
                ['like', 'action.title', $this->q],
                ['like', 'action.description', $this->q],
                ['like', 'action.outcome', $this->q],
            ]);
        }
        if ($this->record_type === 'none') {
            $query->andWhere(['action.record_type' => null]);
        } else {
            $query->andFilterWhere(['action.record_type' => $this->record_type]);
        }
        $query->andFilterWhere(['like', 'action.reference', $this->reference])
            ->andFilterWhere(['like', 'action.title', $this->title])
            ->andFilterWhere(['action.status' => $this->status, 'action.priority' => $this->priority, 'action.owner_id' => $this->owner_id]);

        return $dataProvider;
    }
}
