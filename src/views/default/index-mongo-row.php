<?php

use Magput\Debug\helpers\PostDataHelper;
use yii\helpers\Html;
use yii\helpers\Url;

/** @var array $rows */
/** @var \yii\debug\models\search\Debug $searchModel */
/** @var \yii\debug\Panel[] $panels */

$hasDbPanel = isset($panels['db']);
?>
<?php foreach ($rows as $data) {
    $rowClass = '';
    if ($searchModel->isCodeCritical($data['statusCode'] ?? null)) {
        $rowClass = 'table-danger';
    }
    $processingTime = isset($data['processingTime']) ? number_format((float)$data['processingTime'] * 1000) . 'ms' : '<span class="not-set">(не задано)</span>';
    $peakMemory = isset($data['peakMemory']) ? sprintf('%.3f MB', (float)$data['peakMemory'] / 1048576) : '<span class="not-set">(не задано)</span>';

    $statusCode = $data['statusCode'] ?? 200;
    $method = $data['method'] ?? '';
    if (($statusCode >= 200 && $statusCode < 300) || ($method === 'COMMAND' && (int)$statusCode === 0)) {
        $statusCodeClass = 'badge-success';
    } elseif ($statusCode >= 300 && $statusCode < 400) {
        $statusCodeClass = 'badge-info';
    } else {
        $statusCodeClass = 'badge-danger';
    }

    $postContent = PostDataHelper::renderPreviewBlock($data['postData'] ?? null);
?>
    <tr class="<?= Html::encode($rowClass) ?>">
        <td class="serial-column"></td>
        <td><?= Html::a(Html::encode($data['tag']), ['view', 'tag' => $data['tag']]) ?></td>
        <td><span class="nowrap"><?= Yii::$app->formatter->asDatetime($data['time'], 'yyyy-MM-dd HH:mm:ss') ?></span></td>
        <td><?= $processingTime ?></td>
        <td><?= $peakMemory ?></td>
        <td><?= Html::encode($data['ip'] ?? '') ?></td>

        <?php if ($hasDbPanel): ?>
            <?php
                $dbPanel = Yii::$app->controller->module->panels['db'];
                $sqlCount = (int)($data['sqlCount'] ?? 0);

                $title = "Executed {$sqlCount} database queries.";
                $warning = '';
                if ($dbPanel->isQueryCountCritical($sqlCount)) {
                    $warning .= 'Too many queries. Allowed count is ' . $dbPanel->criticalQueryThreshold;
                }
                if (!empty($data['excessiveCallersCount'])) {
                    $warning .= ($warning ? ' &#10;' : '') . $data['excessiveCallersCount'] . ' '
                        . ($data['excessiveCallersCount'] == 1 ? 'caller is' : 'callers are')
                        . ' making too many calls.';
                }

                $content = (string)$sqlCount;
                if ($warning) {
                    $content .= ' <span title="' . Html::encode($warning) . '">&#x26a0;</span>';
                }
            ?>
            <td><a href="<?= Url::to(['view', 'panel' => 'db', 'tag' => $data['tag']]) ?>"
                   title="<?= Html::encode($title) ?>"><?= $content ?></a></td>
        <?php endif; ?>

        <td><?= Html::encode($data['method'] ?? '') ?></td>

        <td><?= Html::encode($data['url'] ?? '') ?></td>

        <td><?= $postContent ?></td>

        <td><span class="badge <?= Html::encode($statusCodeClass) ?>"><?= Html::encode((string)$statusCode) ?></span></td>

        <td><?= Html::encode($data['userId'] ?? '') ?></td>
    </tr>
<?php } ?>
