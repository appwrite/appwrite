<?php

use Utopia\Config\Config;
use Utopia\System\System;

$templateRuntimes = Config::getParam('template-runtimes');
$allowList = \array_map('trim', \explode(',', System::getEnv('_APP_FUNCTIONS_RUNTIMES', '')));

function getRuntimes($runtimes, $commands, $entrypoint, $providerRootDirectory, $allowList)
{
    return array_map(function ($runtime) use ($commands, $entrypoint, $providerRootDirectory) {
        return [
            'name' => $runtime,
            'commands' => $commands,
            'entrypoint' => $entrypoint,
            'providerRootDirectory' => $providerRootDirectory
        ];
    }, array_filter($runtimes, function ($runtime) use ($allowList) {
        return in_array($runtime, $allowList);
    }));
}

return [
    [
        'icon' => 'icon-lightning-bolt',
        'id' => 'starter',
        'name' => 'Starter function',
        'score' => 5,
        'tagline' =>
            'A simple function to get started. Edit this function to explore endless possibilities with Appwrite Functions.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['starter'],
        'runtimes' => [
            ...getRuntimes($templateRuntimes['NODE'], 'npm install', 'src/main.js', 'node/starter', $allowList),
            ...getRuntimes(
                $templateRuntimes['PYTHON'],
                'pip install -r requirements.txt',
                'src/main.py',
                'python/starter',
                $allowList
            ),
            ...getRuntimes($templateRuntimes['DART'], 'dart pub get', 'lib/main.dart', 'dart/starter', $allowList),
            ...getRuntimes($templateRuntimes['GO'], '', 'main.go', 'go/starter', $allowList),
            ...getRuntimes(
                $templateRuntimes['PHP'],
                'composer install',
                'src/index.php',
                'php/starter',
                $allowList
            ),
            ...getRuntimes($templateRuntimes['DENO'], 'deno cache src/main.ts', 'src/main.ts', 'deno/starter', $allowList),
            ...getRuntimes($templateRuntimes['BUN'], 'bun install', 'src/main.ts', 'bun/starter', $allowList),
            ...getRuntimes($templateRuntimes['RUBY'], 'bundle install', 'lib/main.rb', 'ruby/starter', $allowList),
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/starter">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [],
        'scopes' => ['users.read']
    ],
    [
        'icon' => 'icon-upstash',
        'id' => 'query-upstash-vector',
        'name' => 'Query Upstash Vector',
        'score' => 4,
        'tagline' => 'Vector database that stores text embeddings and context retrieval for LLMs',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['databases'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/query-upstash-vector',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/query-upstash-vector">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'UPSTASH_URL',
                'description' => 'The endpoint to connect to your Upstash Vector database. <a class="u-bold" target="_blank" href="https://upstash.com/docs/vector/overall/getstarted">Learn more</a>.',
                'value' => '',
                'placeholder' => 'https://resolved-mallard-84564-eu1-vector.upstash.io',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'UPSTASH_TOKEN',
                'description' => 'Authentication token to access your Upstash Vector database. <a class="u-bold" target="_blank" href="https://upstash.com/docs/vector/overall/getstarted">Learn more</a>.',
                'value' => '',
                'placeholder' =>
                    'oe4wNTbwHVLcDNa6oceZfhBEABsCNYh43ii6Xdq4bKBH7mq7qJkUmc4cs3ABbYyuVKWZTxVQjiNjYgydn2dkhABNes4NAuDpj7qxUAmZYqGJT78',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-redis',
        'id' => 'query-redis-labs',
        'name' => 'Query Redis Labs',
        'score' => 4,
        'tagline' => 'Key-value database with advanced caching capabilities.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['databases'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/query-redis-labs',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/query-redis-labs">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'REDIS_HOST',
                'description' => 'The endpoint to connect to your Redis database. <a class="u-bold" target="_blank" href="https://redis.io/docs/latest/operate/rc/rc-quickstart/">Learn more</a>.',
                'value' => '',
                'placeholder' => 'redis-13258.c35.eu-central-1-1.ec2.redns.redis-cloud.com',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'REDIS_PASSWORD',
                'description' => 'Authentication password to access your Redis database. <a class="u-bold" target="_blank" href="https://redis.io/docs/latest/operate/rc/rc-quickstart/">Learn more</a>.',
                'value' => '',
                'placeholder' => 'efNNehiACfcZiwsTAjcK6xiwPyu6Dpdq',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-neo4j',
        'id' => 'query-neo4j-auradb',
        'name' => 'Query Neo4j AuraDB',
        'score' => 4,
        'tagline' => 'Graph database with focus on relations between data.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['databases'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/query-neo4j-auradb',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/query-neo4j-auradb">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'NEO4J_URI',
                'description' => 'The endpoint to connect to your Neo4j database. <a class="u-bold" target="_blank" href="https://neo4j.com/docs/aura/auradb/getting-started/connect-database/">Learn more</a>.',
                'value' => '',
                'placeholder' => 'neo4j+s://4tg4mddo.databases.neo4j.io',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'NEO4J_USER',
                'description' => 'Authentication user to access your Neo4j database. <a class="u-bold" target="_blank" href="https://neo4j.com/docs/aura/auradb/getting-started/connect-database/">Learn more</a>.',
                'value' => '',
                'placeholder' => 'neo4j',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'NEO4J_PASSWORD',
                'description' => 'Authentication password to access your Neo4j database. <a class="u-bold" target="_blank" href="https://neo4j.com/docs/aura/auradb/getting-started/connect-database/">Learn more</a>.',
                'value' => '',
                'placeholder' => 'mCUc4PbVUQN-_NkTLJLisb6ccnwzQKKhrkF77YMctzx',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-mongodb',
        'id' => 'query-mongo-atlas',
        'name' => 'Query MongoDB Atlas',
        'score' => 4,
        'tagline' =>
            'Realtime NoSQL document database with geospecial, graph, search, and vector suport.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['databases'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/query-mongo-atlas',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/query-mongo-atlas">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'MONGO_URI',
                'description' => 'The endpoint to connect to your Mongo database. <a class="u-bold" target="_blank" href="https://www.mongodb.com/docs/atlas/getting-started/">Learn more</a>.',
                'value' => '',
                'placeholder' =>
                    'mongodb+srv://appwrite:Yx42hafg7Q4fgkxe@cluster0.7mslfog.mongodb.net/?retryWrites=true&w=majority&appName=Appwrite',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-neon',
        'id' => 'query-neon-postgres',
        'name' => 'Query Neon Postgres',
        'score' => 4,
        'tagline' =>
            'Reliable SQL database with replication, point-in-time recovery, and pgvector support.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['databases'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/query-neon-postgres',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/query-neon-postgres">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'PGHOST',
                'description' => 'The endpoint to connect to your Postgres database. <a class="u-bold" target="_blank" href="https://neon.tech/docs/get-started-with-neon/signing-up/">Learn more</a>.',
                'value' => '',
                'placeholder' => 'ep-still-sea-a792sh84.eu-central-1.aws.neon.tech',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'PGDATABASE',
                'description' => 'Name of our Postgres database. <a class="u-bold" target="_blank" href="https://neon.tech/docs/get-started-with-neon/signing-up/">Learn more</a>.',
                'value' => '',
                'placeholder' => 'main',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'PGUSER',
                'description' => 'Name of our Postgres user for authentication. <a class="u-bold" target="_blank" href="https://neon.tech/docs/get-started-with-neon/signing-up/">Learn more</a>.',
                'value' => '',
                'placeholder' => 'main_owner',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'PGPASSWORD',
                'description' => 'Password of our Postgres user for authentication. <a class="u-bold" target="_blank" href="https://neon.tech/docs/get-started-with-neon/signing-up/">Learn more</a>.',
                'value' => '',
                'placeholder' => 'iQCfaUaaWB3B',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'ENDPOINT_ID',
                'description' => 'Endpoint ID provided for your Postgres database. <a class="u-bold" target="_blank" href="https://neon.tech/docs/get-started-with-neon/signing-up/">Learn more</a>.',
                'value' => '',
                'placeholder' => 'ep-still-sea-a792sh84',
                'required' => true,
                'type' => 'text'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-open-ai',
        'id' => 'prompt-chatgpt',
        'name' => 'Prompt ChatGPT',
        'score' => 7,
        'tagline' => 'Ask questions and let OpenAI GPT-3.5-turbo answer.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/prompt-chatgpt',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['PYTHON'],
                'pip install -r requirements.txt',
                'src/main.py',
                'python/prompt_chatgpt',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['PHP'],
                'composer install',
                'src/index.php',
                'php/prompt-chatgpt',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['DART'],
                'dart pub get',
                'lib/main.dart',
                'dart/prompt_chatgpt',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/prompt-chatgpt">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'OPENAI_API_KEY',
                'description' => 'A unique key used to authenticate with the OpenAI API. This is a paid service and you will be charged for each request made to the API. <a class="u-bold" target="_blank" href="https://platform.openai.com/docs/quickstart/add-your-api-key">Learn more</a>.',
                'value' => '',
                'placeholder' => 'sk-wzG...vcy',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'OPENAI_MAX_TOKENS',
                'description' => 'The maximum number of tokens that the OpenAI response should contain. Be aware that OpenAI models read and write a maximum number of tokens per API call, which varies depending on the model. For GPT-3.5-turbo, the limit is 4096 tokens. <a class="u-bold" target="_blank" href="https://help.openai.com/en/articles/4936856-what-are-tokens-and-how-to-count-them">Learn more</a>.',
                'value' => '512',
                'placeholder' => '512',
                'required' => false,
                'type' => 'number'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-discord',
        'id' => 'discord-command-bot',
        'name' => 'Discord Command Bot',
        'score' => 6,
        'tagline' => 'Simple command using Discord Interactions.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['messaging'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install && npm run setup',
                'src/main.js',
                'node/discord-command-bot',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['PYTHON'],
                'pip install -r requirements.txt && python src/setup.py',
                'src/main.py',
                'python/discord_command_bot',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['GO'],
                '',
                'main.go',
                'go/discord-command-bot',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/discord-command-bot">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'DISCORD_PUBLIC_KEY',
                'description' => 'Public Key of your application in Discord Developer Portal. <a class="u-bold" target="_blank" href="https://discord.com/developers/docs/tutorials/hosting-on-cloudflare-workers#creating-an-app-on-discord">Learn more</a>.',
                'value' => '',
                'placeholder' => 'db9...980',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'DISCORD_APPLICATION_ID',
                'description' => 'ID of your application in Discord Developer Portal. <a class="u-bold" target="_blank" href="https://discord.com/developers/docs/tutorials/hosting-on-cloudflare-workers#creating-an-app-on-discord">Learn more</a>.',
                'value' => '',
                'placeholder' => '427...169',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'DISCORD_TOKEN',
                'description' => 'Bot token of your application in Discord Developer Portal. <a class="u-bold" target="_blank" href="https://discord.com/developers/docs/tutorials/hosting-on-cloudflare-workers#creating-an-app-on-discord">Learn more</a>.',
                'value' => '',
                'placeholder' => 'NDI...LUfg',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-perspective-api',
        'id' => 'analyze-with-perspectiveapi',
        'name' => 'Analyze with PerspectiveAPI',
        'score' => 5,
        'tagline' => 'Automate moderation by getting toxicity of messages.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/analyze-with-perspectiveapi',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/analyze-with-perspectiveapi">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'PERSPECTIVE_API_KEY',
                'description' => 'Google Perspective API key. It authenticates your function, allowing it to interact with the API. <a class="u-bold" target="_blank" href="https://developers.google.com/codelabs/setup-perspective-api">Learn more</a>.',
                'value' => '',
                'placeholder' => 'AIzaS...fk-fuM',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-pangea',
        'id' => 'censor-with-redact',
        'name' => 'Censor with Redact',
        'score' => 5,
        'tagline' =>
            'Censor sensitive information from a provided text string using Redact API by Pangea.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/censor-with-redact',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['PYTHON'],
                'pip install -r requirements.txt',
                'src/main.py',
                'python/censor_with_redact',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['DART'],
                'dart pub get',
                'lib/main.dart',
                'dart/censor_with_redact',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/censor-with-redact">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'PANGEA_REDACT_TOKEN',
                'description' => 'Access token for the Pangea Redact API. <a class="u-bold" target="_blank" href="https://pangea.cloud/docs/redact/getting-started/configuration">Learn more</a>.',
                'value' => '',
                'placeholder' => 'pts_7p4...5wl4',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-document',
        'id' => 'generate-pdf',
        'name' => 'Generate PDF',
        'score' => 7,
        'tagline' => 'Document containing sample invoice in PDF format.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['utilities'],
        'runtimes' => [
            ...getRuntimes($templateRuntimes['NODE'], 'npm install', 'src/main.js', 'node/generate-pdf', $allowList)
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/generate-pdf">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [],
        'scopes' => []
    ],
    [
        'icon' => 'icon-github',
        'id' => 'github-issue-bot',
        'name' => 'GitHub issue bot',
        'score' => 4,
        'tagline' =>
            'Automate the process of responding to newly opened issues in a GitHub repository.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['dev-tools'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/github-issue-bot',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/github-issue-bot">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'GITHUB_TOKEN',
                'description' => 'A personal access token from GitHub with the necessary permissions to post comments on issues. <a class="u-bold" target="_blank" href="https://docs.github.com/en/github/authenticating-to-github/creating-a-personal-access-token">Learn more</a>.',
                'value' => '',
                'placeholder' => 'ghp_1...',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'GITHUB_WEBHOOK_SECRET',
                'description' => 'The secret used to verify that the webhook request comes from GitHub. <a class="u-bold" target="_blank" href="https://docs.github.com/en/developers/webhooks-and-events/securing-your-webhooks">Learn more</a>.',
                'value' => '',
                'placeholder' => 'd1efb...aec35',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-bookmark',
        'id' => 'url-shortener',
        'name' => 'URL shortener',
        'score' => 3,
        'tagline' => 'Generate URL with short ID and redirect to the original URL when visited.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['utilities'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/url-shortener',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/url-shortener">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'APPWRITE_DATABASE_ID',
                'description' => 'The ID of the database to store the short URLs. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/databases">Learn more</a>.',
                'value' => 'urlShortener',
                'placeholder' => 'urlShortener',
                'required' => false,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_COLLECTION_ID',
                'description' => 'The ID of the collection to store the short URLs. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/collections">Learn more</a>.',
                'value' => 'urls',
                'placeholder' => 'urls',
                'required' => false,
                'type' => 'text'
            ],
            [
                'name' => 'SHORT_BASE_URL',
                'description' => 'The domain to use for the short URLs. You can use your functions subdomain or a custom domain.',
                'value' => '',
                'placeholder' => 'https://shortdomain.io',
                'required' => true,
                'type' => 'url'
            ]
        ],
        'scopes' => ['databases.read', 'databases.write', 'collections.write', 'tables.write', 'attributes.write', 'columns.write', 'documents.read', 'rows.read', 'documents.write', 'rows.write']
    ],
    [
        'icon' => 'icon-algolia',
        'id' => 'sync-with-algolia',
        'name' => 'Sync with Algolia',
        'score' => 4,
        'tagline' => 'Intuitive search bar for any data in Appwrite Databases.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['databases'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/sync-with-algolia',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['PYTHON'],
                'pip install -r requirements.txt',
                'src/main.py',
                'python/sync_with_algolia',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['PHP'],
                'composer install',
                'src/index.php',
                'php/sync-with-algolia',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/sync-with-algolia">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'APPWRITE_DATABASE_ID',
                'description' => 'The ID of the Appwrite database that contains the collection to sync. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/databases">Learn more</a>.',
                'placeholder' => '64a55...7b912',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_COLLECTION_ID',
                'description' => 'The ID of the collection in the Appwrite database to sync. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/collections">Learn more</a>.',
                'placeholder' => '7c3e8...2a9f1',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'ALGOLIA_APP_ID',
                'description' => 'The ID of the application in Algolia. <a class="u-bold" target="_blank" href="https://support.algolia.com/hc/en-us/articles/11040113398673-Where-can-I-find-my-application-ID-and-the-index-name-">Learn more</a>.',
                'placeholder' => 'OFCNCOG2CU',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'ALGOLIA_ADMIN_API_KEY',
                'description' => 'The admin API Key for your Algolia service. <a class="u-bold" target="_blank" href="https://www.algolia.com/doc/guides/security/api-keys/">Learn more</a>.',
                'placeholder' => 'fd0aa...136a8',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'ALGOLIA_INDEX_ID',
                'description' => 'The ID of the index in Algolia where the documents are to be synced. <a class="u-bold" target="_blank" href="https://www.algolia.com/doc/api-client/methods/indexing/">Learn more</a>.',
                'placeholder' => 'my_index',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'ALGOLIA_SEARCH_API_KEY',
                'description' => 'The search API Key for your Algolia service. This key is used for searching the synced index. <a class="u-bold" target="_blank" href="https://www.algolia.com/doc/guides/security/api-keys/">Learn more</a>.',
                'placeholder' => 'bf2f5...df733',
                'required' => true,
                'type' => 'password'
            ],
        ],
        'scopes' => ['databases.read', 'collections.read', 'tables.read', 'documents.read', 'rows.read']
    ],
    [
        'icon' => 'icon-meilisearch',
        'id' => 'sync-with-meilisearch',
        'name' => 'Sync with Meilisearch',
        'score' => 4,
        'tagline' => 'Intuitive search bar for any data in Appwrite Databases.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['databases'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/sync-with-meilisearch',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['PYTHON'],
                'pip install -r requirements.txt',
                'src/main.py',
                'python/sync-with-meilisearch',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['PHP'],
                'composer install',
                'src/index.php',
                'php/sync-with-meilisearch',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['BUN'],
                'bun install',
                'src/main.ts',
                'bun/sync-with-meilisearch',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['RUBY'],
                'bundle install',
                'lib/main.rb',
                'ruby/sync-with-meilisearch',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/sync-with-meilisearch">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'APPWRITE_DATABASE_ID',
                'description' => 'The ID of the Appwrite database that contains the collection to sync. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/databases">Learn more</a>.',
                'placeholder' => '64a55...7b912',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_COLLECTION_ID',
                'description' => 'The ID of the collection in the Appwrite database to sync. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/collections">Learn more</a>.',
                'placeholder' => '7c3e8...2a9f1',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'MEILISEARCH_ENDPOINT',
                'description' => 'The host URL of the Meilisearch server. <a class="u-bold" target="_blank" href="https://www.meilisearch.com/docs/learn/getting_started/quick_start/">Learn more</a>.',
                'placeholder' => 'http://127.0.0.1:7700',
                'required' => true,
                'type' => 'url'
            ],
            [
                'name' => 'MEILISEARCH_ADMIN_API_KEY',
                'description' => 'The admin API key for Meilisearch. <a class="u-bold" target="_blank" href="https://docs.meilisearch.com/reference/api/keys/">Learn more</a>.',
                'placeholder' => 'masterKey1234',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'MEILISEARCH_SEARCH_API_KEY',
                'description' => 'API Key for Meilisearch search operations. <a class="u-bold" target="_blank" href="https://www.algolia.com/doc/guides/security/api-keys/">Learn more</a>.',
                'placeholder' => 'searchKey1234',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'MEILISEARCH_INDEX_NAME',
                'description' => 'Name of the Meilisearch index to which the documents will be synchronized. <a class="u-bold" target="_blank" href="https://www.meilisearch.com/docs/learn/core_concepts/indexes/">Learn more</a>.',
                'placeholder' => 'appwrite_index',
                'required' => true,
                'type' => 'text'
            ],
        ],
        'scopes' => ['databases.read', 'collections.read', 'tables.read', 'documents.read', 'rows.read']
    ],
    [
        'icon' => 'icon-vonage',
        'id' => 'whatsapp-with-vonage',
        'name' => 'WhatsApp with Vonage',
        'score' => 6,
        'tagline' => 'Simple bot to answer WhatsApp messages.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['messaging'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/whatsapp-with-vonage',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['PYTHON'],
                'pip install -r requirements.txt',
                'src/main.py',
                'python/whatsapp_with_vonage',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['DART'],
                'dart pub get',
                'lib/main.dart',
                'dart/whatsapp-with-vonage',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['PHP'],
                'composer install',
                'src/index.php',
                'php/whatsapp-with-vonage',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['BUN'],
                'bun install',
                'src/main.ts',
                'bun/whatsapp-with-vonage',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['RUBY'],
                'bundle install',
                'lib/main.rb',
                'ruby/whatsapp-with-vonage',
                $allowList
            ),
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/whatsapp-with-vonage">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'VONAGE_API_KEY',
                'description' => 'API Key to use the Vonage API. <a class="u-bold" target="_blank" href="https://api.support.vonage.com/hc/en-us/articles/204014493-How-do-I-find-my-Voice-API-key-and-API-secret-">Learn more</a>.',
                'value' => '',
                'placeholder' => '62...97',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'VONAGE_API_SECRET',
                'description' => 'Secret to use the Vonage API. <a class="u-bold" target="_blank" href="https://api.support.vonage.com/hc/en-us/articles/204014493-How-do-I-find-my-Voice-API-key-and-API-secret-">Learn more</a>.',
                'placeholder' => 'Zjc...5PH',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'VONAGE_API_SIGNATURE_SECRET',
                'description' => 'Secret to verify the JWT token sent by Vonage. <a class="u-bold" target="_blank" href="https://developer.vonage.com/en/getting-started/concepts/signing-messages">Learn more</a>.',
                'placeholder' => 'NXOi3...IBHDa',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'VONAGE_WHATSAPP_NUMBER',
                'description' => 'Vonage WhatsApp number to send messages from. <a class="u-bold" target="_blank" href="https://api.support.vonage.com/hc/en-us/articles/4431993282580-Where-do-I-find-my-WhatsApp-Number-Certificate-">Learn more</a>.',
                'placeholder' => '+14000000102',
                'required' => true,
                'type' => 'phone'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-bell',
        'id' => 'push-notification-with-fcm',
        'name' => 'Push notification with FCM',
        'score' => 4,
        'tagline' => 'Send push notifications to your users using Firebase Cloud Messaging (FCM).',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['messaging'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/push-notification-with-fcm',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/push-notification-with-fcm">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'FCM_PROJECT_ID',
                'description' => 'A unique identifier for your FCM project. <a class="u-bold" target="_blank" href="https://firebase.google.com/docs/projects/learn-more#project-id">Learn more</a>.',
                'value' => '',
                'placeholder' => 'mywebapp-f6e57',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'FCM_CLIENT_EMAIL',
                'description' => 'Your FCM service account email. <a class="u-bold" target="_blank" href="https://github.com/appwrite/templates/tree/main/node/push-notification-with-fcm#:~:text=Documentation-,FCM%3A%20SDK%20Setup,-FCM_PRIVATE_KEY">Learn more</a>.',
                'placeholder' => 'fcm-adminsdk-2f0de@test-f7q57.iam.gserviceaccount.com',
                'required' => true,
                'type' => 'email'
            ],
            [
                'name' => 'FCM_PRIVATE_KEY',
                'description' => 'A unique private key used to authenticate with FCM. <a class="u-bold" target="_blank" href="https://github.com/appwrite/templates/tree/main/node/push-notification-with-fcm#:~:text=Documentation-,FCM%3A%20SDK%20Setup,-FCM_DATABASE_URL">Learn more</a>.',
                'placeholder' => '0b683...75675',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'FCM_DATABASE_URL',
                'description' => 'URL of your FCM database. <a class="u-bold" target="_blank" href="https://firebase.google.com/docs/admin/setup#initialize_the_sdk_in_non-google_environments">Learn more</a>.',
                'placeholder' => 'https://my-app-f298e.firebaseio.com',
                'required' => true,
                'type' => 'url'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-mail',
        'id' => 'email-contact-form',
        'name' => 'Email contact form',
        'score' => 7,
        'tagline' => 'Sends an email with the contents of a HTML form.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['utilities'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/email-contact-form',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['PYTHON'],
                'pip install -r requirements.txt',
                'src/main.py',
                'python/email_contact_form',
                $allowList
            ),
            ...getRuntimes(
                $templateRuntimes['PHP'],
                'composer install',
                'src/index.php',
                'php/email-contact-form',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/email-contact-form">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'SMTP_HOST',
                'description' => 'The address of your SMTP server. Many STMP providers will provide this information in their documentation. Some popular providers include: Mailgun, SendGrid, and Gmail.',
                'value' => '',
                'placeholder' => 'smtp.mailgun.org',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'SMTP_PORT',
                'description' => 'The port of your STMP server. Commnly used ports include 25, 465, and 587.',
                'placeholder' => '25',
                'required' => true,
                'type' => 'number'
            ],
            [
                'name' => 'SMTP_USERNAME',
                'description' => 'The username for your SMTP server. This is commonly your email address.',
                'placeholder' => 'no-reply@mywebapp.org',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'SMTP_PASSWORD',
                'description' => 'The password for your SMTP server.',
                'placeholder' => '5up3r5tr0ngP4ssw0rd',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'SUBMIT_EMAIL',
                'description' => 'The email address to send form submissions to.',
                'placeholder' => 'me@mywebapp.org',
                'required' => true,
                'type' => 'email'
            ],
            [
                'name' => 'ALLOWED_ORIGINS',
                'description' => 'An optional comma-separated list of allowed origins for CORS (defaults to *). This is an important security measure to prevent malicious users from abusing your function.',
                'value' => '',
                'placeholder' => 'https://mywebapp.org,https://mywebapp.com',
                'required' => false,
                'type' => 'text'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-stripe',
        'id' => 'subscriptions-with-stripe',
        'name' => 'Subscriptions with Stripe',
        'score' => 6,
        'tagline' => 'Receive recurring card payments and grant subscribers extra permissions.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['utilities'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/subscriptions-with-stripe',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/subscriptions-with-stripe">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'STRIPE_SECRET_KEY',
                'description' => 'Secret for sending requests to the Stripe API. <a class="u-bold" target="_blank" href="https://stripe.com/docs/keys">Learn more</a>.',
                'placeholder' => 'sk_test_51J...',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'STRIPE_WEBHOOK_SECRET',
                'description' => 'Secret used to validate the Stripe Webhook signature. <a class="u-bold" target="_blank" href="https://stripe.com/docs/webhooks">Learn more</a>.',
                'placeholder' => 'whsec_...',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => ['users.read', 'sessions.write', 'users.write']
    ],
    [
        'icon' => 'icon-stripe',
        'id' => 'payments-with-stripe',
        'name' => 'Payments with Stripe',
        'score' => 8,
        'tagline' => 'Receive card payments and store paid orders.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['utilities'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/payments-with-stripe',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/payments-with-stripe">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'STRIPE_SECRET_KEY',
                'description' => 'Secret for sending requests to the Stripe API. <a class="u-bold" target="_blank" href="https://stripe.com/docs/keys">Learn more</a>.',
                'placeholder' => 'sk_test_51J...',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'STRIPE_WEBHOOK_SECRET',
                'description' => 'Secret used to validate the Stripe Webhook signature. <a class="u-bold" target="_blank" href="https://stripe.com/docs/webhooks">Learn more</a>.',
                'placeholder' => 'whsec_...',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'APPWRITE_DATABASE_ID',
                'description' => 'The ID of the database to store paid orders. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/databases">Learn more</a>.',
                'value' => 'orders',
                'placeholder' => 'orders',
                'required' => false,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_COLLECTION_ID',
                'description' => 'The ID of the collection to store paid orders. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/collections">Learn more</a>.',
                'value' => 'orders',
                'placeholder' => 'orders',
                'required' => false,
                'type' => 'text'
            ]
        ],
        'scopes' => ['databases.read', 'databases.write', 'collections.write', 'tables.write', 'attributes.write', 'columns.write', 'documents.read', 'rows.read', 'documents.write', 'rows.write']
    ],
    [
        'icon' => 'icon-chat',
        'id' => 'text-generation-with-huggingface',
        'name' => 'Text generation',
        'score' => 5,
        'tagline' => 'Generate text using the Hugging Face inference API.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 30,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/text-generation-with-huggingface',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/text-generation-with-huggingface">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'HUGGINGFACE_ACCESS_TOKEN',
                'description' => 'Secret for sending requests to the Hugging Face API. <a class="u-bold" target="_blank" href="https://huggingface.co/docs/api-inference/en/quicktour#get-your-api-token">Learn more</a>.',
                'placeholder' => 'hf_MUvn...',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-translate',
        'id' => 'language-translation-with-huggingface',
        'name' => 'Language translation',
        'score' => 5,
        'tagline' => 'Translate text using the Hugging Face inference API.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 30,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/language-translation-with-huggingface',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/language-translation-with-huggingface">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'HUGGINGFACE_ACCESS_TOKEN',
                'description' => 'Secret for sending requests to the Hugging Face API. <a class="u-bold" target="_blank" href="https://huggingface.co/docs/api-inference/en/quicktour#get-your-api-token">Learn more</a>.',
                'placeholder' => 'hf_MUvn...',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-eye',
        'id' => 'image-classification-with-huggingface',
        'name' => 'Image classification',
        'score' => 5,
        'tagline' => 'Classify images using the Hugging Face inference API.',
        'permissions' => ['any'],
        'events' => ['buckets.*.files.*.create'],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install && npm run setup',
                'src/main.js',
                'node/image-classification-with-huggingface',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/image-classification-with-huggingface">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'APPWRITE_DATABASE_ID',
                'description' => 'The ID of the database where the responses are stored.  <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/databases">Learn more</a>.',
                'value' => 'ai',
                'placeholder' => 'ai',
                'required' => false,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_COLLECTION_ID',
                'description' => 'The ID of the collection where the responses are stored. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/collections">Learn more</a>.',
                'value' => 'image_classification',
                'placeholder' => 'image_classification',
                'required' => false,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_BUCKET_ID',
                'description' => 'The ID of the bucket where the images are stored. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/storage/buckets">Learn more</a>.',
                'value' => 'image_classification',
                'placeholder' => 'image_classification',
                'required' => false,
                'type' => 'text'
            ],
            [
                'name' => 'HUGGINGFACE_ACCESS_TOKEN',
                'description' => 'Secret for sending requests to the Hugging Face API. <a class="u-bold" target="_blank" href="https://huggingface.co/docs/api-inference/en/quicktour#get-your-api-token">Learn more</a>.',
                'placeholder' => 'hf_MUvn...',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => ['databases.read', 'databases.write', 'collections.read', 'tables.read', 'collections.write', 'tables.write', 'attributes.write', 'columns.write', 'documents.read', 'rows.read', 'documents.write', 'rows.write', 'buckets.read', 'buckets.write', 'files.read']
    ],
    [
        'icon' => 'icon-eye',
        'id' => 'object-detection-with-huggingface',
        'name' => 'Object detection',
        'score' => 5,
        'tagline' => 'Detect objects in images using the Hugging Face inference API.',
        'permissions' => ['any'],
        'events' => ['buckets.*.files.*.create'],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install && npm run setup',
                'src/main.js',
                'node/object-detection-with-huggingface',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/object-detection-with-huggingface">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'APPWRITE_DATABASE_ID',
                'description' => 'The ID of the database where the responses are stored.  <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/databases">Learn more</a>.',
                'value' => 'ai',
                'placeholder' => 'ai',
                'required' => false,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_COLLECTION_ID',
                'description' => 'The ID of the collection where the responses are stored. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/collections">Learn more</a>.',
                'value' => 'object_detection',
                'placeholder' => 'object_detection',
                'required' => false,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_BUCKET_ID',
                'description' => 'The ID of the bucket where the images are stored. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/storage/buckets">Learn more</a>.',
                'value' => 'object_detection',
                'placeholder' => 'object_detection',
                'required' => false,
                'type' => 'text'
            ],
            [
                'name' => 'HUGGINGFACE_ACCESS_TOKEN',
                'description' => 'Secret for sending requests to the Hugging Face API. <a class="u-bold" target="_blank" href="https://huggingface.co/docs/api-inference/en/quicktour#get-your-api-token">Learn more</a>.',
                'placeholder' => 'hf_MUvn...',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => ['databases.read', 'databases.write', 'collections.read', 'tables.read', 'collections.write', 'tables.write', 'attributes.write', 'columns.write', 'documents.read', 'rows.read', 'documents.write', 'rows.write', 'buckets.read', 'buckets.write', 'files.read']
    ],
    [
        'icon' => 'icon-text',
        'id' => 'speech-recognition-with-huggingface',
        'name' => 'Speech recognition',
        'score' => 5,
        'tagline' => 'Transcribe audio to text using the Hugging Face inference API.',
        'permissions' => ['any'],
        'events' => ['buckets.*.files.*.create'],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install && npm run setup',
                'src/main.js',
                'node/speech-recognition-with-huggingface',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/speech-recognition-with-huggingface">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'APPWRITE_DATABASE_ID',
                'description' => 'The ID of the database where the responses are stored.  <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/databases">Learn more</a>.',
                'value' => 'ai',
                'placeholder' => 'ai',
                'required' => false,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_COLLECTION_ID',
                'description' => 'The ID of the collection where the responses are stored. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/collections">Learn more</a>.',
                'value' => 'speech_recognition',
                'placeholder' => 'speech_recognition',
                'required' => false,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_BUCKET_ID',
                'description' => 'The ID of the bucket where audio is stored. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/storage/buckets">Learn more</a>.',
                'value' => 'speech_recognition',
                'placeholder' => 'speech_recognition',
                'required' => false,
                'type' => 'text'
            ],
            [
                'name' => 'HUGGINGFACE_ACCESS_TOKEN',
                'description' => 'Secret for sending requests to the Hugging Face API. <a class="u-bold" target="_blank" href="https://huggingface.co/docs/api-inference/en/quicktour#get-your-api-token">Learn more</a>.',
                'placeholder' => 'hf_MUvn...',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => ['databases.read', 'databases.write', 'collections.read', 'tables.read', 'collections.write', 'tables.write', 'attributes.write', 'columns.write', 'documents.read', 'rows.read', 'documents.write', 'rows.write', 'buckets.read', 'buckets.write', 'files.read']
    ],
    [
        'icon' => 'icon-chat',
        'id' => 'text-to-speech-with-huggingface',
        'name' => 'Text to speech',
        'score' => 5,
        'tagline' => 'Convert text to speech using the Hugging Face inference API.',
        'permissions' => ['any'],
        'events' => [
            'databases.*.tables.*.rows.*.create',
            'databases.*.collections.*.documents.*.create',
        ],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install && npm run setup',
                'src/main.js',
                'node/text-to-speech-with-huggingface',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/text-to-speech-with-huggingface">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'APPWRITE_DATABASE_ID',
                'description' => 'The ID of the database where the responses are stored.  <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/databases">Learn more</a>.',
                'value' => 'ai',
                'placeholder' => 'ai',
                'required' => false,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_COLLECTION_ID',
                'description' => 'The ID of the collection where the responses are stored. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/collections">Learn more</a>.',
                'value' => 'speech_recognition',
                'placeholder' => 'speech_recognition',
                'required' => false,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_BUCKET_ID',
                'description' => 'The ID of the bucket where audio is stored. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/storage/buckets">Learn more</a>.',
                'value' => 'speech_recognition',
                'placeholder' => 'speech_recognition',
                'required' => false,
                'type' => 'text'
            ],
            [
                'name' => 'HUGGINGFACE_ACCESS_TOKEN',
                'description' => 'Secret for sending requests to the Hugging Face API. <a class="u-bold" target="_blank" href="https://huggingface.co/docs/api-inference/en/quicktour#get-your-api-token">Learn more</a>.',
                'placeholder' => 'hf_MUvn...',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => ['buckets.read', 'buckets.write', 'files.read', 'files.write']
    ],
    [
        'icon' => 'icon-chip',
        'id' => 'generate-with-replicate',
        'name' => 'Generate with Replicate',
        'score' => 5,
        'tagline' => "Generate text, audio and images using Replicate's API.",
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 300,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/generate-with-replicate',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/generate-with-replicate">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'REPLICATE_API_KEY',
                'description' => 'A unique key used to authenticate with the Replicate API. <a class="u-bold" target="_blank" href="https://replicate.com/docs/get-started/nodejs">Learn more</a>.',
                'value' => '',
                'placeholder' => 'd1efb...aec35',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-chip',
        'id' => 'generate-with-together-ai',
        'name' => 'Generate with Together AI',
        'score' => 5,
        'tagline' => "Generate text and images using Together AI's API.",
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 300,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/generate-with-together-ai',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/generate-with-together-ai">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'TOGETHER_API_KEY',
                'description' => 'A unique key used to authenticate with the Together AI API. <a class="u-bold" target="_blank" href="https://docs.together.ai/reference/authentication-1">Learn more</a>.',
                'value' => '',
                'placeholder' => 'd1efb...aec35',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'APPWRITE_BUCKET_ID',
                'description' => 'The ID of the bucket where audio is stored. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/storage/buckets">Learn more</a>.',
                'placeholder' => 'generated_speech',
                'required' => true,
                'type' => 'text'
            ]
        ],
        'scopes' => ['buckets.write', 'files.read', 'files.write']
    ],
    [
        'icon' => 'icon-chip',
        'id' => 'chat-with-perplexity-ai',
        'name' => 'Chat with Perplexity AI',
        'score' => 5,
        'tagline' => 'Create a chatbot using the Perplexity AI API.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/chat-with-perplexity-ai',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/chat-with-perplexity-ai">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'PERPLEXITY_API_KEY',
                'description' => 'A unique key used to authenticate with the Perplexity API. <a class="u-bold" target="_blank" href="https://docs.perplexity.ai/docs/getting-started">Learn more</a>.',
                'placeholder' => 'pplex-68...999',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'PERPLEXITY_MAX_TOKENS',
                'description' => 'The maximum number of tokens to generate. <a class="u-bold" target="_blank" href="https://docs.perplexity.ai/docs/getting-started">Learn more</a>.',
                'placeholder' => '512',
                'required' => false,
                'type' => 'number'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-chip',
        'id' => 'generate-with-replicate',
        'name' => 'Generate with Replicate',
        'score' => 5,
        'tagline' => "Generate text, audio and images using Replicate's API.",
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 300,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/generate-with-replicate',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/generate-with-replicate">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'REPLICATE_API_KEY',
                'description' => 'A unique key used to authenticate with the Replicate API. <a class="u-bold" target="_blank" href="https://replicate.com/docs/get-started/nodejs">Learn more</a>.',
                'value' => '',
                'placeholder' => 'd1efb...aec35',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-document-search',
        'id' => 'sync-with-pinecone',
        'name' => 'Sync with Pinecone',
        'score' => 4,
        'tagline' => "Sync your Appwrite database with Pinecone's vector database.",
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 30,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/sync-with-pinecone',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/sync-with-pinecone">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'OPENAI_API_KEY',
                'description' => 'A unique key used to authenticate with the OpenAI API. This is a paid service and you will be charged for each request made to the API. <a class="u-bold" target="_blank" href="https://platform.openai.com/docs/quickstart/add-your-api-key">Learn more</a>.',
                'value' => '',
                'placeholder' => 'sk-wzG...vcy',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'PINECONE_API_KEY',
                'description' => 'A unique key used to authenticate with the Pinecone API. <a class="u-bold" target="_blank" href="https://docs.pinecone.io/guides/getting-started/authentication">Learn more</a>.',
                'placeholder' => 'd1efb...aec35',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'PINECONE_INDEX_NAME',
                'description' => 'The name of the index in Pinecone. <a class="u-bold" target="_blank" href="https://docs.pinecone.io/guides/getting-started/create-index">Learn more</a>.',
                'placeholder' => 'my-index',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_DATABASE_ID',
                'description' => 'The ID of the database where the documents are stored.  <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/databases">Learn more</a>.',
                'placeholder' => 'my-database',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_COLLECTION_ID',
                'description' => 'The ID of the collection where the documents are stored. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/collections">Learn more</a>.',
                'placeholder' => 'my-collection',
                'required' => true,
                'type' => 'text'
            ]
        ],
        'scopes' => ['databases.read', 'collections.read', 'tables.read', 'documents.read', 'rows.read']
    ],
    [
        'icon' => 'icon-chip',
        'id' => 'rag-with-langchain',
        'name' => 'RAG with LangChain',
        'score' => 6,
        'tagline' => 'Generate text using a LangChain RAG model',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 30,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/rag-with-langchain',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/rag-with-langchain">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'OPENAI_API_KEY',
                'description' => 'A unique key used to authenticate with the OpenAI API. This is a paid service and you will be charged for each request made to the API. <a class="u-bold" target="_blank" href="https://platform.openai.com/docs/quickstart/add-your-api-key">Learn more</a>.',
                'value' => '',
                'placeholder' => 'sk-wzG...vcy',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'PINECONE_API_KEY',
                'description' => 'A unique key used to authenticate with the Pinecone API. <a class="u-bold" target="_blank" href="https://docs.pinecone.io/guides/getting-started/authentication">Learn more</a>.',
                'placeholder' => 'd1efb...aec35',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'PINECONE_INDEX_NAME',
                'description' => 'The name of the index in Pinecone. <a class="u-bold" target="_blank" href="https://docs.pinecone.io/guides/getting-started/create-index">Learn more</a>.',
                'placeholder' => 'my-index',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_DATABASE_ID',
                'description' => 'The ID of the database where the documents are stored.  <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/databases">Learn more</a>.',
                'placeholder' => 'my-database',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_COLLECTION_ID',
                'description' => 'The ID of the collection where the documents are stored. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/collections">Learn more</a>.',
                'placeholder' => 'my-collection',
                'required' => true,
                'type' => 'text'
            ]
        ],
        'scopes' => ['databases.read', 'collections.read', 'tables.read', 'documents.read', 'rows.read']
    ],
    [
        'icon' => 'icon-chat',
        'id' => 'speak-with-elevenlabs',
        'name' => 'Speak with ElevenLabs',
        'score' => 5,
        'tagline' => 'Convert text to speech using the ElevenLabs API.',
        'permissions' => ['any'],
        'cron' => '',
        'events' => [],
        'timeout' => 15,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/speak-with-elevenlabs',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/speak-with-elevenlabs">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'ELEVENLABS_API_KEY',
                'description' => 'A unique key used to authenticate with the ElevenLabs API. <a class="u-bold" target="_blank" href="https://elevenlabs.io/docs/api-reference/getting-started">Learn more</a>.',
                'placeholder' => 'd03xxxxxxxx26',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'APPWRITE_DATABASE_ID',
                'description' => 'The ID of the database where the responses are stored.  <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/databases">Learn more</a>.',
                'placeholder' => 'my-database',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_COLLECTION_ID',
                'description' => 'The ID of the collection where the responses are stored. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/collections">Learn more</a>.',
                'placeholder' => 'my-collection',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_BUCKET_ID',
                'description' => 'The ID of the bucket where audio is stored. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/storage/buckets">Learn more</a>.',
                'placeholder' => 'generated_speech',
                'required' => true,
                'type' => 'text'
            ]
        ],
        'scopes' => ['buckets.read', 'buckets.write', 'files.read', 'files.write']
    ],
    [
        'icon' => 'icon-chip',
        'id' => 'speak-with-lmnt',
        'name' => 'Speak with LMNT',
        'score' => 5,
        'tagline' => 'Convert text to speech using the LMNT API.',
        'permissions' => ['any'],
        'cron' => '',
        'events' => [],
        'timeout' => 15,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/speak-with-lmnt',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/speak-with-lmnt">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'LMNT_API_KEY',
                'description' => 'A unique key used to authenticate with the LMNT API. <a class="u-bold" target="_blank" href="https://app.lmnt.com/account">Learn more</a>.',
                'placeholder' => 'd03xxxxxxxx26',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'APPWRITE_BUCKET_ID',
                'description' => 'The ID of the bucket where audio is stored. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/storage/buckets">Learn more</a>.',
                'placeholder' => 'generated_speech',
                'required' => true,
                'type' => 'text'
            ]
        ],
        'scopes' => ['buckets.read', 'buckets.write', 'files.read', 'files.write']
    ],
    [
        'icon' => 'icon-chip',
        'id' => 'chat-with-anyscale',
        'name' => 'Chat with AnyScale',
        'score' => 5,
        'tagline' => 'Create a chatbot using the AnyScale API.',
        'permissions' => ['any'],
        'cron' => '',
        'events' => [],
        'timeout' => 15,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/chat-with-anyscale',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/chat-with-anyscale">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'ANYSCALE_API_KEY',
                'description' => 'A unique key used to authenticate with the AnyScale API. <a class="u-bold" target="_blank" href="https://app.endpoints.anyscale.com/credentials">Learn more</a>.',
                'placeholder' => 'd03xxxxxxxx26',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'ANYSCALE_MAX_TOKENS',
                'description' => 'The maximum number of tokens that Anyscale responses should contain. <a class="u-bold" target="_blank" href="https://help.openai.com/en/articles/4936856-what-are-tokens-and-how-to-count-them">Learn more</a>.',
                'placeholder' => '',
                'required' => false,
                'type' => 'number'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-music-note',
        'id' => 'music-generation-with-huggingface',
        'name' => 'Music generation',
        'score' => 4,
        'tagline' => 'Generate music from a text prompt using the Hugging Face inference API.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install && npm run setup',
                'src/main.js',
                'node/music-generation-with-huggingface',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/music-generation-with-huggingface">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'APPWRITE_BUCKET_ID',
                'description' => 'The ID of the bucket where generated music is stored. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/storage/buckets">Learn more</a>.',
                'value' => 'generated_music',
                'placeholder' => 'generated_music',
                'required' => false,
                'type' => 'text'
            ],
            [
                'name' => 'HUGGINGFACE_ACCESS_TOKEN',
                'description' => 'Secret for sending requests to the Hugging Face API. <a class="u-bold" target="_blank" href="https://huggingface.co/docs/api-inference/en/quicktour#get-your-api-token">Learn more</a>.',
                'placeholder' => 'hf_MUvn...',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => ['buckets.read', 'buckets.write', 'files.read', 'files.write']
    ],
    [
        'icon' => 'icon-chip',
        'id' => 'generate-with-fal-ai',
        'name' => 'Generate with fal.ai',
        'score' => 5,
        'tagline' => "Generate images using fal.ai's API.",
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 300,
        'useCases' => ['ai'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/generate-with-fal-ai',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/generate-with-fal-ai">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'FAL_API_KEY',
                'description' => 'A unique key used to authenticate with the fal.ai API. <a class="u-bold" target="_blank" href="https://fal.ai/docs/authentication/key-based">Learn more</a>.',
                'value' => '',
                'placeholder' => 'd1efb...aec35',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => []
    ],
    [
        'icon' => 'icon-currency-dollar',
        'id' => 'subscriptions-with-lemon-squeezy',
        'name' => 'Subscriptions with Lemon Squeezy',
        'score' => 6,
        'tagline' => 'Receive recurring card payments and grant subscribers extra permissions.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['utilities'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/subscriptions-with-lemon-squeezy',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/subscriptions-with-lemon-squeezy">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'LEMON_SQUEEZY_API_KEY',
                'description' => 'API key for sending requests to the Lemon Squeezy API. <a class="u-bold" target="_blank" href="https://docs.lemonsqueezy.com/api#authentication">Learn more</a>.',
                'placeholder' => 'eyJ0eXAiOiJ...',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'LEMON_SQUEEZY_WEBHOOK_SECRET',
                'description' => 'Secret used to validate the Lemon Squuezy Webhook signature. <a class="u-bold" target="_blank" href="https://docs.lemonsqueezy.com/guides/developer-guide/webhooks#from-the-dashboard">Learn more</a>.',
                'placeholder' => 'abcd...',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'LEMON_SQUEEZY_STORE_ID',
                'description' => 'Store ID required to create a checkout using the Lemon Squeezy API. <a class="u-bold" target="_blank" href="https://docs.lemonsqueezy.com/guides/developer-guide/taking-payments#creating-checkouts-with-the-api">Learn more</a>.',
                'placeholder' => '123456',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'LEMON_SQUEEZY_VARIANT_ID',
                'description' => 'Variant ID of a product required to create a checkout using the Lemon Squeezy API. <a class="u-bold" target="_blank" href="https://docs.lemonsqueezy.com/guides/developer-guide/taking-payments#creating-checkouts-with-the-api">Learn more</a>.',
                'placeholder' => 'abcd...',
                'required' => true,
                'type' => 'text'
            ]
        ],
        'scopes' => ['users.read', 'users.write']
    ],
    [
        'icon' => 'icon-currency-dollar',
        'id' => 'payments-with-lemon-squeezy',
        'name' => 'Payments with Lemon Squeezy',
        'score' => 6,
        'tagline' => 'Receive card payments and store paid orders.',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['utilities'],
        'runtimes' => [
            ...getRuntimes(
                $templateRuntimes['NODE'],
                'npm install',
                'src/main.js',
                'node/payments-with-lemon-squeezy',
                $allowList
            )
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/payments-with-lemon-squeezy">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'APPWRITE_DATABASE_ID',
                'description' => 'The ID of the database to store paid orders. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/databases">Learn more</a>.',
                'value' => 'orders',
                'placeholder' => 'orders',
                'required' => false,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_COLLECTION_ID',
                'description' => 'The ID of the collection to store paid orders. <a class="u-bold" target="_blank" href="https://appwrite.io/docs/products/databases/collections">Learn more</a>.',
                'value' => 'orders',
                'placeholder' => 'orders',
                'required' => false,
                'type' => 'text'
            ],
            [
                'name' => 'LEMON_SQUEEZY_API_KEY',
                'description' => 'API key for sending requests to the Lemon Squeezy API. <a class="u-bold" target="_blank" href="https://docs.lemonsqueezy.com/api#authentication">Learn more</a>.',
                'placeholder' => 'eyJ0eXAiOiJ...',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'LEMON_SQUEEZY_WEBHOOK_SECRET',
                'description' => 'Secret used to validate the Lemon Squuezy Webhook signature. <a class="u-bold" target="_blank" href="https://docs.lemonsqueezy.com/guides/developer-guide/webhooks#from-the-dashboard">Learn more</a>.',
                'placeholder' => 'abcd...',
                'required' => true,
                'type' => 'password'
            ],
            [
                'name' => 'LEMON_SQUEEZY_STORE_ID',
                'description' => 'Store ID required to create a checkout using the Lemon Squeezy API. <a class="u-bold" target="_blank" href="https://docs.lemonsqueezy.com/guides/developer-guide/taking-payments#creating-checkouts-with-the-api">Learn more</a>.',
                'placeholder' => '123456',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'LEMON_SQUEEZY_VARIANT_ID',
                'description' => 'Variant ID of a product required to create a checkout using the Lemon Squeezy API. <a class="u-bold" target="_blank" href="https://docs.lemonsqueezy.com/guides/developer-guide/taking-payments#creating-checkouts-with-the-api">Learn more</a>.',
                'placeholder' => 'abcd...',
                'required' => true,
                'type' => 'text'
            ]
        ],
        'scopes' => ['databases.read', 'databases.write', 'collections.write', 'attributes.write', 'documents.read', 'documents.write']
    ],
    [
        'icon' => 'icon-apple',
        'id' => 'sign-in-with-apple',
        'name' => 'Sign in with Apple',
        'score' => 6,
        'tagline' => 'Use native Apple sign-in APIs on Apple devices with Appwrite Auth',
        'permissions' => ['any'],
        'events' => [],
        'cron' => '',
        'timeout' => 15,
        'useCases' => ['auth'],
        'runtimes' => [
            ...getRuntimes($templateRuntimes['DART'], 'dart pub get', 'lib/main.dart', 'dart/sign_in_with_apple', $allowList)
        ],
        'instructions' => 'For documentation and instructions, check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/dart/sign_in_with_apple">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [
            [
                'name' => 'BUNDLE_ID',
                'description' => 'Bundle ID of the app. <a class="u-bold" target="_blank" href="https://developer.apple.com/documentation/xcode/preparing-your-app-for-distribution/#Set-the-bundle-ID">Learn more</a>.',
                'value' => '',
                'placeholder' => 'com.companyname.appname',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'TEAM_ID',
                'description' => 'Team ID of the Apple Developer account.',
                'value' => '',
                'placeholder' => '6K3...5PH',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'KEY_ID',
                'description' => 'Key ID required to communicate with Apple Developer services. <a class="u-bold" target="_blank" href="https://developer.apple.com/help/account/keys/get-a-key-identifier/">Learn more</a>.',
                'value' => '',
                'placeholder' => '9G8...6YF',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'KEY_CONTENTS_ENCODED',
                'description' => 'Contents of Key required to communicated with Apple Developer services, encoded in Base64. <a class="u-bold" target="_blank" href="https://developer.apple.com/help/account/keys/revoke-edit-and-download-keys">Learn more</a>.',
                'value' => '',
                'placeholder' => '7x8aA...Ab7c',
                'required' => true,
                'type' => 'password'
            ]
        ],
        'scopes' => ['users.read', 'users.write']
    ]
];
