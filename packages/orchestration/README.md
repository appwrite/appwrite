# Utopia Orchestration

[![Build Status](https://app.travis-ci.com/utopia-php/orchestration.svg?branch=main)](https://app.travis-ci.com/github/utopia-php/orchestration)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/orchestration.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia framework orchestration library is simple and lite library for abstracting the interaction with multiple container orchestrators. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting Started

Install using composer:
```bash
composer require utopia-php/orchestration
```

### Example
```php
<?php

require_once 'vendor/autoload.php';

use Utopia\Orchestration\Orchestration;
use Utopia\Orchestration\Adapter\DockerCLI;

// Initialise Orchestration with Docker CLI adapter.
$orchestration = new Orchestration(new DockerCLI());

// Pull the image.
$orchestration->pull('ubuntu:latest');

// Launch a ubuntu container that doesn't end using the tail command.
$containerID = $orchestration->run('ubuntu:latest', 'testContainer', ['tail', '-f', '/dev/null']);

$stderr = '';
$stdout = '';

// Execute a hello world command in the container
$orchestration->execute($containerID, ['echo', 'Hello World!'], $stdout, $stderr);

// Remove the container forcefully since it's still running.
$orchestration->remove($containerID, true);
```

## Usage

### Initialisation

There are currently two orchestrator adapters available and each of them has slightly different parameters:

- ### DockerAPI
    Directly communicates to the Docker Daemon using the Docker UNIX socket.

    ```php
    use Utopia\Orchestration\Orchestration;
    use Utopia\Orchestration\Adapter\DockerAPI;

    $orchestration = new Orchestration(new DockerAPI($username, $password, $email));
    ```
    $username, $password and $email are optional and are only used to pull private images from Docker Hub.

- ### DockerCLI
    Uses the Docker CLI to communicate to the Docker Daemon.
    ```php
    use Utopia\Orchestration\Orchestration;
    use Utopia\Orchestration\Adapter\DockerCLI;

    $orchestration = new Orchestration(new DockerCLI($username, $password));
    ```
    $username and $password are optional and are only used to pull private images from Docker Hub.

Once you have initialised your Orchestration object the following methods can be used:

- ### Pulling an image
    This method pulls the image requested from the orchestrators registry. It will return a boolean value indicating if the image was pulled successfully.

    ```php
    $orchestration->pull('image:tag');
    ```

    <details>
    <summary>
    Parameters
    </summary>
    <br>

    - `image` [String] [Required]

        The image to pull from the registry.

    </details>
    <br>

- ### Running a container
    This method creates and runs a new container. On success, it will return a string containing the container ID. On failure, it will throw an exception.

    ```php
    $orchestration->run(
        'image:tag',
        'name',
        ['echo', 'hello world!'],
        'entrypoint',
        'workdir',
        ['tmp:/tmp:rw', 'cooldirectory:/home/folder:rw'],
        ['ENV_VAR' => 'value'],
        '/tmp',
        ['label' => 'value'],
        'hostname',
        true,
    );
    ```

    <details>
    <summary>
    Parameters
    </summary>
    <br>

    - `image` [String] [Required]

        The image to base the container off.

    - `name` [String] [Required]

        The name given to the container.

    - `command` [Array]

        The command to run in the container seperated into a array.
    
    - `entrypoint` [String]

        The executable to run in the container.

    - `workdir` [String]

        The default directory in which the container commands will run.

    - `volumes` [Array]

        The volumes to attach to the container.
    
    - `env` [Array]

        The environment variables to set in the container.
    
    - `mountFolder` [String]

        A folder that will be automatically mounted to /tmp in the container

    - `labels` [Array]

        The labels to set on the container.

    - `hostname` [String]

        The hostname to set on the container.

    - `remove` [Boolean]
  
        Whether to remove the container once it exits.

    </details>


- ### Executing a command in a running container

    This method executes a command in an already running container and returns a boolean value indicating if the command was executed successfully.

    ```php
    $stdout = '';
    $stderr = '';

    $orchestraton->execute(
        'container_id',
        ['echo', 'Hello World!'],
        $stdout,
        $stderr,
        ['VAR' => 'VALUE'],
        10,
    )
    ```

    <details>
    <summary>
    Parameters
    </summary>
    <br>

    - `container_id` [String] [Required]

        The ID of the container to execute the command in.
    
    - `command` [Array] [Required]

        The command to execute in the container.

    - `stdout` [String] [Reference]

        The variable to store the stdout of the command in.

    - `stderr` [String] [Reference]

        The variable to store the stderr of the command in.

    - `env` [Array]

        The environment variables to set while executing the command.

    - `timeout` [Integer]

        The timeout in seconds to wait for the command to finish.

    </details>

- ### Removing a container

    This method removes a container and returns a boolean value indicating if the container was removed successfully.

    ```php
    $orchestration->remove('container_id', true);
    ```

    <details>
    <summary>
    Parameters
    </summary>
    <br>

    - `container_id` [String] [Required]

        The ID of the container to remove.

    - `force` [Boolean]

        Whether to remove the container forcefully.

    </details>

- ### List containers
    
    This method returns an array of containers.

    ```php
    $orchestration->list(['label' => 'value']);
    ```

    <details>
    <summary>
    Parameters
    </summary>
    <br>

    - `filters` [Array]

        Filters to apply to the list of containers.

    </details>

- ### List Networks
    
    This method returns an array of networks.

    ```php
    $orchestration->listNetworks();
    ```

    <details>
    <summary>
    Parameters
    </summary>
    <br>

    This method has no parameters

    </details>

- ### Create a Network
    
    This method creates a new network and returns a boolean value indicating if the network was created successfully.

    ```php
    $orchestration->createNetwork('name', false);
    ```

    <details>
    <summary>
    Parameters
    </summary>
    <br>

    - `name` [String] [Required]

        The name of the network.

    - `internal` [Boolean]

        Whether to set the network to be an internal network.

    </details>

- ### Remove a Network

    This method removes a network and returns a boolean value indicating if the network was removed successfully.

    ```php
    $orchestration->removeNetwork('network_id');
    ```

    <details>
    <summary>
    Parameters
    </summary>
    <br>

    - `network_id` [String] [Required]

        The ID of the network to remove.

    </details>

- ### Connect a container to a network

    This method connects a container to a network and returns a boolean value indicating if the connection was successful.

    ```php
    $orchestration->connect('container_id', 'network_id');
    ```

    <details>
    <summary>
    Parameters
    </summary>
    <br>

    - `container_id` [String] [Required]

        The ID of the container to connect to the network.

    - `network_id` [String] [Required]

        The ID of the network to connect to.

    </details>

- ### Disconnect a container from a network

    This method disconnects a container from a network and returns a boolean value indicating if the removal was successful.

    ```php
    $orchestration->disconnect('container_id', 'network_id', false);
    ```

    <details>
    <summary>
    Parameters
    </summary>
    <br>

    - `container_id` [String] [Required]

        The ID of the container to disconnect from the network.

    - `network_id` [String] [Required]

        The ID of the network to disconnect from.

    - `force` [Boolean]

        Whether to disconnect the container forcefully.

    </details>


## System Requirements

Utopia Framework requires PHP 7.3 or later. We recommend using the latest PHP version whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
