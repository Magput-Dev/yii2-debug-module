<?php

/**
 * @link https://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace Magput\Debug\data;

use Exception;
use Yii;
use yii\base\Component;
use yii\caching\Cache;
use yii\debug\Module;
use yii\di\Instance;

/**
 *  CacheDataStorage
 */
class CacheDataStorage extends Component implements DataStorage
{
    /**
     * @var Module Debug module instance
     */
    private $module;

    /**
     * @var string Cache component di identifier
     */
    public $cacheComponent = 'cache';

    /**
     * @var string Cache data key for debug data
     */
    public $cacheDebugDataKey = 'debug:';

    /**
     * @var string Cache data key for manifest
     */
    public $cacheDebugManifestKey = 'debug:index';

    /**
     * @var int the maximum number of debug data files to keep. If there are more files generated,
     * the oldest ones will be removed.
     */
    public $historySize = 50;

    /**
     * @var Cache Cache component instance
     */
    private $cache;

    /**
     * @var int Manifest cache data ttl
     */
    public $manifestDuration = 10000;

    /**
     * @var int  Debug cache data ttl
     */
    public $dataDuration = 3600;

    /**
     * @return void
     */
    public function init()
    {
        parent::init();

        try {
            $this->cache = Instance::ensure($this->cacheComponent, 'yii\caching\Cache');
        } catch (Exception $e) {
            return;
        }
    }

    /**
     * @param string $tag
     *
     * @return array
     */
    public function getData($tag)
    {
        return $this->cache->exists($this->cacheDebugDataKey . $tag) ? unserialize($this->cache->get($this->cacheDebugDataKey . $tag)) : [];
    }

    /**
     * @param string $tag
     * @param array  $data
     *
     * @return mixed|void
     */
    public function setData($tag, $data)
    {
        $this->cache->set($this->cacheDebugDataKey . $tag, serialize($data), $this->dataDuration);
        $this->updateIndex($tag, $data['summary'] ?: []);
    }

    /**
     * @param $forceReload
     *
     * @return array|mixed
     */
    public function getDataManifest($forceReload = false)
    {
        $manifest = [];

        if ($this->cache->exists($this->cacheDebugManifestKey)) {
            $manifest = unserialize($this->cache->get($this->cacheDebugManifestKey));
            // sort descending by time
            uasort($manifest, function ($a, $b) {
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
                $this->cache->set($this->cacheDebugManifestKey, serialize($manifest), $this->manifestDuration);
            }
        }

        return $manifest;
    }

    /**
     * @param Module $module
     *
     * @return mixed|void
     */
    public function setModule($module)
    {
        $this->module = $module;
    }

    /**
     * @param string $tag
     * @param        $summary
     *
     * @return void
     */
    private function updateIndex($tag, $summary)
    {
        $manifest = $this->cache->get($this->cacheDebugManifestKey);

        if (empty($manifest)) {
            $manifest = [];
        } else {
            $manifest = unserialize($manifest);
        }

        $manifest[$tag] = $summary;
        $this->gc($manifest);

        $this->cache->set($this->cacheDebugManifestKey, serialize($manifest), $this->manifestDuration);
    }

    /**
     * Removes obsolete data files
     *
     * @param array $manifest
     */
    protected function gc(&$manifest)
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
