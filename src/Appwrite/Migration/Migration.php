<?php

namespace Appwrite\Migration;

use Appwrite\Database\Document;
use Appwrite\Database\Database;
use Utopia\CLI\Console;
use Utopia\Exception;
use PDO;

abstract class Migration
{
    /**
     * @var PDO
     */
    protected PDO $db;

    /**
     * @var int
     */
    protected int $limit = 30;

    /**
     * @var Document
     */
    protected Document $project;

    /**
     * @var Database
     */
    protected Database $projectDB;

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

            Console::log('Migrating: ' . $offset . ' / ' . $this->projectDB->getSum());

            foreach ($all as $document) {

                $document = call_user_func($callback, $document);

                if (empty($document->getId())) {
                    throw new Exception('Missing ID');
                }

                try {
                    $new = $this->projectDB->overwriteDocument($document->getArrayCopy());
                } catch (\Throwable $th) {
                    Console::error('Failed to update document: ' . $th->getMessage());
                    continue;
                }

                if ($new->getId() !== $document->getId()) {
                    throw new Exception('Duplication Error');
                }
            }

            $offset += $this->limit;
        }
    }

    /**
     * Executes migration for set project.
     */
    abstract public function execute(): void;
}
