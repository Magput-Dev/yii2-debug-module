<?php

namespace Magput\Debug;

use Magput\Debug\data\MongoDataStorage;
use Magput\Debug\helpers\JsonHelper;
use Exception;
use Ramsey\Uuid\Uuid;
use Yii;
use yii\debug\FlattenException;
use yii\log\Target;

/**
 * The debug LogTarget is used to store logs for later use in the debugger tool
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class LogTarget extends Target
{
    private const MEMORY_GUARD_RATIO = 0.70;
    private const MEMORY_ENCODE_RESERVE_BYTES = 16777216; // 16MB

    /** @var DebugModule */
    public $module;
    /** @var string */
    public $tag;
    /** @var \Closure|null */
    public $userIdCallback;

    /**
     * @param DebugModule $module
     * @param array $config
     */
    public function __construct($module, $config = [])
    {
        parent::__construct($config);
        $this->module = $module;
        $this->tag = (isset(Yii::$app->params['DEBUG_TAG_PREFIX']) ? Yii::$app->params['DEBUG_TAG_PREFIX'] : '') . Uuid::uuid4()->toString();
    }

    /**
     * Exports log messages to a specific destination.
     * Child classes must implement this method.
     */
    public function export()
    {
        $summary = $this->collectSummary();
        $dataStorage = $this->module->getDataStorage();

        if ($dataStorage instanceof MongoDataStorage && $this->isMemoryPressureHigh()) {
            $this->ensureSummaryPerformanceMetrics($summary);
            $dataStorage->setIndexData($this->tag, $summary);
            Yii::warning('Debug payload skipped due to memory pressure; index only saved.', __METHOD__);
            return;
        }

        $data = [];
        $exceptions = [];

        foreach ($this->module->panels as $id => $panel) {
            if ($dataStorage instanceof MongoDataStorage && $this->isMemoryPressureHigh()) {
                $summary['debugPartial'] = 1;
                $summary['debugPartialReason'] = 'memory-pressure';
                $this->ensureSummaryPerformanceMetrics($summary);
                $dataStorage->setIndexData($this->tag, $summary);
                Yii::warning('Debug payload partially skipped due to memory pressure; index only saved.', __METHOD__);
                return;
            }

            try {
                $panelData = $panel->save();
                if ($id === 'profiling') {
                    $summary['peakMemory'] = $panelData['memory'];
                    $summary['processingTime'] = $panelData['time'];
                }
                if ($dataStorage instanceof MongoDataStorage && $this->isMemoryPressureHigh()) {
                    $summary['debugPartial'] = 1;
                    $summary['debugPartialReason'] = 'memory-pressure-before-encode';
                    $this->ensureSummaryPerformanceMetrics($summary);
                    $dataStorage->setIndexData($this->tag, $summary);
                    Yii::warning('Debug payload skipped before encode due to memory pressure; index only saved.', __METHOD__);
                    return;
                }
                if ($dataStorage instanceof MongoDataStorage && !$this->hasMemoryHeadroom(self::MEMORY_ENCODE_RESERVE_BYTES)) {
                    $summary['debugPartial'] = 1;
                    $summary['debugPartialReason'] = 'low-memory-headroom-before-encode';
                    $this->ensureSummaryPerformanceMetrics($summary);
                    $dataStorage->setIndexData($this->tag, $summary);
                    Yii::warning('Debug payload skipped because there is not enough free memory for encode; index only saved.', __METHOD__);
                    return;
                }
                $data[$id] = json_encode($panelData, JSON_UNESCAPED_UNICODE);
                unset($panelData);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            } catch (Exception $exception) {
                $exceptions[$id] = new FlattenException($exception);
            }
        }
        $this->ensureSummaryPerformanceMetrics($summary);
        $data['summary'] = $summary;
        $data['exceptions'] = $exceptions;

        $this->module->getDataStorage()->setData($this->tag, $data);
    }

    /**
     * @return bool
     */
    private function isMemoryPressureHigh()
    {
        $limitBytes = $this->getMemoryLimitBytes();
        if ($limitBytes <= 0) {
            return false;
        }

        return memory_get_usage(true) >= (int)($limitBytes * self::MEMORY_GUARD_RATIO);
    }

    /**
     * @param int $reserveBytes
     * @return bool
     */
    private function hasMemoryHeadroom($reserveBytes)
    {
        $limitBytes = $this->getMemoryLimitBytes();
        if ($limitBytes <= 0) {
            return true;
        }

        return ($limitBytes - memory_get_usage(true)) > $reserveBytes;
    }

    /**
     * Fills summary metrics when profiling panel was not fully processed.
     *
     * @param array $summary
     */
    private function ensureSummaryPerformanceMetrics(array &$summary)
    {
        if (!isset($summary['peakMemory']) || $summary['peakMemory'] === null) {
            $summary['peakMemory'] = memory_get_peak_usage(true);
        }

        if (!isset($summary['processingTime']) || $summary['processingTime'] === null) {
            $requestStart = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float)$_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
            $summary['processingTime'] = max(0, microtime(true) - $requestStart);
        }
    }

    /**
     * @return int
     */
    private function getMemoryLimitBytes()
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === false || $memoryLimit === '' || $memoryLimit === '-1') {
            return -1;
        }

        $memoryLimit = trim($memoryLimit);
        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int)$memoryLimit;

        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return (int)$memoryLimit;
        }
    }

    /**
     * @return array
     * @see DefaultController
     */
    public function loadManifest()
    {
        return $this->module->getDataStorage()->getDataManifest();
    }

    /**
     * @return array
     * @see DefaultController
     */
    public function loadTagToPanels($tag)
    {
        $data = $this->module->getDataStorage()->getData($tag);
        $exceptions = $data['exceptions'];
        foreach ($this->module->panels as $id => $panel) {
            if (isset($data[$id])) {
                $panel->tag = $tag;
                $panel->load(json_decode($data[$id], true));
            } else {
                unset($this->module->panels[$id]);
            }
            if (isset($exceptions[$id])) {
                $panel->setError($exceptions[$id]);
            }
        }
        $this->module->getDataStorage()->setData($this->tag, $data);

        return $data;
    }

    /**
     * Processes the given log messages.
     * This method will filter the given messages with [[levels]] and [[categories]].
     * And if requested, it will also export the filtering result to specific medium (e.g. email).
     * @param array $messages log messages to be processed. See [[\yii\log\Logger::messages]] for the structure
     * of each message.
     * @param bool $final whether this method is called at the end of the current application
     */
    public function collect($messages, $final)
    {
        $this->messages = array_merge($this->messages, $messages);
        if ($final) {
            $this->export();
        }
    }

    /**
     * Collects summary data of current request.
     * @return array
     */
    protected function collectSummary()
    {
        if (Yii::$app === null) {
            return [];
        }

        $request = Yii::$app->getRequest();
        $response = Yii::$app->getResponse();

        if ($request instanceof yii\web\Request) {
            $postData = !empty($request->getRawBody())
                ? JsonHelper::decode($request->getRawBody())
                : (!empty($_POST) ? $_POST : null);
        } else {
            $postData = null;
        }

        if (is_array($postData)) {
            $sensitiveKeys = [
                'token',
                'secret',
                'login',
                'pass',
                'password',
                'sms_code',
            ];

            foreach ($sensitiveKeys as $key) {
                if (isset($postData[$key])) {
                    $postData[$key] = '***';
                }
            }
        }

        $ip = null;
        if ($request instanceof yii\console\Request) {
            $ip = exec('whoami');
        } elseif ($request instanceof yii\web\Request) {
            $ip = !empty($request->headers->get(' X-Real-IP'))
                ? $request->headers->get('X-Real-IP')
                : $request->getUserIP();
        }

        $userIdentity = Yii::$app->user->identity;
        $oneCSecret = $request->get('secret');

        if ($this->userIdCallback instanceof \Closure) {
            $userId = call_user_func($this->userIdCallback, $userIdentity, $request);
        } else {
            $userId = ($oneCSecret === $this->module->oneCSecretKey) ? '1C_user' : null;
        }

        $summary = [
            'tag' => $this->tag,
            'url' => $request instanceof yii\console\Request ? "php yii " . implode(' ', $request->getParams()) : $request->getUrl(),
            'method' => $request instanceof yii\console\Request ? 'COMMAND' : $request->getMethod(),
            'ip' => $ip,
            'time' => $_SERVER['REQUEST_TIME_FLOAT'],
            'statusCode' => $response instanceof yii\console\Response ? $response->exitStatus : $response->statusCode,
            'sqlCount' => $this->getSqlTotalCount(),
            'getParams' => $request instanceof yii\console\Request ? $request->getParams() : $request->getQueryParams(),
            'postData' => JsonHelper::encode($postData),
            'responseData' => $response instanceof yii\console\Response
                ? $response->exitStatus
                : (!empty($response->stream) ? 'filestream' : $response->content),
            'userId' => $userId,
        ];

        return $summary;
    }

    /**
     * Returns total sql count executed in current request. If database panel is not configured
     * returns 0.
     * @return int
     */
    protected function getSqlTotalCount()
    {
        if (!isset($this->module->panels['db'])) {
            return 0;
        }

        $logs = $this->module->panels['db']->getProfileLogs();

        $doctrine = 0;
        $other = 0;

        foreach ($logs as $log) {
            if ((isset($log[2]) ? $log[2] : null) === 'vendor/doctrine/dbal/src/Driver/PDO/Statement::execute') {
                $doctrine++;
            } else {
                $other++;
            }
        }

        // Yii профилирование: begin/end → 2 записи на 1 запрос
        $yii = (int)($other / 2);

        return $yii + $doctrine;
    }
}
