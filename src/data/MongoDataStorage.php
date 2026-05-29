<?php

declare(strict_types=1);

namespace Magput\Debug\data;

use Magput\Debug\DebugModule;
use Exception;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use yii\base\Component;
use yii\di\Instance;
use yii\mongodb\Collection;
use yii\mongodb\Connection as MongoConnection;

class MongoDataStorage extends Component implements DataStorage
{
    /** @var string Mongo connection component id */
    public string $mongoComponent = 'mongodbForLogs';

    /** @var string Collection with full debug payload */
    public string $dataCollectionName = 'debug_data';

    /** @var string Collection with manifest/index (tag + summary) */
    public string $indexCollectionName = 'debug_index';

    /**
     * @var int the maximum number of mail data files to keep. If there are more files generated,
     * the oldest ones will be removed.
     */
    public int $historySize = 50;

    /** @var DebugModule */
    private DebugModule $module;

    /** @var MongoConnection */
    private MongoConnection $mongo;

    /** @var Collection */
    private Collection $dataCollection;

    /** @var Collection */
    private Collection $indexCollection;

    private const RESERVED_INDEX_KEYS = ['processingTime', 'ip', 'peakMemory', 'method', 'statusCode', 'tag', 'time', 'userId'];

    public function init(): void
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
     */
    public function setModule($module): void
    {
        $this->module = $module;
    }

    /**
     * @param string $tag
     */
    public function getData($tag): array
    {
        if (!isset($this->dataCollection) || empty($tag)) {
            return [];
        }

        $doc = $this->dataCollection->findOne(
            ['tag' => (string)$tag],
            ['logData' => 1],
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
     * @throws \yii\mongodb\Exception
     */
    public function setData($tag, $data): void
    {
        if (!isset($this->dataCollection) || !isset($this->indexCollection) || !is_array($data)) {
            return;
        }

        $time = (int)($data['summary']['time'] ?? time());

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
                ['upsert' => true],
            );
        }

        $this->updateIndex($tag, $data['summary'] ?? []);
    }

    /**
     * Writes only index document without full payload.
     *
     * @param string $tag
     * @param array $summary
     *
     * @throws \yii\mongodb\Exception
     */
    public function setIndexData(string $tag, array $summary): void
    {
        if (!isset($this->indexCollection)) {
            return;
        }

        $this->updateIndex($tag, $summary);
    }

    public function getDataManifest($forceReload = false): array
    {
        return [];
    }

    /**
     * @param string $tag
     * @param array $summary
     *
     * @throws \yii\mongodb\Exception
     */
    private function updateIndex(string $tag, array $summary): void
    {
        if (!isset($this->indexCollection)) {
            return;
        }

        $time = (int)($summary['time'] ?? time());

        $newData = [
            'date' => new UTCDateTime($time * 1000),
            'ip' => $summary['ip'] ?? null,
            'method' => $summary['method'] ?? null,
            'peakMemory' => $summary['peakMemory'] ?? null,
            'processingTime' => $summary['processingTime'] ?? null,
            'requestUrl' => $summary['url'] ?? null,
            'statusCode' => $summary['statusCode'] ?? null,
            'summary' => array_diff_key(
                $summary,
                array_flip(self::RESERVED_INDEX_KEYS),
            ),
            'tag' => $tag,
            'time' => $time,
            'userId' => $summary['userId'] ?? null,
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
                ['upsert' => true],
            );
        }
    }

    public function findIndexPage(array $filters, int $limit = 50, ?array $cursor = null): array
    {
        if (!isset($this->indexCollection)) {
            return ['items' => [], 'nextCursor' => null];
        }

        $baseQuery = $this->buildIndexQuery($filters);

        $cursorQuery = null;
        if ($cursor && isset($cursor['id']) && $cursor['id'] !== '') {
            try {
                $cursorQuery = ['_id' => ['$lt' => new ObjectId((string)$cursor['id'])]];
            } catch (Exception $e) {
                $cursorQuery = null;
            }
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
            condition: $condition,
            fields: [
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
            options: [
                'sort' => ['_id' => -1],
                'limit' => $limit,
            ],
        );

        $items = [];
        $last = null;
        foreach ($cursorDb as $doc) {
            $tag = (string)($doc['tag'] ?? '');
            if ($tag === '') {
                continue;
            }
            $items[] = $doc;
            $lastId = isset($doc['_id']) ? (string)$doc['_id'] : '';
            if ($lastId !== '') {
                $last = ['id' => $lastId];
            }
        }

        return [
            'items' => $items,
            'nextCursor' => $last ?: null,
        ];
    }

    private function buildIndexQuery(array $filters): array
    {
        $clauses = [];

        if (isset($filters['tag']) && trim((string)$filters['tag']) !== '') {
            $clauses[] = ['tag' => trim((string)$filters['tag'])];
        }

        if (!empty($filters['ip'])) {
            $clauses[] = ['ip' => (string)$filters['ip']];
        }

        if (isset($filters['method']) && trim((string)$filters['method']) !== '') {
            $clauses[] = ['method' => mb_strtoupper(trim((string)$filters['method']))];
        }

        if (isset($filters['statusCode']) && $filters['statusCode'] !== '') {
            $clauses[] = ['statusCode' => (int)$filters['statusCode']];
        }

        if (!empty($filters['postData'])) {
            $clauses[] = [
                'summary.postData' => [
                    '$regex' => (string)$filters['postData'],
                    '$options' => 'i',
                ],
            ];
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

        if (count($clauses) === 0) {
            return [];
        }
        if (count($clauses) === 1) {
            return $clauses[0];
        }

        return ['$and' => $clauses];
    }
}
