<?php

declare(strict_types=1);

namespace Magput\Debug\data;

use Magput\Debug\DebugModule;
use Exception;
use Yii;
use yii\base\Component;
use yii\debug\Module;
use yii\di\Instance;
use yii\redis\Connection as Redis;

class RedisDataStorage extends Component implements DataStorage
{
    /** @var string Cache component di identifier */
    public string $redisComponent = 'redis';

    /** @var string Cache data key for debug data */
    public string $redisDebugDataKey = 'debug:';

    /** @var string Cache data key for manifest */
    public string $redisDebugManifestKey = 'debug:index';

    /**
     * @var int the maximum number of debug data files to keep. If there are more files generated,
     * the oldest ones will be removed.
     */
    public int $historySize = 50;

    /** @var DebugModule Debug module instance */
    private DebugModule $module;

    /** @var Redis Redis connection component instance */
    private Redis $redis;

    /** @var int Manifest cache data ttl */
    public int $manifestDuration = 10000;

    /** @var int  Debug cache data ttl */
    public int $dataDuration = 3600;

    public function init(): void
    {
        parent::init();

        try {
            $this->redis = Instance::ensure($this->redisComponent, Redis::class);
        } catch (Exception $e) {
            return;
        }
    }

    /**
     * @param string $tag
     *
     * @return array
     */
    public function getData($tag): array
    {
        return $this->redis->exists($this->redisDebugDataKey . $tag) ? json_decode($this->redis->get($this->redisDebugDataKey . $tag), true) : [];
    }

    /**
     * @param string $tag
     * @param array  $data
     *
     * @return void
     */
    public function setData($tag, $data): void
    {
        $this->redis->setex(
            $this->redisDebugDataKey . $tag,
            $this->dataDuration,
            json_encode($data, JSON_UNESCAPED_UNICODE),
        );
        $this->updateIndex($tag, $data['summary'] ?: []);
    }

    /**
     * @param bool $forceReload
     *
     * @return array
     */
    public function getDataManifest($forceReload = false): array
    {
        $manifest = [];

        if ($this->redis->exists($this->redisDebugManifestKey)) {
            $manifest = json_decode($this->redis->get($this->redisDebugManifestKey), true);
            // sort descending by time
            uasort($manifest, static function ($a, $b) {
                return $b['time'] <=> $a['time'];
            });
            $manifestSortedAsc = array_reverse($manifest);

            // warkaround: remove from manifest tags which have been deleted due to
            // cache duration expiration
            $n = count($manifest);
            foreach (array_keys($manifestSortedAsc) as $tag) {
                // if (!$this->cache->exists($this->cacheDebugDataKey . $tag)) { // calling cache server
                if (isset($manifest[$tag])) {
                    if (($manifest[$tag]['time'] + $this->dataDuration) < time()) {
                        unset($manifest[$tag]);
                    } else {
                        break;
                    }
                }
            }

            if ($n > count($manifest)) {
                $this->redis->setex(
                    $this->redisDebugManifestKey,
                    $this->manifestDuration,
                    json_encode($manifest, JSON_UNESCAPED_UNICODE),
                );
            }
        }

        return $manifest;
    }

    /**
     * @param DebugModule $module
     *
     * @return void
     */
    public function setModule($module): void
    {
        $this->module = $module;
    }

    /**
     * @param string $tag
     * @param array $summary
     *
     * @return void
     */
    private function updateIndex(string $tag, array $summary): void
    {
        $manifest = $this->redis->get($this->redisDebugManifestKey);

        if (empty($manifest)) {
            $manifest = [];
        } else {
            $manifest = json_decode($manifest, true);
        }

        $manifest[$tag] = $summary;
        $this->gc($manifest);

        if ($this->manifestDuration <= 0) {
            $this->redis->set(
                $this->redisDebugManifestKey,
                json_encode($manifest, JSON_UNESCAPED_UNICODE),
            );
        } else {
            $this->redis->setex(
                $this->redisDebugManifestKey,
                $this->manifestDuration,
                json_encode($manifest, JSON_UNESCAPED_UNICODE),
            );
        }
    }

    /**
     * Removes obsolete data files
     *
     * @param array $manifest
     */
    protected function gc(array &$manifest)
    {
        if (count($manifest) > $this->historySize + 10) {
            $n = count($manifest) - $this->historySize;
            foreach (array_keys($manifest) as $tag) {
                if (isset($manifest[$tag]['mailFiles'])) {
                    foreach ($manifest[$tag]['mailFiles'] as $mailFile) {
                        @unlink(Yii::getAlias($this->module->panels['mail']->mailPath) . "/{$mailFile}");
                    }
                }
                unset($manifest[$tag]);
                if (--$n <= 0) {
                    break;
                }
            }
        }
    }
}
