<?php

declare(strict_types=1);

namespace Magput\Debug\data;

use Magput\Debug\DebugModule;
use Magput\Debug\helpers\JsonHelper;
use Exception;
use MongoDB\BSON\UTCDateTime;
use yii\base\Component;
use yii\di\Instance;
use yii\mongodb\Collection;
use yii\mongodb\Connection as MongoConnection;

class MongoDataStorage extends Component implements DataStorage
{
    /** @var string Mongo connection component id */
    public $mongoComponent = 'mongodbForLogs';

    /** @var string Collection with full debug payload */
    public $dataCollectionName = 'debug_data';

    /** @var string Collection with manifest/index (tag + summary) */
    public $indexCollectionName = 'debug_index';

    /**
     * @var int the maximum number of mail data files to keep. If there are more files generated,
     * the oldest ones will be removed.
     */
    public $historySize = 50;

    /** @var DebugModule */
    private $module;

    /** @var MongoConnection */
    private $mongo;

    /** @var Collection */
    private $dataCollection;

    /** @var Collection */
    private $indexCollection;

    private const RESERVED_INDEX_KEYS = ['processingTime', 'ip', 'peakMemory', 'method', 'statusCode', 'tag', 'time', 'userId'];

    public function init()
    {
        parent::init();

        try {
            $this->mongo = Instance::ensure($this->mongoComponent, MongoConnection::class);
            $this->dataCollection = $this->mongo->getCollection($this->dataCollectionName);
            $this->indexCollection = $this->mongo->getCollection($this->indexCollectionName);
        } catch (Exception $e) {
            return;
        }
    }

    /**
     * @param DebugModule $module
     *
     * @return void
     */
    public function setModule($module)
    {
        $this->module = $module;
    }

    /**
     * @param string $tag
     *
     * @return array
     */
    public function getData($tag)
    {
        if (!isset($this->dataCollection) || empty($tag)) {
            return [];
        }

        $doc = $this->dataCollection->findOne(
            ['tag' => (string)$tag],
            ['logData' => 1]
        );

        if (!$doc || !isset($doc['logData']) || !is_array($doc['logData'])) {
            return [];
        }

        return $doc['logData'];
    }

    /**
     * @param string $tag
     * @param array $data
     *
     * @return void
     * @throws \yii\mongodb\Exception
     */
    public function setData($tag, $data)
    {
        if (!isset($this->dataCollection) || !isset($this->indexCollection) || !is_array($data)) {
            return;
        }

        $time = (int)(isset($data['summary']['time']) ? $data['summary']['time'] : time());

        $log = [
            'tag' => $tag,
            'logData' => $data,
            'date' => new UTCDateTime($time * 1000),
            'time' => $time,
        ];

        /**
         * $tag генерируется как uuid на каждый запрос, поэтому логичнее делать insert,
         * а не update(..., upsert=true): upsert требует проверки наличия документа по `tag`.
         */
        try {
            $this->dataCollection->insert($log);
        } catch (Exception $e) {
            /** На случай коллизии/уникального индекса (редко) откатываемся на upsert */
            $this->dataCollection->update(
                ['tag' => $tag],
                ['$set' => $log],
                ['upsert' => true]
            );
        }

        $this->updateIndex($tag, $data['summary'] ?? []);
    }

    /**
     * Writes only index document without full payload.
     *
     * @param string $tag
     * @param array $summary
     * @return void
     * @throws \yii\mongodb\Exception
     */
    public function setIndexData($tag, array $summary)
    {
        if (!isset($this->indexCollection)) {
            return;
        }

        $this->updateIndex($tag, $summary);
    }

    public function getDataManifest($forceReload = false)
    {
        return [];
    }

    /**
     * @param string $tag
     * @param array $summary
     *
     * @return void
     * @throws \yii\mongodb\Exception
     */
    private function updateIndex($tag, array $summary)
    {
        if (!isset($this->indexCollection)) {
            return;
        }

        $time = (int)(isset($summary['time']) ? $summary['time'] : time());

        $newData = [
            'date' => new UTCDateTime($time * 1000),
            'ip' => isset($summary['ip']) ? $summary['ip'] : null,
            'method' => isset($summary['method']) ? $summary['method'] : null,
            'peakMemory' => isset($summary['peakMemory']) ? $summary['peakMemory'] : null,
            'processingTime' => isset($summary['processingTime']) ? $summary['processingTime'] : null,
            'requestUrl' => $summary['url'] ?? null,
            'statusCode' => isset($summary['statusCode']) ? $summary['statusCode'] : null,
            'summary' => array_diff_key(
                $summary,
                array_flip(self::RESERVED_INDEX_KEYS)
            ),
            'tag' => $tag,
            'time' => $time,
            'userId' => isset($summary['userId']) ? $summary['userId'] : null,
        ];

        /** 
         * $tag генерируется как uuid на каждый запрос, поэтому логичнее делать insert,
         * а не update(..., upsert=true): upsert требует проверки наличия документа по `tag`.
         */
        try {
            $this->indexCollection->insert($newData);
        } catch (Exception $e) {
            /** На случай коллизии/уникального индекса откатываемся на upsert */
            $this->indexCollection->update(
                ['tag' => $tag],
                ['$set' => $newData],
                ['upsert' => true]
            );
        }
    }

    public function findIndexPage(array $filters, $limit = 50, $cursor = null)
    {

        if (!isset($this->indexCollection)) {
            return ['items' => [], 'nextCursor' => null];
        }

        $baseQuery = $this->buildIndexQuery($filters); // теперь безопаснее

        $cursorQuery = null;
        if ($cursor && isset($cursor['time'], $cursor['tag'])) {
            $cursorQuery = [
                '$or' => [
                    ['time' => ['$lt' => (int)$cursor['time']]],
                    [
                        '$and' => [
                            ['time' => (int)$cursor['time']],
                            ['tag'  => ['$lt' => (string)$cursor['tag']]],
                        ],
                    ],
                ],
            ];
        }

        if (!empty($baseQuery) && $cursorQuery !== null) {
            $condition = ['$and' => [$baseQuery, $cursorQuery]];
        } elseif (!empty($baseQuery)) {
            $condition = $baseQuery;
        } elseif ($cursorQuery !== null) {
            $condition = $cursorQuery;
        } else {
            $condition = [];
        }

        $cursorDb = $this->indexCollection->find(
            $condition,
            [
                'tag' => 1,
                'time' => 1,
                'ip' => 1,
                'method' => 1,
                'statusCode' => 1,
                'requestUrl' => 1,
                'processingTime' => 1,
                'peakMemory' => 1,
                'summary' => 1,
                'userId' => 1,
            ],
            [
                'sort' => ['time' => -1, 'tag' => -1],
                'limit' => $limit,
            ]
        );

        $items = [];
        $last = null;
        foreach ($cursorDb as $doc) {
            $tag = (string)(isset($doc['tag']) ? $doc['tag'] : '');
            if ($tag === '') {
                continue;
            }
            $items[] = $doc;
            $last = ['time' => (int)(isset($doc['time']) ? $doc['time'] : 0), 'tag' => $tag];
        }

        return [
            'items' => $items,
            'nextCursor' => $last ? $last : null,
        ];
    }

    private function buildIndexQuery(array $filters): array
    {
        $clauses = [];

        if (!empty($filters['ip'])) {
            $clauses[] = ['ip' => (string)$filters['ip']];
        }

        if (!empty($filters['method'])) {
            $clauses[] = ['method' => (string)$filters['method']];
        }

        if (!empty($filters['statusCode'])) {
            $clauses[] = ['statusCode' => (string)$filters['statusCode']];
        }

        if (!empty($filters['postData'])) {
            $clauses[] = [
                'summary.postData' => [
                '$regex' => (string)$filters['postData'],
                '$options' => 'i',
            ]];
        }

        if (!empty($filters['url'])) {
            $clauses[] = [
                'requestUrl' => [
                    '$regex' => preg_quote((string)$filters['url'], '/'),
                    '$options' => 'i',
                ],
            ];
        }

        if (!empty($filters['userId'])) {
            $clauses[] = [
                'userId' => [
                    '$regex' => $filters['userId'],
                    '$options' => 'i',
                ],
            ];
        }

        /** time range */
        $range = [];
        if (!empty($filters['timeFrom'])) {
            $range['$gte'] = (int)$filters['timeFrom'];
        }
        if (!empty($filters['timeTo'])) {
            $range['$lte'] = (int)$filters['timeTo'];
        }
        if (!empty($range)) {
            $clauses[] = ['time' => $range];
        }

        /** Критично: никакого ['$and' => []] */
        if (count($clauses) === 0) {
            return [];
        }
        if (count($clauses) === 1) {
            return $clauses[0];
        }

        return ['$and' => $clauses];
    }
}
