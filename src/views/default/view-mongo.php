<?php

use yii\helpers\Html;
use yii\helpers\Url;

/* @var $this \yii\web\View */
/* @var $summary array */
/* @var $tag string */
/* @var $manifest array */
/* @var $panels \yii\debug\Panel[] */
/* @var $activePanel \yii\debug\Panel */

$this->title = 'Yii Debugger';
?>
<div class="yii-debug-main-container default-view">
    <div id="yii-debug-toolbar" class="yii-debug-toolbar yii-debug-toolbar_position_top" style="display: none;">
        <div class="yii-debug-toolbar__bar">
            <div class="yii-debug-toolbar__block yii-debug-toolbar__title">
                <a href="<?= Url::to(['index']) ?>">
                    <img width="29" height="30" alt="" src="<?= \yii\debug\Module::getYiiLogo() ?>">
                </a>
            </div>

            <?php foreach ($panels as $panel): ?>
                <?= $panel->getSummary() ?>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="container-fluid main-container">
        <div class="row">
            <div class="col-md-2">
                <div class="list-group">
                    <?php
                    $classes = ['list-group-item', 'd-flex', 'justify-content-between', 'align-items-center'];
                    foreach ($panels as $id => $panel) {
                        $label = Html::tag('span', Html::encode($panel->getName())) . '<span class="icon"></span>';
                        echo Html::a($label, ['view', 'tag' => $tag, 'panel' => $id], [
                            'class' => $panel === $activePanel ? array_merge($classes, ['active']) : $classes,
                        ]);
                    }
                    ?>
                </div>
            </div>
            <div class="col-md-10">
                <?php
                $statusCode = $summary['statusCode'];
                $method = $summary['method'];
                if ($statusCode === null) {
                    $statusCode = 200;
                }
                if (($statusCode >= 200 && $statusCode < 300) || ($method == 'COMMAND' && $statusCode == 0)) {
                    $calloutClass = 'callout-success';
                } elseif ($statusCode >= 300 && $statusCode < 400) {
                    $calloutClass = 'callout-info';
                } else {
                    $calloutClass = 'callout-danger';
                }
                ?>
                <div class="callout <?= $calloutClass ?>">
                    <?php
                    echo "\n" . $summary['tag'] . ': ' . Html::encode($summary['method']) . ' ' . Html::a(Html::encode($summary['url']),
                            $summary['url']);
                    echo ' at ' . date('Y-m-d h:i:s a', (int) $summary['time']) . ' by ' . $summary['ip'];
                    ?>
                </div>
                <?= $activePanel->getDetail(); ?>
            </div>
        </div>
    </div>
</div>
<script type="text/javascript">
    if (window.top == window) {
        document.querySelector('#yii-debug-toolbar').style.display = 'block';
    }
</script>
