<?php

namespace Appwrite\Migration;

use Appwrite\Database\Document as OldDocument;
use Appwrite\Database\Database as OldDatabase;
use PDO;
use Redis;
use Swoole\Runtime;
use Utopia\CLI\Console;
use Utopia\Exception;

abstract class Migration
{
    /**
     * @var array
     */
    protected array $options;

    /**
     * @var PDO
     */
    protected PDO $db;

    /**
     * @var Redis
     */
    protected Redis $cache;

    /**
     * @var int
     */
    protected int $limit = 500;

    /**
     * @var OldDocument
     */
    protected OldDocument $project;

    /**
     * @var OldDatabase
     */
    protected OldDatabase $oldProjectDB;

    /**
     * @var OldDatabase
     */
    protected OldDatabase $oldConsoleDB;

    /**
     * @var array
     */
    public static array $versions = [
        '0.6.0' => 'V05',
        '0.7.0' => 'V06',
        '0.8.0' => 'V07',
        '0.9.0' => 'V08',
        '0.9.1' => 'V08',
        '0.9.2' => 'V08',
        '0.9.3' => 'V08',
        '0.9.4' => 'V08',
        '0.10.0' => 'V09',
        '0.10.1' => 'V09',
        '0.10.2' => 'V09',
        '0.10.3' => 'V09',
        '0.10.4' => 'V09',
        '0.11.0' => 'V10',
        '0.12.0' => 'V11',
    ];

    /**
     * Migration constructor.
     *
     * @param PDO $db
     * @param Redis|null $cache
     * @param array $options
     * @return void 
     */
    public function __construct(PDO $db, Redis $cache = null, array $options = [])
    {
        $this->options = $options;
        $this->db = $db;
        if (!is_null($cache)) {
            $this->cache = $cache;
        }
    }

    /**
     * Set project for migration.
     *
     * @param OldDocument $project
     * @param OldDatabase $projectDB
     * @param OldDatabase $oldConsoleDB
     *
     * @return self
     */
    public function setProject(OldDocument $project, OldDatabase $projectDB, OldDatabase $oldConsoleDB): self
    {
        $this->project = $project;

        $this->oldProjectDB = $projectDB;
        $this->oldProjectDB->setNamespace('app_' . $project->getId());

        $this->oldConsoleDB = $oldConsoleDB;

        return $this;
    }

    /**
     * Iterates through every document.
     *
     * @param callable $callback
     */
    public function forEachDocument(callable $callback): void
    {
        $sum = $this->limit;
        $offset = 0;

        while ($sum >= $this->limit) {
            $all = $this->projectDB->getCollection([
                'limit' => $this->limit,
                'offset' => $offset,
                'orderType' => 'DESC',
            ]);

            $sum = \count($all);
            Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

            Console::log('Migrating: ' . $offset . ' / ' . $this->projectDB->getSum());
            \Co\run(function () use ($all, $callback) {
                foreach ($all as $document) {
                    go(function () use ($document, $callback) {
                        if (empty($document->getId()) || empty($document->getCollection())) {
                            if ($document->getCollection() !== 0) {
                                Console::warning('Skipped Document due to missing ID or Collection.');
                            }
                            return;
                        }

                        $old = $document->getArrayCopy();
                        $new = call_user_func($callback, $document);

                        if (!$this->check_diff_multi($new->getArrayCopy(), $old)) {
                            return;
                        }

                        try {
                            $new = $this->projectDB->overwriteDocument($new->getArrayCopy());
                        } catch (\Throwable $th) {
                            Console::error('Failed to update document: ' . $th->getMessage());
                            return;

                            if ($document && $new->getId() !== $document->getId()) {
                                throw new Exception('Duplication Error');
                            }
                        }
                    });
                }
            });

            $offset += $this->limit;
        }
    }

    /**
     * Checks 2 arrays for differences.
     * 
     * @param array $array1 
     * @param array $array2 
     * @return array 
     */
    public function check_diff_multi(array $array1, array $array2): array
    {
        $result = array();

        foreach ($array1 as $key => $val) {
            if (is_array($val) && isset($array2[$key])) {
                $tmp = $this->check_diff_multi($val, $array2[$key]);
                if ($tmp) {
                    $result[$key] = $tmp;
                }
            } elseif (!isset($array2[$key])) {
                $result[$key] = null;
            } elseif ($val !== $array2[$key]) {
                $result[$key] = $array2[$key];
            }

            if (isset($array2[$key])) {
                unset($array2[$key]);
            }
        }

        $result = array_merge($result, $array2);

        return $result;
    }

    /**
     * Executes migration for set project.
     */
    abstract public function execute(): void;
}
