<?php

declare(strict_types=1);

namespace Magput\Debug\panels;

use yii\debug\panels\DbPanel;

/**
 * Debugger panel that collects and displays database queries performed.
 *
 * @property-read array $excessiveCallers The number of DB calls indexed by the backtrace hash of excessive
 * caller(s).
 * @property-read array $profileLogs
 * @property-read string $summaryName Short name of the panel, which will be use in summary.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class CustomDbPanel extends DbPanel
{
    /**
     * @var array of event names used to get profile logs.
     */
    public $dbEventNames = [
        'yii\db\Command::query',
        'yii\db\Command::execute',
        'vendor/doctrine/dbal/src/Driver/PDO/Statement::execute'
    ];

    /**
     * Returns total query time.
     *
     * @param array $timings
     * @return int total time
     */
    public function getTotalQueryTime($timings): int
    {
        $queryTime = 0;

        foreach ($timings as $timing) {
            $queryTime += $timing['duration'];
        }

        return (int)$queryTime;
    }
}
