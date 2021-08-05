<?php

namespace Appwrite\Migration;

use Appwrite\Database\Document;
use Appwrite\Database\Database;
use PDO;
use Swoole\Runtime;
use Utopia\CLI\Console;
use Utopia\Exception;

abstract class Migration
{
    /**
     * @var PDO
     */
    protected $db;

    /**
     * @var int
     */
    protected $limit = 50;

    /**
     * @var Document
     */
    protected $project;

    /**
     * @var Database
     */
    protected $projectDB;

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
        '0.10.0' => 'V08', // TODO Eldad: `I need this to pass the tests`
    ];

    /**
     * Migration constructor.
     * 
     * @param PDO $pdo
     */
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Set project for migration.
     * 
     * @param Document $project
     * @param Database $projectDB
     * 
     * @return Migration 
     */
    public function setProject(Document $project, Database $projectDB): Migration
    {
        $this->project = $project;
        $this->projectDB = $projectDB;
        $this->projectDB->setNamespace('app_' . $project->getId());
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

                        $old = $document->getArrayCopy();
                        $new = call_user_func($callback, $document);

                        if (empty($new->getId())) {
                            Console::warning('Skipped Document due to missing ID.');
                            return;
                        }

                        if (!$this->check_diff_multi($new->getArrayCopy(), $old)) {
                            return;
                        }

                        try {
                            $new = $this->projectDB->overwriteDocument($document->getArrayCopy());
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

    public function check_diff_multi($array1, $array2){
        $result = array();
    
        foreach($array1 as $key => $val) {
            if(is_array($val) && isset($array2[$key])) {
                $tmp = $this->check_diff_multi($val, $array2[$key]);
                if($tmp) {
                    $result[$key] = $tmp;
                }
            }
            elseif(!isset($array2[$key])) {
                $result[$key] = null;
            }
            elseif($val !== $array2[$key]) {
                $result[$key] = $array2[$key];
            }
    
            if(isset($array2[$key])) {
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
