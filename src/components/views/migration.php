<?php
/**
 * Template for new migrations (`make yii ARGS="migrate/create xyz"`).
 * @var string $className
 * @var string $namespace
 */
echo "<?php\n";
if (!empty($namespace)) {
    echo "\nnamespace {$namespace};\n";
}
?>

use app\components\Migration;

class <?= $className ?> extends Migration
{
    public function safeUp()
    {
    }

    public function safeDown()
    {
        echo "<?= $className ?> cannot be reverted.\n";
        return false;
    }
}
