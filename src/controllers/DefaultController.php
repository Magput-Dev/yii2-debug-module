<?php

declare(strict_types=1);

namespace Magput\Debug\controllers;

use Magput\Debug\data\Debug;
use Magput\Debug\data\MongoDataStorage;
use Magput\Debug\DebugModule;
use Magput\Debug\helpers\JsonHelper;
use Yii;
use yii\data\ArrayDataProvider;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Debugger controller provides browsing over available debug logs.
 *
 *
 * @see    \yii\debug\Panel
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since  2.0
 */
class DefaultController extends Controller
{
    /**
     * {@inheritdoc}
     */
    public $layout = '@vendor/yiisoft/yii2-debug/src/views/layouts/main';
    /**
     * @var DebugModule owner module.
     */
    public $module;
    /**
     * @var array the summary data (e.g. URL, time)
     */
    public $summary;

    /**
     * {@inheritdoc}
     */
    public function actions()
    {
        $actions = [];
        foreach ($this->module->panels as $panel) {
            $actions = array_merge($actions, $panel->actions);
        }

        return $actions;
    }

    /**
     * {@inheritdoc}
     */
    public function beforeAction($action)
    {
        Yii::$app->response->format = Response::FORMAT_HTML;
        return parent::beforeAction($action);
    }

    public function actionIndex()
    {
        ini_set('memory_limit', '512M');

        $dataStorage = $this->module->getDataStorage();

        if ($dataStorage instanceof MongoDataStorage) {
            $filters = Yii::$app->request->get('Debug') ?? [];

            $limit = 50;
            $page = $dataStorage->findIndexPage($filters, $limit, null);

            $rows = [];
            foreach ($page['items'] as $log) {
                $tag = (string)($log['tag'] ?? '');
                if ($tag === '') {
                    continue;
                }

                $summary = (isset($log['summary']) && is_array($log['summary']))
                    ? $log['summary']
                    : JsonHelper::decode(
                         $log['summary'],
                        true,
                         []
                    );

                // ВАЖНО: сопоставляем requestUrl -> url, чтобы работал ваш столбец 'url'
                $rows[] = array_merge($summary, [
                    'tag' => $tag,
                    'time' => $log['time'] ?? null,
                    'ip' => $log['ip'] ?? '',
                    'method' => $log['method'] ?? '',
                    'sqlCount' => $summary['sqlCount'] ?? null,
                    'statusCode' => $log['statusCode'] ?? null,
                    'url' => $log['requestUrl'] ?? ($summary['url'] ?? ''),
                    'processingTime' => $log['processingTime'] ?? ($summary['processingTime'] ?? null),
                    'peakMemory' => $log['peakMemory'] ?? ($summary['peakMemory'] ?? null),
                    'userId' => $log['userId'] ?? null,
                ]);
            }

            // Нужен, потому что view использует filterModel
            $searchModel = new Debug();
            $searchModel->load(Yii::$app->request->get());

            // Ключевой фикс: определяем $dataProvider
            $dataProvider = new ArrayDataProvider([
                'allModels' => $rows,
                'pagination' => false, // infinite scroll, пагинацию GridView выключаем
                'sort' => [
                    'defaultOrder' => ['time' => SORT_DESC],
                    'attributes' => [
                        'tag', 'time', 'processingTime', 'peakMemory', 'method', 'url', 'statusCode', 'sqlCount',
                    ],
                ],
            ]);

            return $this->render('index-mongo', [
                'panels' => $this->module->panels,
                'dataProvider' => $dataProvider,
                'searchModel' => $searchModel,

                // для статусов в фильтре вы собираете из $manifest, но можно из $rows
                'manifest' => array_column($rows, null, 'tag'),

                // для infinite scroll
                'nextCursor' => $page['nextCursor'],
            ]);
        }

        $manifest = $dataStorage->getDataManifest();

        $searchModel = new Debug();
        $dataProvider = $searchModel->search($_GET, $manifest);
        $dataProvider->sort->defaultOrder['time'] = SORT_DESC;

        // load latest request
        $tags = array_keys($manifest);
        $tag = reset($tags);
        if ($tag) {
            $this->loadData($tag);
        }

        return $this->render('index', [
            'panels' => $this->module->panels,
            'dataProvider' => $dataProvider,
            'searchModel' => $searchModel,
            'manifest' => $manifest,
        ]);
    }

    /**
     * @param string|null $tag   debug data tag.
     * @param string|null $panel debug panel ID.
     *
     * @return mixed response.
     * @throws NotFoundHttpException if debug data not found.
     * @see \yii\debug\Panel
     */
    public function actionView($tag = null, $panel = null)
    {
        ini_set('memory_limit', '512M');

        $dataStorage = $this->module->getDataStorage();

        if ($dataStorage instanceof MongoDataStorage) {
            if ($tag === null) {
                $tag = $dataStorage->getLatestTag();
            }

            $this->loadDataDirect($tag);

            $activePanel = $this->module->panels[$panel] ?? $this->module->panels[$this->module->defaultPanel];

            if ($activePanel->hasError()) {
                Yii::$app->errorHandler->handleException($activePanel->getError());
            }

            return $this->render('view-mongo', [
                'tag' => $tag,
                'summary' => $this->summary,
                'manifest' => [],
                'panels' => $this->module->panels,
                'activePanel' => $activePanel,
            ]);
        }

        $manifest = $dataStorage->getDataManifest();

        if ($tag === null) {
            $tags = array_keys($manifest);
            $tag = reset($tags);
        }
        $this->loadData($tag);
        if (isset($this->module->panels[$panel])) {
            $activePanel = $this->module->panels[$panel];
        } else {
            $activePanel = $this->module->panels[$this->module->defaultPanel];
        }

        if ($activePanel->hasError()) {
            Yii::$app->errorHandler->handleException($activePanel->getError());
        }

        return $this->render('@api/components/Debug/views/default/view', [
            'tag' => $tag,
            'summary' => $this->summary,
            'manifest' => $manifest,
            'panels' => $this->module->panels,
            'activePanel' => $activePanel,
        ]);
    }

    public function actionToolbar($tag)
    {
        $this->loadData($tag, 5);

        return $this->renderPartial('@vendor/yiisoft/yii2-debug/src/views/default/toolbar', [
            'tag' => $tag,
            'panels' => $this->module->panels,
            'position' => 'bottom',
            'defaultHeight' => $this->module->defaultHeight,
        ]);
    }

    /**
     * Download mail action
     *
     * @param string $file
     * @return \yii\console\Response|Response
     * @throws NotFoundHttpException
     */
    public function actionDownloadMail($file)
    {
        $filePath = Yii::getAlias($this->module->panels['mail']->mailPath) . '/' . basename($file);

        if ((mb_strpos($file, '\\') !== false || mb_strpos($file, '/') !== false) || !is_file($filePath)) {
            throw new NotFoundHttpException('Mail file not found');
        }

        return Yii::$app->response->sendFile($filePath);
    }

    /**
     * @param string $tag      debug data tag.
     * @param int    $maxRetry maximum numbers of tag retrieval attempts.
     *
     * @throws NotFoundHttpException if specified tag not found.
     */
    public function loadData(string $tag, $maxRetry = 0)
    {
        // retry loading debug data because the debug data is logged in shutdown function
        // which may be delayed in some environment if xdebug is enabled.
        // See: https://github.com/yiisoft/yii2/issues/1504
        for ($retry = 0; $retry <= $maxRetry; ++$retry) {
            $manifest = $this->module->getDataStorage()->getDataManifest($retry > 0);
            if (isset($manifest[$tag])) {
                $data=$this->module->getDataStorage()->getData($tag);
                $exceptions = isset($data['exceptions']) ? $data['exceptions'] : [];
                foreach ($this->module->panels as $id => $panel) {
                    if (isset($data[$id])) {
                        $panel->tag = $tag;
                        $panel->load(json_decode($data[$id], true));
                    }
                    if (isset($exceptions[$id])) {
                        $panel->setError($exceptions[$id]);
                    }
                }
                $this->summary = $data['summary'];

                return;
            }
            sleep(1);
        }

        throw new NotFoundHttpException("Unable to find debug data tagged with '{$tag}'.");
    }

    public function loadDataDirect($tag, $maxRetry = 0)
    {
        for ($retry = 0; $retry <= $maxRetry; ++$retry) {
            $data = $this->module->getDataStorage()->getData($tag);

            if (!empty($data) && isset($data['summary'])) {
                $exceptions = isset($data['exceptions']) ? $data['exceptions'] : [];

                foreach ($this->module->panels as $id => $panel) {
                    if (isset($data[$id])) {
                        $panel->tag = $tag;
                        $panel->load(json_decode($data[$id], true));
                    }
                    if (isset($exceptions[$id])) {
                        $panel->setError($exceptions[$id]);
                    }
                }

                $this->summary = $data['summary'];
                return;
            }

            sleep(1);
        }

        throw new NotFoundHttpException("Unable to find debug data tagged with '{$tag}'.");
    }

    public function actionList()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $dataStorage = $this->module->getDataStorage();
        if (!($dataStorage instanceof MongoDataStorage)) {
            return ['html' => '', 'nextCursor' => null];
        }

        $filters = Yii::$app->request->get('Debug') !== null ? Yii::$app->request->get('Debug') : [];

        $limit = (int)Yii::$app->request->get('limit', 50);
        $limit = max(1, min(200, $limit));

        $cursor = null;
        $cursorTime = Yii::$app->request->get('cursorTime');
        $cursorTag  = Yii::$app->request->get('cursorTag');
        if ($cursorTime !== null && $cursorTag !== null && $cursorTime !== '' && $cursorTag !== '') {
            $cursor = ['time' => (int)$cursorTime, 'tag' => (string)$cursorTag];
        }

        $page = $dataStorage->findIndexPage($filters, $limit, $cursor);

        // Приводим документы к "manifest-like" структуре, которую ожидают ваши колонки
        $rows = [];
        foreach ($page['items'] as $doc) {
            $tag = (string)(isset($doc['tag']) ? $doc['tag'] : '');
            if ($tag === '') {
                continue;
            }

            $summary = (isset($doc['summary']) && is_array($doc['summary'])) ? $doc['summary'] : [];

            $rows[] = array_merge($summary, [
                'tag' => $tag,
                'time' => isset($doc['time']) ? $doc['time'] : null,
                'ip' => isset($doc['ip']) ? $doc['ip'] : '',
                'method' => isset($doc['method']) ? $doc['method'] : '',
                'statusCode' => isset($doc['statusCode']) ? $doc['statusCode'] : null,
                'url' => isset($doc['requestUrl']) ? $doc['requestUrl'] : (isset($summary['url']) ? $summary['url'] : ''), // под вашу колонку url
                'processingTime' => isset($doc['processingTime']) ? $doc['processingTime'] : (isset($summary['processingTime']) ? $summary['processingTime'] : null),
                'peakMemory' => isset($doc['peakMemory']) ? $doc['peakMemory'] : (isset($summary['peakMemory']) ? $summary['peakMemory'] : null),
            ]);
        }

        $html = $this->renderPartial('index-mongo-row', [
            'rows' => $rows,
            'searchModel' => new \yii\debug\models\search\Debug(),
            'panels' => $this->module->panels,
        ]);

        return [
            'html' => $html,
            'nextCursor' => $page['nextCursor'],
        ];
    }
}
