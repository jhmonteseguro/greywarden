<?php

namespace app\modules\admin\models;

use app\models\User;
use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;

class UserSearch extends Model
{
    public $q;
    public $status;
    public $role;

    public function rules(): array
    {
        return [
            ['q', 'string', 'max' => 100],
            ['status', 'integer'],
            ['role', 'string', 'max' => 32],
        ];
    }

    public function formName(): string
    {
        return '';
    }

    public function attributeLabels(): array
    {
        return (new User())->attributeLabels() + ['role' => 'Role'];
    }

    public function search(array $params): ActiveDataProvider
    {
        $query = User::find();
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => Yii::$app->params['pageSize'] ?? 25],
            'sort' => ['defaultOrder' => ['full_name' => SORT_ASC], 'attributes' => ['username', 'full_name', 'email', 'status', 'last_login_at', 'created_at']],
        ]);
        $this->load($params);
        if (!$this->validate()) {
            $query->where('0=1');
            return $dataProvider;
        }
        if ($this->q) {
            $query->andWhere(['or', ['like', 'username', $this->q], ['like', 'full_name', $this->q], ['like', 'email', $this->q]]);
        }
        $query->andFilterWhere(['status' => $this->status]);
        if ($this->role) {
            $query->andWhere(['id' => (new \yii\db\Query())->select('user_id')->from('auth_assignment')->where(['item_name' => $this->role])]);
        }
        return $dataProvider;
    }
}
