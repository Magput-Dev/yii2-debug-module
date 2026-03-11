<?php

use Magput\Debug\panels\CustomDbPanel;
use Magput\Debug\helpers\JsonHelper;
use yii\data\ArrayDataProvider;
use yii\debug\models\search\Debug;
use yii\debug\Module;
use yii\debug\Panel;
use yii\debug\panels\AssetPanel;
use yii\debug\panels\DbPanel;
use yii\debug\panels\EventPanel;
use yii\debug\panels\MailPanel;
use yii\debug\panels\ProfilingPanel;
use yii\debug\panels\TimelinePanel;
use yii\grid\GridView;
use yii\grid\SerialColumn;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\View;

/* @var $this View */
/* @var $manifest array */
/* @var $searchModel Debug */
/* @var $dataProvider ArrayDataProvider */
/* @var $panels Panel[] */

$this->title = 'Yii Debugger';
?>
<div class="yii-debug-main-container default-index">
    <div id="yii-debug-toolbar" class="yii-debug-toolbar yii-debug-toolbar_position_top" style="display: none;">
        <div class="yii-debug-toolbar__bar">
            <div class="yii-debug-toolbar__block yii-debug-toolbar__title">
                <a href="#">
                    <img width="30" height="30" alt="" src="<?= Module::getYiiLogo() ?>">
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
                } elseif ($panel instanceof CustomDbPanel) {
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
                        'eventCount' => count($panel->data ?? []),
                    ];
                } elseif ($panel instanceof MailPanel) {
                    $extraParams = [
                        'mailCount' => is_array($panel->data) ? count($panel->data ?? []) : '⚠',
                    ];
                } elseif (
                    $panel instanceof TimelinePanel
                    || $panel instanceof AssetPanel
                ) {
                    continue;
                }
                ?>
                <?= $this->renderFile(Yii::getAlias('@api/components/Debug/views/default/panels/' . $panelViewPath . '/summary.php'), [
                    'panel' => $panel,
                    'data' => $panel->data,
                    ...$extraParams
                ]) ?>
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
                'options' => ['id' => 'debug-grid'],
                'tableOptions' => ['class' => 'table table-striped table-bordered'],
                'pager' => false,
                'summary' => false,
                'rowOptions' => function ($model) use ($searchModel, $hasDbPanel) {
                    if ($searchModel->isCodeCritical($model['statusCode'])) {
                        return ['class' => 'table-danger'];
                    }

                    return [];
                },
                'columns' => array_filter([
                    [
                        'class' => SerialColumn::class,
                        'contentOptions' => [
                            'class' => 'serial-column',
                        ],
                    ],
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
                            if (!isset($data['processingTime']) || $data['processingTime'] === '') {
                                return '<span class="not-set">(not set)</span>';
                            }
                            return number_format((float)$data['processingTime'] * 1000) . ' ms';
                        },
                        'format' => 'html',
                    ],
                    [
                        'attribute' => 'peakMemory',
                        'value' => function ($data) {
                            if (!isset($data['peakMemory']) || $data['peakMemory'] === '') {
                                return '<span class="not-set">(not set)</span>';
                            }
                            return sprintf('%.3f MB', (float)$data['peakMemory'] / 1048576);
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
                        'attribute' => 'url',
                        'label' => 'URL/Command',
                    ],
                    [
                        'attribute' => 'postData',
                        'label' => 'Post Data',
                        'format' => 'raw',
                        'value' => function ($data) {
                            $postData = $data['postData'] ?? null;

                            if (!$postData || $postData === 'null') {
                                return null;
                            }

                            if (is_array($postData)) {
                                $postData = JsonHelper::encode($postData);
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
                        'label' => 'Status code'
                    ],
                    'userId',
                ]),
            ]);
            ?>
        </div>
    </div>
</div>
<div id="debug-infinite-sentinel" style="height: 1px;"></div>
<div id="debug-infinite-loader" style="display:none; padding: 10px 0;">Loading…</div>
<style>
    .json-block {
        cursor: pointer;
        white-space: pre-wrap;
    }
</style>
<script type="text/javascript">
    (function () {
        // ВАЖНО: эти переменные нужно прокинуть из actionIndex (nextCursor)
        // Если пока не прокинули — поставьте null, infinite просто не активируется
        const nextCursorInit = <?= isset($nextCursor) ? json_encode($nextCursor) : 'null' ?>;

        const grid = document.getElementById('debug-grid');
        if (!grid || !nextCursorInit) {
            return;
        }

        const tbody = grid.querySelector('tbody');
        const sentinel = document.getElementById('debug-infinite-sentinel');
        const loader = document.getElementById('debug-infinite-loader');

        let isLoading = false;
        let done = false;
        let cursor = nextCursorInit;

        function buildUrl() {
            const params = new URLSearchParams(window.location.search);

            // cursor
            params.set('cursorTime', String(cursor.time));
            params.set('cursorTag', String(cursor.tag));
            params.set('limit', '50');

            // endpoint
            return '<?= Url::to(['list']) ?>' + '?' + params.toString();
        }

        async function loadMore() {
            if (isLoading || done || !cursor) return;

            isLoading = true;
            loader.style.display = 'block';

            try {
                const resp = await fetch(buildUrl(), { credentials: 'same-origin' });
                if (!resp.ok) {
                    done = true;
                    return;
                }

                const data = await resp.json();
                const html = data.html || '';

                if (!html.trim()) {
                    done = true;
                    return;
                }

                // append rows
                const tmp = document.createElement('tbody');
                tmp.innerHTML = html;

                while (tmp.firstElementChild) {
                    tbody.appendChild(tmp.firstElementChild);
                }

                // обновляем cursor
                cursor = data.nextCursor || null;
                if (!cursor) {
                    done = true;
                }

                // Пересчитать serial column (иначе будут пустые)
                renumberSerial();

            } catch (e) {
                done = true;
            } finally {
                loader.style.display = 'none';
                isLoading = false;
            }
        }

        function renumberSerial() {
            const rows = tbody.querySelectorAll('tr');
            let i = 1;
            rows.forEach(tr => {
                const td = tr.querySelector('td.serial-column');
                if (td) td.textContent = String(i++);
            });
        }

        // Делегирование для json-block, чтобы работало на новых строках
        document.addEventListener('click', (e) => {
            const block = e.target.closest('.json-block');
            if (!block) return;

            const selectedText = window.getSelection().toString();
            if (selectedText.length > 0) return;

            try {
                const isMinified = (block.dataset.state || 'minified') === 'minified';
                if (isMinified) {
                    const obj = JSON.parse(block.textContent);
                    block.dataset.original = block.textContent;
                    block.textContent = JSON.stringify(obj, null, 2);
                    block.dataset.state = 'pretty';
                } else {
                    block.textContent = block.dataset.original || block.textContent;
                    block.dataset.state = 'minified';
                }
            } catch (err) {}
        });

        renumberSerial();

        // IntersectionObserver
        const io = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    loadMore();
                }
            });
        }, { root: null, rootMargin: '600px 0px', threshold: 0 });

        io.observe(sentinel);
    })();
</script>
