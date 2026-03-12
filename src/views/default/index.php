<?php

use Magput\Debug\panels\CustomDbPanel;
use Magput\Debug\helpers\JsonHelper;
use yii\data\ArrayDataProvider;
use yii\debug\panels\AssetPanel;
use yii\debug\panels\DbPanel;
use yii\debug\panels\EventPanel;
use yii\debug\panels\MailPanel;
use yii\debug\panels\ProfilingPanel;
use yii\debug\panels\TimelinePanel;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;

/* @var $this \yii\web\View */
/* @var $manifest array */
/* @var $searchModel \yii\debug\models\search\Debug */
/* @var $dataProvider ArrayDataProvider */
/* @var $panels \yii\debug\Panel[] */

$this->title = 'Yii Debugger';
?>
<div class="yii-debug-main-container default-index">
    <div id="yii-debug-toolbar" class="yii-debug-toolbar yii-debug-toolbar_position_top" style="display: none;">
        <div class="yii-debug-toolbar__bar">
            <div class="yii-debug-toolbar__block yii-debug-toolbar__title">
                <a href="#">
                    <img width="30" height="30" alt="" src="<?= \yii\debug\Module::getYiiLogo() ?>">
                </a>
            </div>
            <?php
                foreach ($panels as $panel) {
                    $panelViewPath = $panel->id;
                    $extraParams = [];
                    if ($panel instanceof ProfilingPanel) {
                        $panelViewPath = 'profile';
                        $extraParams = [
                            'memory' => sprintf('%.3f MB', ($panel->data['memory'] ?? 0) / 1048576),
                            'time' => number_format(($panel->data['time'] ?? 0) * 1000) . ' ms',
                        ];
                    } elseif ($panel instanceof DbPanel || $panel instanceof CustomDbPanel) {
                        $timings = $panel->calculateTimings();
                        $queryCount = count($timings);
                        $queryTime = number_format($panel->getTotalQueryTime($timings) * 1000) . ' ms';
                        $excessiveCallerCount = $panel->getExcessiveCallersCount();

                        $extraParams = [
                            'timings' => $timings,
                            'queryCount' => $queryCount,
                            'queryTime' => $queryTime,
                            'excessiveCallerCount' => $excessiveCallerCount,
                        ];
                    } elseif ($panel instanceof EventPanel) {
                        $extraParams = [
                            'eventCount' => count(isset($panel->data) ? $panel->data : []),
                        ];
                    } elseif ($panel instanceof MailPanel) {
                        $extraParams = [
                            'mailCount' => is_array($panel->data) ? count(isset($panel->data) ? $panel->data : []) : '⚠',
                        ];
                    } elseif (
                        $panel instanceof TimelinePanel
                        || $panel instanceof AssetPanel
                    ) {
                        continue;
                    }
            ?>
                <?= $this->render('panels/' . $panelViewPath . '/summary.php', array_merge([
                    'panel' => $panel,
                    'data' => $panel->data,
                ], $extraParams)) ?>
            <?php } ?>
        </div>
    </div>

    <div class="container-fluid">
        <div class="table-responsive">
            <h1>Available Debug Data</h1>
            <?php

            $codes = [];
            foreach ($manifest as $tag => $vals) {
                if (!empty($vals['statusCode'])) {
                    $codes[] = $vals['statusCode'];
                }
            }
            $codes = array_unique($codes, SORT_NUMERIC);
            $statusCodes = !empty($codes) ? array_combine($codes, $codes) : null;

            $hasDbPanel = isset($panels['db']);

            echo GridView::widget([
                'dataProvider' => $dataProvider,
                'filterModel' => $searchModel,
                'filterUrl' => $this->context->module->gatewayPath . '/debug/default',
                'rowOptions' => function ($model) use ($searchModel, $hasDbPanel) {
                    if ($searchModel->isCodeCritical($model['statusCode'])) {
                        return ['class' => 'table-danger'];
                    }

                    return [];
                },
                'pager' => [
                    'linkContainerOptions' => [
                        'class' => 'page-item'
                    ],
                    'linkOptions' => [
                        'class' => 'page-link'
                    ],
                    'disabledListItemSubTagOptions' => [
                        'tag' => 'a',
                        'href' => 'javascript:;',
                        'tabindex' => '-1',
                        'class' => 'page-link'
                    ]
                ],
                'columns' => array_filter([
                    ['class' => 'yii\grid\SerialColumn'],
                    [
                        'attribute' => 'tag',
                        'value' => function ($data) {
                            return Html::a($data['tag'], ['view', 'tag' => $data['tag']]);
                        },
                        'format' => 'html',
                    ],
                    [
                        'attribute' => 'time',
                        'value' => function ($data) {
                            return '<span class="nowrap">' . Yii::$app->formatter->asDatetime($data['time'],
                                    'yyyy-MM-dd HH:mm:ss') . '</span>';
                        },
                        'format' => 'html',
                    ],
                    [
                        'attribute' => 'processingTime',
                        'value' => function ($data) {
                            return isset($data['processingTime']) ? number_format($data['processingTime'] * 1000) .
                                ' ms' : '<span class="not-set">(not set)</span>';
                        },
                        'format' => 'html',
                    ],
                    [
                        'attribute' => 'peakMemory',
                        'value' => function ($data) {
                            return isset($data['peakMemory']) ? sprintf('%.3f MB', $data['peakMemory'] /
                                1048576) : '<span class="not-set">(not set)</span>';
                        },
                        'format' => 'html',
                    ],
                    'ip',
                    $hasDbPanel ? [
                        'attribute' => 'sqlCount',
                        'label' => 'Query Count',
                        'value' => function ($data) {
                            /* @var $dbPanel DbPanel */
                            $dbPanel = $this->context->module->panels['db'];

                            $title = "Executed {$data['sqlCount']} database queries.";
                            $warning = '';
                            if ($dbPanel->isQueryCountCritical($data['sqlCount'])) {
                                $warning .= 'Too many queries. Allowed count is ' . $dbPanel->criticalQueryThreshold;
                            }
                            if (!empty($data['excessiveCallersCount'])) {
                                $warning .= ($warning ? ' &#10;' : '') . $data['excessiveCallersCount'] . ' '
                                    . ($data['excessiveCallersCount'] == 1 ? 'caller is' : 'callers are')
                                    . ' making too many calls.';
                            }

                            $content = $data['sqlCount'];
                            if ($warning) {
                                $content .= ' <span title="' . $warning . '">&#x26a0;</span>';
                            }

                            return '<a href="' . Url::to(['view', 'panel' => 'db', 'tag' => $data['tag']]) .'"
                                        title="' . $title . '">' . $content . '</a>';
                        },
                        'format' => 'raw',
                    ] : null,
                    [
                        'attribute' => 'mailCount',
                        'visible' => isset($this->context->module->panels['mail']),
                    ],
                    [
                        'attribute' => 'method',
                        'filter' => [
                            'get' => 'GET',
                            'post' => 'POST',
                            'delete' => 'DELETE',
                            'put' => 'PUT',
                            'head' => 'HEAD',
                            'command' => 'COMMAND'
                        ]
                    ],
                    [
                        'attribute' => 'ajax',
                        'value' => function ($data) {
                            return $data['ajax'] ? 'Yes' : 'No';
                        },
                        'filter' => ['No', 'Yes'],
                    ],
                    [
                        'attribute' => 'url',
                        'label' => 'URL/Command',
                    ],
                    [
                        'attribute' => 'postData',
                        'label' => 'Post Data',
                        'format' => 'raw',
                        'value' => function ($data) {
                            $postData = isset($data['postData'])
                                ? JsonHelper::encode($data['postData'], JSON_UNESCAPED_UNICODE)
                                : null;

                            if (!$postData) {
                                return null;
                            }

                            return '<div class="json-block">' . $postData . '</div>';
                        },
                    ],
                    [
                        'attribute' => 'statusCode',
                        'value' => function ($data) {
                            $statusCode = $data['statusCode'];
                            $method = $data['method'];
                            if ($statusCode === null) {
                                $statusCode = 200;
                            }
                            if (($statusCode >= 200 && $statusCode < 300) || ($method == 'COMMAND' && $statusCode == 0)) {
                                $class = 'badge-success';
                            } elseif ($statusCode >= 300 && $statusCode < 400) {
                                $class = 'badge-info';
                            } else {
                                $class = 'badge-danger';
                            }
                            return "<span class=\"badge {$class}\">$statusCode</span>";
                        },
                        'format' => 'raw',
                        'filter' => $statusCodes,
                        'label' => 'Status code'
                    ],
                ]),
            ]);
            ?>
        </div>
    </div>
</div>
<style>
    .json-block {
        cursor: pointer;
        white-space: pre-wrap;
    }
</style>
<script type="text/javascript">
    if (window.top == window) {
        document.querySelector('#yii-debug-toolbar').style.display = 'block';
    }

    document.querySelectorAll('.json-block').forEach(block => {
        block.dataset.state = 'minified';

        block.addEventListener('click', () => {
            // Проверка: если пользователь что-то выделил — ничего не делаем
            const selectedText = window.getSelection().toString();
            if (selectedText.length > 0) {
                return;
            }

            try {
                const isMinified = block.dataset.state === 'minified';

                if (isMinified) {
                    const obj = JSON.parse(block.textContent);
                    block.dataset.original = block.textContent;
                    block.textContent = JSON.stringify(obj, null, 2);
                    block.dataset.state = 'pretty';
                } else {
                    block.textContent = block.dataset.original || block.textContent;
                    block.dataset.state = 'minified';
                }

            } catch (e) {
                console.error('Invalid JSON:', e);
            }
        });
    });
</script>
