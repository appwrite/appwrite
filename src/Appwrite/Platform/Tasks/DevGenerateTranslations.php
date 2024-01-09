<?php

namespace Appwrite\Platform\Tasks;

use Exception;
use Utopia\CLI\Console;
use Utopia\Fetch\Client;
use Utopia\Platform\Action;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class DevGenerateTranslations extends Action
{
    private string $apiKey = '';

    public static function getName(): string
    {
        return 'dev-generate-translations';
    }

    public function __construct()
    {
        $this
            ->desc('Generate translations in all languages')
            ->param('dry-run', true, new Boolean(true), 'If action should do a dry run. Dry run does not write into files', true)
            ->param('api-key', '', new Text(256), 'Open AI API key. Only used during non-dry runs to generate translations.', true)
            ->callback(fn ($dryRun, $apiKey) => $this->action($dryRun, $apiKey));
    }

    public function action(bool|string $dryRun, string $apiKey): void
    {
        $dryRun = \strval($dryRun) === 'true';

        Console::info("Started");

        if(!$dryRun && empty($apiKey)) {
            Console::error("Please specify --api-key='OPEN_AI_API_KEY' or run with --dry-run");
            return;
        }

        $this->apiKey = $apiKey;

        $dir = __DIR__ . '/../../../../app/config/locale/translations';
        $mainFile = 'en.json';

        $mainJson = \json_decode(\file_get_contents($dir . '/' . $mainFile), true);
        $mainKeys = \array_keys($mainJson);

        $files = array_diff(scandir($dir), array('.', '..', $mainFile));

        foreach ($files as $file) {
            $fileJson = \json_decode(\file_get_contents($dir . '/' . $file), true);
            $fileKeys = \array_keys($fileJson);

            foreach ($mainKeys as $key) {
                if(!(\in_array($key, $fileKeys))) {
                    if($dryRun) {
                        Console::warning("{$file} missing translation for {$key}");
                    } else {
                        $language = \explode('.', $file)[0];
                        $translation = $this->generateTranslation($language, $mainJson[$key]);

                        $json = \json_decode(\file_get_contents($dir . '/' . $file), true);
                        $json[$key] = $translation;
                        \file_put_contents($dir . '/' . $file, \json_encode($json, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|0));

                        Console::success("Generated {$key} for {$language}");
                    }
                }
            }
        }

        Console::info("Done");
    }

    private function generateTranslation(string $targetLanguage, string $enTranslation): string
    {
        $response = Client::fetch('https://api.openai.com/v1/chat/completions', [
            'content-type' => Client::CONTENT_TYPE_APPLICATION_JSON,
            'Authorization' => 'Bearer ' . $this->apiKey
        ], Client::METHOD_POST, [
            'model' => 'gpt-4-1106-preview', // https://platform.openai.com/docs/models/gpt-4-and-gpt-4-turbo
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "Please translate the message user provides from English language to target language. Target language is language of country with country code {$targetLanguage}. Do not translate text inside {{ and }} placeholders. Provide only translated text."
                ],
                [
                    'role' => 'user',
                    'content' => $enTranslation
                ]
            ]
        ]);

        $body = \json_decode($response->getBody(), true);

        if($response->getStatusCode() >= 400) {
            throw new Exception($response->getBody());
        }

        return $body['choices'][0]['message']['content'];
    }
}
