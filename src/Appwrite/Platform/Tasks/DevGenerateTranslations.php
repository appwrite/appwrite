<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Agents\Adapters\OpenAI;
use Utopia\Agents\Agent;
use Utopia\Agents\Conversation;
use Utopia\Agents\Messages\Text as AgentText;
use Utopia\Agents\Roles\Assistant;
use Utopia\Agents\Roles\User;
use Utopia\Agents\Schema;
use Utopia\Agents\Schema\SchemaObject;
use Utopia\CLI\Console;
use Utopia\Platform\Action;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class DevGenerateTranslations extends Action
{
    public static function getName(): string
    {
        return 'dev-generate-translations';
    }

    public function __construct()
    {
        $this
            ->desc('Generate missing translations in all locales. This task does not translate english keys.')
            ->param('dry-run', 'true', new Boolean(true), 'If action should do a dry run. Dry run only lists missing translations', true)
            ->param('api-key', '', new Text(256), 'Open AI API key. Only used during non-dry runs to generate translations.', true)
            ->callback($this->action(...));
    }

    public function action(mixed $dryRun, string $apiKey): void
    {
        $dryRun = \strval($dryRun) === 'true';

        Console::info("Started");

        if (!$dryRun && empty($apiKey)) {
            Console::error("Please specify --api-key=\"YOUR_KEY\" or run with --dry-run=true");
            return;
        }

        $dir = __DIR__ . '/../../../../app/config/locale/translations';
        $mainFile = 'en.json';

        $mainJson = \json_decode(\file_get_contents($dir . '/' . $mainFile), true);
        $mainKeys = \array_keys($mainJson);

        $files = \array_diff(\scandir($dir), array('.', '..', $mainFile));

        foreach ($files as $file) {
            Console::log('Processing ' . $file);

            $fileJson = \json_decode(\file_get_contents($dir . '/' . $file), true);
            $fileKeys = \array_keys($fileJson);

            $missingKeys = [];
            foreach ($mainKeys as $key) {
                if (!(\in_array($key, $fileKeys))) {
                    $missingKeys[] = $key;
                }
            }

            if (\count($missingKeys) > 0) {
                Console::warning(\count($missingKeys) . ' missing keys in ' . $file);

                if ($dryRun) {
                    foreach ($missingKeys as $missingKey) {
                        Console::log('Missing translation for key ' . $missingKey);
                    }
                } else {
                    $language = \explode('.', $file)[0];

                    foreach ($missingKeys as $missingKey) {
                        $json = \json_decode(\file_get_contents($dir . '/' . $file), true);

                        $translation = $this->generateTranslation($language, $mainJson[$missingKey], $apiKey);

                        Console::log('Translation results:');
                        Console::log('English: ' . $mainJson[$missingKey]);
                        Console::log($language . ': ' . $translation);

                        // This puts new key at beginning to prevent merge conflict issue and ending comma
                        $newPair = [];
                        $newPair[$missingKey] = $translation;

                        if (isset($json[$missingKey])) {
                            unset($json[$missingKey]);
                        }

                        $json = \array_merge($newPair, $json);

                        \file_put_contents($dir . '/' . $file, \json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | 0));
                    }
                }
            }
        }

        Console::info("Done");
    }

    private function generateTranslation(string $targetLanguage, string $enTranslation, string $apiKey): string
    {
        // Replace placeholders with <m id=x label="y" /> to prevent AI from translating them, but still provide context
        $placeholders = [];

        $id = 0;
        $pattern = '/{{\w+}}/';

        $enTranslation = preg_replace_callback($pattern, function ($match) use (&$id, &$placeholders) {
            $placeholders[$id] = $match[0];
            $label = \trim($match[0], "{}");
            $key = "<m id={$id} label=\"{$label}\" />";
            $id++;
            return $key;
        }, $enTranslation);

        // Talk to AI
        $object = new SchemaObject();
        $object->addProperty('translation', [
            'type' => SchemaObject::TYPE_STRING,
            'description' => 'The translation output in ' . $targetLanguage . ' language.',
        ]);
        $schema = new Schema(
            name: 'get_translation',
            description: 'Get the translation output from given message in well structured JSON',
            object: $object,
            required: $object->getNames()
        );

        $adapter = new OpenAI($apiKey, OpenAI::MODEL_GPT_4O);
        $agent = new Agent($adapter);
        $agent->setSchema($schema);

        $user = new User('user', 'Translator');
        $assistant = new Assistant('assistant', 'System');

        $conversation = new Conversation($agent);
        $conversation
            ->message($assistant, new AgentText('User will give you a message in English language, and you will translate it into ' . $targetLanguage . ' language. Do not translate XML tags, HTML tags, or placeholders - preserve those in the same place in the new message.'))
            ->message($user, new AgentText($enTranslation));

        $output = $conversation->send()->getContent();

        $outputJson = \json_decode($output, true);

        $targetTranslation = $outputJson['translation'];

        // Replace XML tags back to placeholders
        $id = 0;
        foreach ($placeholders as $placeholder) {
            $pattern = '/\<m id=' . $id . ' label="\w+" \/>/';
            $targetTranslation = \preg_replace($pattern, $placeholder, $targetTranslation);
            $id++;
        }

        return $targetTranslation;
    }
}
