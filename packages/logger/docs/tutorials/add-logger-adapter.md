# Adding a new logger adapter 💾

This document is part of the Utopia contributors' guide. Before you continue reading this document make sure you have read the [Code of Conduct](https://raw.githubusercontent.com/utopia-php/logger/main/CODE_OF_CONDUCT.md) and the [Contributing Guide](https://raw.githubusercontent.com/utopia-php/logger/main/CONTRIBUTING.md).

## Getting started

Logger adapters help developers to store their logs on an external provider's servers that monitors, notifies and manages logs for them. Using such an external provider creates a flow that let you spot a bug as soon as possible and provides tools for proper tracking.

Utopia is and will always be tech-agnostic, which means, we are creating a tools based on technologies you already use and love, instead of creating a new tool-set for you. With that in mind, we accept all contributions with adapters for any third party providers.

## 1. Prerequisites

It's really easy to contribute to an open source project, but when using GitHub, there are a few steps we need to follow. This section will take you step-by-step through the process of preparing your own local version of utopia-php/logger, where you can make any changes without affecting Utopia right away.

> If you are experienced with GitHub or have made a pull request before, you can skip to [Implement new adapter](#2-implement-new-adapter).

###  1.1 Fork the utopia-php/logger repository

Before making any changes, you will need to fork Utopia's repository to keep branches on the official repo clean. To do that, visit the [utopia/logger Github repository](https://github.com/utopia-php/logger) and click on the fork button.

This will redirect you from `github.com/utopia-php/logger` to `github.com/YOUR_USERNAME/logger`, meaning all changes you do are only done inside your repository. Once you are there, click the highlighted `Code` button, copy the URL and clone the repository to your computer using `git clone` command:

```shell
$ git clone [COPIED_URL]
```

> To clone a repository, you will need a basic understanding of CLI and git-cli binaries installed. If you are a beginner, we recommend you to use `Github Desktop`. It is a really clean and simple visual Git client.

Finally, you will need to create a `feat-ZZZ-adapter` branch based on the `main` branch and switch to it. The `ZZZ` should represent the adapter name.

## 2. Implement new adapter

### 2.1 Add adapter class

Before implementing the adapter, please make sure to **not use any PHP library!** You will need to build app API calls using HTTP requests.

Create a new file `XXX.php` where `XXX` is the name of the adapter in [`PascalCase`](https://stackoverflow.com/a/41769355/7659504) in this location
```bash
src/Logger/Adapter/XXX.php
```

Inside this file, create a new class that extends adapter abstract class `Adapter`. Note that the class name should start with a capital letter, as PHP FIG standards suggest.

Once a new class is created, you can start to implement your new adapter's flow. We have prepared a starting point for adapter class below, but you should also consider looking at other adapter implementations and try to follow the same standards.

```php
<?php

namespace Utopia\Logger\Adapter;

use Utopia\Logger\Adapter;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;

// Reference Material
// [DOCS FROM ADAPTER PROVIDER]

class [ADAPTER_NAME] extends Adapter
{
    // TODO: Define protected variables with keys required for authentication with external Adapter API

    /**
     * Return unique adapter name
     *
     * @return string
     */
    public static function getName(): string
    {
        return "[UNIQUE_ADAPTER_VERBOSE_ID]";
    }

    /**
     * Push log to external provider
     *
     * @param Log $log
     * @return int
     */
    public function push(Log $log): int
    {
        // TODO: Implement HTTP API request that submit a log into external server. For building HTTP request, use `curl_exec()`, just like all other adapters
    }

    /**
     * [ADAPTER_NAME] constructor.
     *
     * @param string $configKey
     */
    public function __construct(string $configKey)
    {
        // TODO: Fill protected variables with keys using values from constructor parameters
    }
    
    public function getSupportedTypes(): array
    {
        // TODO: Return array of supported log types, such as Log::TYPE_DEBUG or Log::TYPE_ERROR
    }

    public function getSupportedEnvironments(): array
    {
        // TODO: Return array of supported environments, such as Log::ENVIRONMENT_STAGING or Log::ENVIRONMENT_PRODUCTION
    }

    public function getSupportedBreadcrumbTypes(): array
    {
        // TODO: Return array of supported breadcrumb types, such as Log::TYPE_WARNING or Log::TYPE_INFO
    }
}
```

> If you copy this template, make sure to replace all placeholders wrapped like `[THIS]` and to implement everything marked as `TODO:`.

When implementing new adapter, please make sure to follow these rules:

- `getName()` needs to use same name as file name with first letter lowercased. For example, in `AppSignal.php`, we use `appSignal`
- Consturctor needs to recieve exactly 1 parameter `$configKey`. This should all keys required for authentication. If multiple are needed, symbol `;` should be used for separation.

Please mention in your documentation what resources or API docs you used to implement the provider's API. Also, make sure all of these parameters are pushed to external API server:

- [ ] Timestamp
- [ ] Type
- [ ] Message
- [ ] Version
- [ ] Environment
- [ ] Action
- [ ] Namespace
- [ ] Server
- [ ] User (id, email, username)
- [ ] Breadcrumbs array (type, category, message, timestamp)
- [ ] Extra array
- [ ] Tags array

If external API does not support any of these, feel free to add the information as tag. Every provider supports tags, so we can use that to store any officially un-supported information.

If you need a custom logic for validation, you can implement `validate` function from which you call parent's implementation of it and extend the logic with whatever validation your adapter requires.

### 2.2 Register newly created provider

In `src/Logger/Logger.php` update variable `const PROVIDERS` to include your provider name.

## 3. Test your adapter

After you finished adding your new adapter, you should write a proper test for it. To do that, you enter `tests/e2e/Adapter` and create a new `XXXTest.php` wtih something like:

```php
<?php

namespace Utopia\Tests\E2E\Adapter;

use Utopia\Logger\Adapter\[XXX];
use Utopia\Tests\E2E\AdapterBase;

class [XXX]Test extends AdapterBase
{
    protected function setUp(): void
    {
        parent::setUp();
        $param1 = \getenv('TEST_XXX_PARAM_1');
        $param2 = \getenv('TEST_XXX_PARAM_2');
        $this->adapter = new [XXX](
            $param1 ? $param1 : '',
            $param2 ? $param2 : '',
        );
        $this->expected = 202; // Expected status code for successful log creation
    }
}

```

Take a look at any other adapter as an example.

## 4. Raise a pull request

First of all, commit the changes with the message `Added XXX Adapter` (where `XXX` is adapter name) and push it. This will publish a new branch to your forked version of utopia/logger. If you visit it at `github.com/YOUR_USERNAME/logger`, you will see a new alert saying you are ready to submit a pull request. Follow the steps GitHub provides, and at the end, you will have your pull request submitted.

## 🤕 Stuck ?
If you need any help with the contribution, feel free to head over to [Appwrite discord channel](https://appwrite.io/discord) and we'll be happy to help you out.
