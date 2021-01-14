<?php

namespace Appwrite\Migration;

use Appwrite\Database\Document;
use Appwrite\Database\Database;
use Utopia\CLI\Console;
use Utopia\Exception;

abstract class Migration
{
    protected \PDO $db;

    protected int $limit = 30;
    protected int $sum = 30;
    protected int $offset = 0;
    protected Document $project;
    protected Database $projectDB;

    /**
     * Migration constructor.
     */
    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Set project for migration.
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
     * @param function(Document): Document $callback
     */
    public function forEachDocument(callable $callback)
    {
        while ($this->sum >= 30) {
            $all = $this->projectDB->getCollection([
                'limit' => $this->limit,
                'offset' => $this->offset,
                'orderType' => 'DESC',
            ]);

            $this->sum = \count($all);

            Console::log('Migrating: ' . $this->offset . ' / ' . $this->projectDB->getSum());

            foreach ($all as $document) {

                $document = call_user_func($callback, $document);

                if (empty($document->getId())) {
                    throw new Exception('Missing ID');
                }

                try {
                    $new = $this->projectDB->overwriteDocument($document->getArrayCopy());
                } catch (\Throwable $th) {
                    var_dump($document);
                    Console::error('Failed to update document: ' . $th->getMessage());
                    continue;
                }

                if ($new->getId() !== $document->getId()) {
                    throw new Exception('Duplication Error');
                }
            }

            $this->offset = $this->offset + $this->limit;
        }
    }

    /**
     * Executes migration for set project.
     */
    abstract public function execute(): void;
}
