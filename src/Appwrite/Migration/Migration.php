<?php

namespace Appwrite\Migration;

use Swoole\Runtime;
use Utopia\Database\Document;
use Utopia\Database\Database;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Exception;

abstract class Migration
{
    /**
     * @var int
     */
    protected int $limit = 100;

    /**
     * @var Document
     */
    protected Document $project;

    /**
     * @var Database
     */
    protected Database $projectDB;

    /**
     * @var Database
     */
    protected Database $consoleDB;

    /**
     * @var array
     */
    public static array $versions = [
        '0.13.0' => 'V12',
        '0.13.1' => 'V12',
    ];

    /**
     * @var array
     */
    protected array $collections;

    public function __construct()
    {
        $this->collections = array_merge([
            '_metadata' => [
                '$id' => '_metadata'
            ],
            'audit' => [
                '$id' => 'audit'
            ],
            'abuse' => [
                '$id' => 'abuse'
            ]
        ], Config::getParam('collections', []));
    }

    /**
     * Set project for migration.
     *
     * @param Document $project
     * @param Database $projectDB
     * @param Database $oldConsoleDB
     *
     * @return self
     */
    public function setProject(Document $project, Database $projectDB, Database $consoleDB): self
    {
        $this->project = $project;
        $this->projectDB = $projectDB;
        $this->projectDB->setNamespace('_' . $this->project->getId());

        $this->consoleDB = $consoleDB;

        return $this;
    }

    /**
     * Iterates through every document.
     *
     * @param callable $callback
     */
    public function forEachDocument(callable $callback): void
    {
        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        foreach ($this->collections as $collection) {
            $sum = 0;
            $nextDocument = null;
            $collectionCount = $this->projectDB->count($collection['$id']);
            Console::log('Migrating Collection ' . $collection['$id'] . ':');

            do {
                $documents = $this->projectDB->find($collection['$id'], limit: $this->limit, cursor: $nextDocument);
                $count = count($documents);
                $sum += $count;

                Console::log($sum . ' / ' . $collectionCount);

                \Co\run(function (array $documents, callable $callback) {
                    foreach ($documents as $document) {
                        go(function (Document $document, callable $callback) {
                            if (empty($document->getId()) || empty($document->getCollection())) {
                                return;
                            }

                            $old = $document->getArrayCopy();
                            $new = call_user_func($callback, $document);

                            foreach ($document as &$attr) {
                                if ($attr instanceof Document) {
                                    $attr = call_user_func($callback, $attr);
                                }

                                if (\is_array($attr)) {
                                    foreach ($attr as &$child) {
                                        if ($child instanceof Document) {
                                            $child = call_user_func($callback, $child);
                                        }
                                    }
                                }
                            }

                            if (!$this->check_diff_multi($new->getArrayCopy(), $old)) {
                                return;
                            }

                            try {
                                $new = $this->projectDB->updateDocument($document->getCollection(), $document->getId(), $document);
                            } catch (\Throwable $th) {
                                Console::error('Failed to update document: ' . $th->getMessage());
                                return;

                                if ($document && $new->getId() !== $document->getId()) {
                                    throw new Exception('Duplication Error');
                                }
                            }
                        }, $document, $callback);
                    }
                }, $documents, $callback);

                if ($count !== $this->limit) {
                    $nextDocument = null;
                } else {
                    $nextDocument = end($documents);
                }
            } while (!is_null($nextDocument));
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
