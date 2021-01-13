<?php

namespace Appwrite\Migration;

use Appwrite\Database\Document;
use Appwrite\Database\Database;

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
    $this->projectDB->setNamespace('app_'.$project->getId());
    return $this;
  }

  /**
   * Executes migration for set project.
   */
  abstract public function execute(): void;
}
