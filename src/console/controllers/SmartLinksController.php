<?php

namespace Tahadudhiya\SmartLinks\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use Tahadudhiya\SmartLinks\models\IndexingResult;
use Tahadudhiya\SmartLinks\SmartLinks;
use yii\console\ExitCode;

/**
 * Smart Links from the command line: `php craft smartlinks/<action>`.
 */
class SmartLinksController extends Controller
{
    /** @var bool Whether to rebuild in this process, rather than queue the rebuild. */
    public bool $now = false;

    public $defaultAction = 'index-status';

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'reindex') {
            $options[] = 'now';
        }

        return $options;
    }

    /**
     * Rebuilds the link index from all Smart Link field content. Queued, unless --now is given.
     */
    public function actionReindex(): int
    {
        $index = SmartLinks::getInstance()->getIndex();

        if (!$this->now) {
            $index->queueRebuild();
            $this->stdout("The rebuild is queued.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $result = $index->rebuild(function(int $done, int $total): void {
            Console::updateProgress($done, max($total, $done, 1));
        });
        Console::endProgress();

        return $this->report($result);
    }

    /**
     * Shows what the link index holds and how far it is behind content. Exits with 1 if it is.
     */
    public function actionIndexStatus(): int
    {
        $status = SmartLinks::getInstance()->getIndex()->status();

        $this->stdout(sprintf("Targets: %d\nLink occurrences: %d\nElement sites read: %d\n", $status->targets, $status->usages, $status->sources));

        if (!$status->isStale()) {
            $this->stdout("The index is up to date with content.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout("The index is behind content:\n", Console::FG_YELLOW);

        foreach ([
            'Element sites saved since they were read, or never read' => $status->unindexedSources,
            'Element sites with a value that can’t be read (links as last read)' => $status->unreadableSources,
            'Element sites read that are no longer sources' => $status->orphanedSources,
            'Link occurrences no source or field accounts for' => $status->orphanedUsages,
            'Targets no link uses' => $status->unusedTargets,
            'Targets whose element or site is gone' => $status->outdatedTargets,
        ] as $label => $count) {
            if ($count > 0) {
                $this->stdout(sprintf("  %s: %d\n", $label, $count));
            }
        }

        $this->stdout("Run `php craft smartlinks/reindex` to rebuild it.\n");

        return ExitCode::UNSPECIFIED_ERROR;
    }

    private function report(IndexingResult $result): int
    {
        $this->stdout(sprintf(
            "Read %d element sites and %d links; removed %d element sites, %d link occurrences and %d targets no longer used.\n",
            $result->sources,
            $result->links,
            $result->removedSources,
            $result->removedUsages,
            $result->removedTargets,
        ), Console::FG_GREEN);

        if ($result->problemCount === 0) {
            return ExitCode::OK;
        }

        $this->stderr(sprintf("%d problems (also logged):\n", $result->problemCount), Console::FG_YELLOW);

        foreach ($result->problems as $problem) {
            $this->stderr("  $problem\n");
        }

        return ExitCode::UNSPECIFIED_ERROR;
    }
}
