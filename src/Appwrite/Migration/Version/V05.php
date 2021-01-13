<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Utopia\Config\Config;
use Utopia\CLI\Console;
use Utopia\Exception;
use Appwrite\Database\Database;
use Appwrite\Database\Document;

class V05 extends Migration
{
  public function execute(): void
  {
    $db = $this->db;
    $project = $this->project;
    $projectDB = $this->projectDB;
    Console::log('Migrating project: ' . $project->getAttribute('name') . ' (' . $project->getId() . ')');

    // Update all documents $uid -> $id

    $limit = 30;
    $sum = 30;
    $offset = 0;

    while ($sum >= 30) {
      $all = $projectDB->getCollection([
        'limit' => $limit,
        'offset' => $offset,
        'orderType' => 'DESC',
      ]);

      $sum = \count($all);

      Console::log('Migrating: ' . $offset . ' / ' . $projectDB->getSum());

      foreach ($all as $document) {
        $document = $this->fixDocument($document);

        if (empty($document->getId())) {
          throw new Exception('Missing ID');
        }

        try {
          $new = $projectDB->overwriteDocument($document->getArrayCopy());
        } catch (\Throwable $th) {
          var_dump($document);
          Console::error('Failed to update document: ' . $th->getMessage());
          continue;
        }

        if ($new->getId() !== $document->getId()) {
          throw new Exception('Duplication Error');
        }
      }

      $offset = $offset + $limit;
    }

    $schema = $_SERVER['_APP_DB_SCHEMA'] ?? '';

    try {
      $statement = $db->prepare("

            CREATE TABLE IF NOT EXISTS `template.database.unique` (
                `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `key` varchar(128) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `index1` (`key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `{$schema}`.`app_{$project->getId()}.database.unique` LIKE `template.database.unique`;
            ALTER TABLE `{$schema}`.`app_{$project->getId()}.audit.audit` DROP COLUMN IF EXISTS `userType`;
            ALTER TABLE `{$schema}`.`app_{$project->getId()}.audit.audit` DROP INDEX IF EXISTS `index_1`;
            ALTER TABLE `{$schema}`.`app_{$project->getId()}.audit.audit` ADD INDEX IF NOT EXISTS `index_1` (`userId` ASC);
        ");

      $statement->closeCursor();

      $statement->execute();
    } catch (\Exception $e) {
      Console::error('Failed to alter table for project: ' . $project->getId() . ' with message: ' . $e->getMessage() . '/');
    }
  }

  private function fixDocument(Document $document)
  {
    $providers = Config::getParam('providers');

    switch ($document->getAttribute('$collection')) {
      case Database::SYSTEM_COLLECTION_PROJECTS:
        foreach ($providers as $key => $provider) {
          if (!empty($document->getAttribute('usersOauth' . \ucfirst($key) . 'Appid'))) {
            $document
              ->setAttribute('usersOauth2' . \ucfirst($key) . 'Appid', $document->getAttribute('usersOauth' . \ucfirst($key) . 'Appid', ''))
              ->removeAttribute('usersOauth' . \ucfirst($key) . 'Appid');
          }

          if (!empty($document->getAttribute('usersOauth' . \ucfirst($key) . 'Secret'))) {
            $document
              ->setAttribute('usersOauth2' . \ucfirst($key) . 'Secret', $document->getAttribute('usersOauth' . \ucfirst($key) . 'Secret', ''))
              ->removeAttribute('usersOauth' . \ucfirst($key) . 'Secret');
          }
        }
        break;

      case Database::SYSTEM_COLLECTION_PROJECTS:
      case Database::SYSTEM_COLLECTION_TASKS:
        $document->setAttribute('security', ($document->getAttribute('security')) ? true : false);
        break;

      case Database::SYSTEM_COLLECTION_USERS:
        foreach ($providers as $key => $provider) {
          if (!empty($document->getAttribute('oauth' . \ucfirst($key)))) {
            $document
              ->setAttribute('oauth2' . \ucfirst($key), $document->getAttribute('oauth' . \ucfirst($key), ''))
              ->removeAttribute('oauth' . \ucfirst($key));
          }

          if (!empty($document->getAttribute('oauth' . \ucfirst($key) . 'AccessToken'))) {
            $document
              ->setAttribute('oauth2' . \ucfirst($key) . 'AccessToken', $document->getAttribute('oauth' . \ucfirst($key) . 'AccessToken', ''))
              ->removeAttribute('oauth' . \ucfirst($key) . 'AccessToken');
          }
        }

        if ($document->getAttribute('confirm', null) !== null) {
          $document
            ->setAttribute('emailVerification', $document->getAttribute('confirm', $document->getAttribute('emailVerification', false)))
            ->removeAttribute('confirm');
        }
        break;

      case Database::SYSTEM_COLLECTION_PLATFORMS:
        if ($document->getAttribute('url', null) !== null) {
          $document
            ->setAttribute('hostname', \parse_url($document->getAttribute('url', $document->getAttribute('hostname', '')), PHP_URL_HOST))
            ->removeAttribute('url');
        }
        break;
    }

    $document
      ->setAttribute('$id', $document->getAttribute('$uid', $document->getAttribute('$id')))
      ->removeAttribute('$uid');

    foreach ($document as &$attr) { // Handle child documents
      if ($attr instanceof Document) {
        $attr = $this->fixDocument($attr);
      }

      if (\is_array($attr)) {
        foreach ($attr as &$child) {
          if ($child instanceof Document) {
            $child = $this->fixDocument($child);
          }
        }
      }
    }

    return $document;
  }
}
