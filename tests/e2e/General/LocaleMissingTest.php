<?php

namespace Tests\E2E\General;

use Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectNone;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideNone;
use Utopia\CLI\CLI;
use Utopia\CLI\Console;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_push;
use function count;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function pathinfo;
use function realpath;
use function scandir;
use function unlink;
use function var_dump;
use const PATHINFO_EXTENSION;

class LocaleMissingTest extends Scope
{
    use ProjectNone;
    use SideNone;

    public function testSourceCode()
    {
        /**
         * Test for SUCCESS
         */

        // Define recursive function that returns all files inside specific folder
        // This function also filters to only PHP and PHTML files
        function getFilesRecursively(string $dir): array {
            $filesInCurrentDirectory = scandir($dir);
            $allFiles = [];

            foreach ($filesInCurrentDirectory as $file) {
                if($file == '.' || $file == '..') {
                    continue;
                }

                $fileFullPath = $dir . '/' . $file;

                if(is_dir($fileFullPath)) {
                    array_push($allFiles, ...getFilesRecursively($fileFullPath));
                    continue;
                }

                $fileExtention = pathinfo($file, PATHINFO_EXTENSION);
                if($fileExtention !== "php" && $fileExtention !== "phtml") {
                    continue;
                }

                array_push($allFiles, $fileFullPath);
            }

            return $allFiles;
        }

        // Fetch list of all files we want to check
        $filesToCheck = [];
        $sourceCodePaths = ['src', 'app'];

        foreach ($sourceCodePaths as $sourceCodePath) {
            array_push($filesToCheck, ...getFilesRecursively($sourceCodePath));
        }

        // Get all translations that are used in the code
        $translationKeys = [];
        $invalidChunks = [];

        foreach ($filesToCheck as $fileToCheck) {
            $fileContent = file_get_contents($fileToCheck);

            // Split string into a way that each item starts with the translation key
            // The item may start with ' or "
            $fileChunks = explode('->getText(', $fileContent);

            // If we split and get 1 item, it means string has not been found
            if(count($fileChunks) <= 1) {
                continue;
            }

            // Remove first item because this is chunk without ->getText
            array_shift($fileChunks);

            // Fill $translationKeys from the file code
            foreach ($fileChunks as $fileChunk) {
                $separatorCharacter = $fileChunk[0];

                if($separatorCharacter !== '\'' && $separatorCharacter !== '"') {
                    // Ignore $ prefix. If used correctly, that is a good use of dynamic key
                    if($separatorCharacter == '$') {
                        continue;
                    }

                    // Invalid chunk, might mean dynamic key. Store it and show warning later
                    if (!in_array($fileToCheck, $invalidChunks)) {
                        array_push($invalidChunks, $fileToCheck);
                    }
                    continue;
                }

                // Remove first character
                $fileChunk = substr($fileChunk, 1);

                $keyEndIndex = -1;

                // Find the end of the key definition
                $chunkLetters = str_split($fileChunk);
                $keyIndex = 0;
                foreach($chunkLetters as $chunkLetter){
                    if($separatorCharacter == $chunkLetter) {
                        $keyEndIndex = $keyIndex;
                        break;
                    }

                    $keyIndex++;
                }

                // Separate translation key from the chunk
                $translationKey = $result = substr($fileChunk, 0, $keyEndIndex);

                // Fill the array only without duplicates
                if (!in_array($translationKey, $translationKeys)) {
                    $translationKeys[$translationKey] = $fileToCheck;
                }
            }
        }

        // Print warnings for files we could not parse
        $invalidChunksAmount = count($invalidChunks);
        if($invalidChunksAmount > 0) {
           Console::warning("Found {$invalidChunksAmount} files we could not parse:");
           var_dump($invalidChunks);
        } else {
            Console::success("No invalid files found");
        }

        // Find translation keys that are not represented in en.json
        $missingKeys = [];

        $translations = json_decode(file_get_contents("app/config/locale/translations/en.json"),true);
        foreach ($translationKeys as $translationKey => $translationFile) {
            // Temporary solution for dynamic keys 'continents' and 'countries'
            // TODO: Remove temporary fix and implement it in a smarter way
            if($translationKey == 'countries.' || $translationKey == 'continents.') {
                continue;
            }

            // Mark as missing key if not found in translation JSON
            if(!array_key_exists($translationKey, $translations)) {
                array_push($missingKeys, $translationKey);
            }
        }



        if(count($missingKeys) > 0) {
            Console::log('List of missing keys:');
            var_dump($missingKeys);
        } else {
            Console::success("No missing translation keys");
        }

        $this->assertEmpty($invalidChunks);
        $this->assertEmpty($missingKeys);
    }
}
