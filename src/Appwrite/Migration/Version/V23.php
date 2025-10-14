<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Throwable;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Structure;

class V23 extends Migration
{
    /**
     * @throws Throwable
     */
    public function execute(): void
    {
        Console::info('Migrating buckets collection to add transformations attribute');
        $this->migrateBucketsTransformations();
        
        Console::info('Migration V23 completed');
    }

    /**
     * Migrate buckets collection to add transformations attribute and clean up imageTransformations
     *
     * @return void
     * @throws Throwable
     */
    private function migrateBucketsTransformations(): void
    {
        $this->dbForProject->setNamespace("_{$this->project->getSequence()}");

        try {
            // First, try to get the buckets collection to see current schema
            $bucketsCollection = $this->dbForProject->getCollection('buckets');
            
            if ($bucketsCollection->isEmpty()) {
                Console::warning('Buckets collection not found, skipping migration');
                return;
            }

            $hasImageTransformations = false;
            $hasTransformations = false;

            // Check what attributes currently exist
            foreach ($bucketsCollection->getAttribute('attributes', []) as $attribute) {
                if ($attribute['$id'] === 'imageTransformations') {
                    $hasImageTransformations = true;
                }
                if ($attribute['$id'] === 'transformations') {
                    $hasTransformations = true;
                }
            }

            Console::log("Current attribute status: imageTransformations=" . ($hasImageTransformations ? 'exists' : 'missing') . 
                        ", transformations=" . ($hasTransformations ? 'exists' : 'missing'));

            // Scenario 1: Only imageTransformations exists (most common case)
            if ($hasImageTransformations && !$hasTransformations) {
                Console::log('Renaming imageTransformations to transformations...');
                
                // Rename the attribute from imageTransformations to transformations
                $this->dbForProject->renameAttribute('buckets', 'imageTransformations', 'transformations');
                
                // Update any indexes that reference the old field name
                try {
                    $this->dbForProject->deleteIndex('buckets', '_key_imageTransformations');
                } catch (Throwable $th) {
                    Console::warning("Could not delete old imageTransformations index: {$th->getMessage()}");
                }
                
                // Create new index for transformations
                try {
                    $this->dbForProject->createIndex('buckets', '_key_transformations', Database::INDEX_KEY, ['transformations'], [Database::ORDER_ASC]);
                } catch (Throwable $th) {
                    Console::warning("Could not create transformations index: {$th->getMessage()}");
                }
                
                Console::log('✅ Successfully renamed imageTransformations to transformations');
            }
            // Scenario 2: Only transformations exists (already migrated)
            elseif (!$hasImageTransformations && $hasTransformations) {
                Console::log('✅ Transformations attribute already exists, no migration needed');
            }
            // Scenario 3: Both exist (conflict resolution)
            elseif ($hasImageTransformations && $hasTransformations) {
                Console::log('Both attributes exist, removing imageTransformations...');
                
                // Delete the old imageTransformations attribute
                try {
                    $this->dbForProject->deleteAttribute('buckets', 'imageTransformations');
                    Console::log('✅ Removed duplicate imageTransformations attribute');
                } catch (Throwable $th) {
                    Console::warning("Could not remove imageTransformations: {$th->getMessage()}");
                }
                
                // Delete old index if it exists
                try {
                    $this->dbForProject->deleteIndex('buckets', '_key_imageTransformations');
                } catch (Throwable $th) {
                    Console::warning("Could not delete old imageTransformations index: {$th->getMessage()}");
                }
            }
            // Scenario 4: Neither exists (create fresh)
            else {
                Console::log('Creating fresh transformations attribute...');
                
                // Create the transformations attribute from scratch
                $this->dbForProject->createAttribute(
                    collection: 'buckets',
                    id: 'transformations',
                    type: Database::VAR_BOOLEAN,
                    size: 0,
                    required: false,
                    default: true,
                    signed: true,
                    array: false,
                    format: '',
                    filters: []
                );
                
                // Create index for the new attribute
                try {
                    $this->dbForProject->createIndex('buckets', '_key_transformations', Database::INDEX_KEY, ['transformations'], [Database::ORDER_ASC]);
                } catch (Throwable $th) {
                    Console::warning("Could not create transformations index: {$th->getMessage()}");
                }
                
                Console::log('✅ Created transformations attribute');
            }

            // Purge the collection cache to ensure changes are reflected
            $this->dbForProject->purgeCachedCollection('buckets');
            
            // Verify all existing buckets have the transformations field with a default value
            Console::log('Ensuring all existing buckets have transformations field...');
            
            foreach ($this->documentsIterator('buckets') as $bucket) {
                $transformationsValue = $bucket->getAttribute('transformations');
                
                // If transformations field is missing or null, set default value
                if (is_null($transformationsValue)) {
                    Console::log("Setting default transformations=true for bucket: {$bucket->getId()}");
                    
                    $bucket->setAttribute('transformations', true);
                    $this->dbForProject->updateDocument('buckets', $bucket->getId(), $bucket);
                }
            }
            
            Console::log('✅ Buckets transformations migration completed successfully');
            
        } catch (Throwable $th) {
            Console::error("Buckets transformations migration failed: {$th->getMessage()}");
            throw $th;
        }
    }
}