# Contributing

We would :heart: you to contribute to Appwrite and help make it better! We want contributing to Appwrite to be fun, enjoyable, and educational for anyone and everyone. All contributions are welcome, including issues, and new docs, as well as updates and tweaks, blog posts, workshops, and more.

## Here for Hacktoberfest?
If you're here to contribute during Hacktoberfest, we're so happy to see you here. Appwrite has been a long-time participant of Hacktoberfest and we welcome you, whatever your experience level. This year, we're **only taking contributions for issues tagged** `hacktoberfest`, so we can focus our resources to support your contributions.

You can [find issues using this query](https://github.com/search?q=org%3Aappwrite+is%3Aopen+type%3Aissue+label%3Ahacktoberfest&type=issues).

## How to Start?

If you are worried or don’t know where to start, check out the next section that explains what kind of help we could use and where you can get involved. You can send your questions to [@appwrite on Twitter](https://twitter.com/appwrite) or to anyone from the [Appwrite team on Discord](https://appwrite.io/discord). You can also submit an issue, and a maintainer can guide you!

## Code of Conduct

Help us keep Appwrite open and inclusive. Please read and follow our [Code of Conduct](https://github.com/appwrite/.github/blob/main/CODE_OF_CONDUCT.md).

## Submit a Pull Request :rocket:

Branch naming convention is as following

`TYPE-ISSUE_ID-DESCRIPTION`

example:

```
doc-548-submit-a-pull-request-section-to-contribution-guide
```

When `TYPE` can be:

- **feat** - a new feature
- **doc** - documentation only changes
- **cicd** - changes related to CI/CD system
- **fix** - a bug fix
- **refactor** - code change that neither fixes a bug nor adds a feature

**All PRs must include a commit message with the description of the changes made!**

For the initial start, fork the project and use git clone command to download the repository to your computer. A standard procedure for working on an issue would be to:

1. `git pull`, before creating a new branch, pull the changes from upstream. Your master needs to be up to date.

```
$ git pull
```

2. Create a new branch from `master` like: `doc-548-submit-a-pull-request-section-to-contribution-guide`.<br/>

```
$ git checkout -b [name_of_your_new_branch]
```

3. Work - commit - repeat (make sure you're on the correct branch!)

4. Before you push your changes, make sure your code follows the `PSR12` coding standards, which is the standard that Appwrite currently follows. You can easily do this by running the formatter.

```bash
composer format <your file path>
```

Now, go a step further by running the linter using the following command to manually fix the issues the formatter wasn't able to.

```bash
composer lint <your file path>
```

This will give you a list of errors to rectify. If you need more information on the errors, you can pass in additional command line arguments to get more verbose information. More lists of available arguments can be found [on PHP_Codesniffer usage Wiki](https://github.com/squizlabs/PHP_CodeSniffer/wiki/Usage). A very useful command line argument is `--report=diff`. This will give you the expected changes by the linter for easy fixing of formatting issues.

```bash
composer lint --report=diff <your file path>
```

5. Push changes to GitHub

```
$ git push origin [name_of_your_new_branch]
```

6. Submit your changes for review
   If you go to your repository on GitHub, you'll see a `Compare & pull request` button. Click on that button.
7. Start a Pull Request
   Now submit the pull request and click on `Create pull request`.
8. Get a code review approval/reject.
9. After approval, merge your PR.
10. GitHub will automatically delete the branch after the merge is done. (they can still be restored).

## Setup From Source

To set up a working **development environment**, just fork the project git repository and install the backend and frontend dependencies using the proper package manager and create run the docker-compose stack.

> If you just want to install Appwrite for day-to-day use and not as a contributor, you can reference the [installation guide](https://github.com/appwrite/appwrite#installation), the [getting started guide](https://appwrite.io/docs/quick-starts), or the main [README](README.md) file.

```bash
git clone git@github.com:[YOUR_FORK_HERE]/appwrite.git

cd appwrite

git submodule update --init

docker compose build
docker compose up -d
```

### Code Autocompletion

To get proper autocompletion for all the different functions and classes in the codebase, you'll need to install Appwrite dependencies on your local machine. You can easily do that with PHP's package manager, [Composer](https://getcomposer.org/). If you don't have Composer installed, you can use the Docker Hub image to get the same result:

```bash
docker run --rm --interactive --tty \
  --volume $PWD:/app \
  composer update --ignore-platform-reqs --optimize-autoloader --no-plugins --no-scripts --prefer-dist
```

### User Interface

Appwrite's UI is built with [Svelte](https://svelte.dev/), [Svelte Kit](https://kit.svelte.dev/), and the [Pink Design](https://github.com/appwrite/pink) component library. You can find the source code in the [Appwrite Console](https://github.com/appwrite/console) repository.

To contribute to the UI, head to the [Contribution Guide](https://github.com/appwrite/console/blob/main/CONTRIBUTING.md) of Appwrite Console.

### Get Started

After finishing the installation process, you can start writing and editing code.

#### Advanced Topics

We love to create issues that are good for beginners and label them as `good first issue` or `hacktoberfest`, but some more advanced topics might require extra knowledge. Below is a list of links you can use to learn about the more advanced topics that will help you master the Appwrite codebase.

##### Tools and Libs

- [Docker](https://www.docker.com/get-started)
- [PHP FIG](https://www.php-fig.org/) - [PSR-12](https://www.php-fig.org/psr/psr-12/)
- [PHP Swoole](https://www.swoole.co.uk/)

Learn more at our [Technology Stack](#technology-stack) section.

##### Network and Protocols

- [OSI Model](https://en.wikipedia.org/wiki/OSI_model)
- [TCP vs UDP](https://www.guru99.com/tcp-vs-udp-understanding-the-difference.html#:~:text=TCP%20is%20a%20connection%2Doriented,speed%20of%20UDP%20is%20faster&text=TCP%20does%20error%20checking%20and,but%20it%20discards%20erroneous%20packets.)
- [HTTP](https://en.wikipedia.org/wiki/Hypertext_Transfer_Protocol)
- [REST API](https://en.wikipedia.org/wiki/Representational_state_transfer)
- [GraphQL](https://en.wikipedia.org/wiki/GraphQL)
- [gRPC](https://en.wikipedia.org/wiki/GRPC)

##### Architecture

- [Microservices vs Monolithic](https://www.mulesoft.com/resources/api/microservices-vs-monolithic#:~:text=Microservices%20architecture%20vs%20monolithic%20architecture&text=A%20monolithic%20application%20is%20built%20as%20a%20single%20unit.&text=To%20make%20any%20alterations%20to,formally%20with%20business%2Doriented%20APIs.)
- [MVVM](https://en.wikipedia.org/wiki/Model%E2%80%93view%E2%80%93viewmodel) - Appwrite console architecture

##### Container Namespace Conventions
To keep our services easy to understand within Docker we follow a naming convention for all our containers depending on it's intended use.

`appwrite-worker-X` - Workers (`src/Appwrite/Platform/Workers/*`)
`appwrite-task-X` - Tasks (`src/Appwrite/Platform/Tasks/*`)

Other containes should be named the same as their service, for example `redis` should just be called `redis`.

##### Security

- [Appwrite Auth and ACL](https://github.com/appwrite/appwrite/blob/master/docs/specs/authentication.drawio.svg)
- [OAuth](https://en.wikipedia.org/wiki/OAuth)
- [Encryption](https://medium.com/searchencrypt/what-is-encryption-how-does-it-work-e8f20e340537#:~:text=Encryption%20is%20a%20process%20that,%2C%20or%20decrypt%2C%20the%20information.)
- [Hashing](https://searchsqlserver.techtarget.com/definition/hashing#:~:text=Hashing%20is%20the%20transformation%20of,it%20using%20the%20original%20value.)

## Architecture

Appwrite's current structure is a combination of both [Monolithic](https://en.wikipedia.org/wiki/Monolithic_application) and [Microservice](https://en.wikipedia.org/wiki/Microservices) architectures.

---

## ![Appwrite](docs/specs/overview.drawio.svg)

### File Structure

```bash
.
├── app # Main application
│   ├── config # Config files
│   ├── controllers # API & dashboard controllers
│   │   ├── api
│   │   ├── shared
│   │   └── web
│   ├── db # DB schemas
│   ├── sdks # SDKs generated copies (used for generating code examples)
│   ├── tasks # Server CLI commands
│   ├── views # HTML server-side templates
│   └── workers # Background workers
├── bin # Server executables (tasks & workers)
├── docker # Docker related resources and configs
├── docs # Docs and tutorials
│   ├── examples
│   ├── references
│   ├── services
│   ├── specs
│   └── tutorials
├── public # Public files
│   ├── dist
│   ├── fonts
│   ├── images
│   ├── scripts
│   └── styles
├── src # Supporting libraries (each lib has one role, common libs are released as individual projects)
│   └── Appwrite
│       ├── Auth
│       ├── Detector
│       ├── Docker
|       ├── DSN
│       ├── Event
│       ├── Extend
│       ├── GraphQL
│       ├── Messaging
│       ├── Migration
│       ├── Network
│       ├── OpenSSL
│       ├── Promises
│       ├── Specification
│       ├── Task
│       ├── Template
│       ├── URL
│       └── Utopia
└── tests # End to end & unit tests
    ├── e2e
    ├── resources
    └── unit
```

### The Monolithic Part

Appwrite's main API container is designed as a monolithic app. This is a decision we made to allow us to develop the project faster while still being a very small team.

Although the Appwrite API is a monolithic app, it has a very clear separation of concern as each internal service or worker is separated by its container, which allows us to start breaking services for better maintenance and scalability as we grow.

### The Microservice Part

Each container in Appwrite is a microservice on its own. Each service is an independent process that can scale without regard to any of the other services.

Currently, all the Appwrite microservices are intended to communicate using the TCP protocol over a private network. With the exception of the public-facing port 80 and 443, which by default are used to expose the Appwrite HTTP API, you should **avoid exposing any other services' ports**.

## Ports

Appwrite dev version uses ports 80 and 443 as an entry point to the Appwrite API and console. We also expose multiple ports in the range of 9500-9504 for debugging some of the Appwrite containers on dev mode. If you have any conflicts with the ports running on your system, you can easily replace them by editing Appwrite's docker-compose.yml file and executing `docker compose up -d` command.

## Technology Stack

To start helping us to improve the Appwrite server by submitting code, prior knowledge of Appwrite's technology stack can help you get started.

Appwrite stack is a combination of a variety of open-source technologies and tools. Appwrite backend API is written primarily with PHP version 7 and above, on top of the [Utopia PHP framework](https://github.com/utopia-php/framework). The Appwrite frontend is built with tools like gulp, less, and [litespeed.js](https://github.com/litespeed-js). We use Docker as the container technology to package the Appwrite server for easy on-cloud, on-premise, or on-localhost integration.

### Other Technologies

- Redis - for managing cache and in-memory data (currently, we do not use Redis for persistent data).
- MariaDB - for database storage and queries.
- InfluxDB - for managing stats and time-series based data
- Statsd - for sending data over UDP protocol (using Telegraf)
- ClamAV - for validating and scanning storage files.
- Imagemagick - for manipulating and managing image media files.
- Webp - for better compression of images on supporting clients.
- SMTP - for sending email messages and alerts.

## Package Managers

Appwrite uses a package manager for managing code dependencies for both backend and frontend development. We try our best to avoid creating any unnecessary dependencies. New dependency to the project is subjected to a lead developer's review and approval.

Many of Appwrite's internal modules are also used as dependencies to allow other Appwrite projects to reuse them and as a way to contribute back to the community.

Appwrite uses [PHP's Composer](https://getcomposer.org/) for managing dependencies on the server-side and [JS NPM](https://www.npmjs.com/) for managing dependencies on the frontend side.

## Coding Standards

Appwrite follows the [PHP-FIG standards](https://www.php-fig.org/). Currently, we use both PSR-0 and PSR-12 for coding standards and autoloading standards.

We use prettier for our JS coding standards and auto-formatting for our code.

## Scalability, Speed, and Performance

Appwrite is built to scale. Please keep in mind that the Appwrite stack can run in different environments and different scales.

We intend Appwrite to be as easy to set up as possible in a single localhost, and to grow easily into a large environment with dozens and even hundreds of instances.

When contributing code, please take into account the following:

- Response Time
- Throughput
- Requests per Seconds
- Network Usage
- Memory Usage
- Browser Rendering
- Background Jobs
- Task Execution Time

## Security and Privacy

Security and privacy are extremely important to Appwrite, developers, and users alike. Make sure to follow the best industry standards and practices.

## Dependencies

Please avoid introducing new dependencies to Appwrite without consulting the team. New dependencies can be very helpful, but they also introduce new security and privacy risks, add complexity, and impact the total docker image size.

Adding a new dependency should have vital value for the product with minimum possible risk.

## Introducing New Features

We would :sparkling_heart: you to contribute to Appwrite, but we also want to ensure Appwrite is loyal to its vision and mission statement :pray:.

For us to find the right balance, please open an issue explaining your ideas before introducing a new pull request.

This will allow the Appwrite community to sufficiently discuss the new feature value and how it fits within the product roadmap and vision.

This is also important for the Appwrite lead developers to be able to provide technical input and potentially a different emphasis regarding the feature design and architecture. Some bigger features might need to go through our [RFC process](https://github.com/appwrite/rfc).

## Adding New Usage Metrics

These are the current metrics we collect usage stats for:

| Metric | Description                                       |
|--------|-------------------------------------------------|
| teams  | Total number of teams per project |
| users | Total number of users per project|
| executions | Total number of executions per project           | 
| databases | Total number of databases per project             | 
| collections | Total number of collections per project | 
| {databaseInternalId}.collections | Total number of collections per database| 
| documents | Total number of documents per project             | 
| {databaseInternalId}.{collectionInternalId}.documents | Total number of documents per collection | 
| buckets | Total number of buckets per project               | 
| files | Total number of files per project                 |
| {bucketInternalId}.files.storage | Sum of files.storage per bucket (in bytes)                  |
| functions | Total number of functions per project             |
| deployments | Total number of deployments per project           |
| builds | Total number of builds per project                |
| {resourceType}.{resourceInternalId}.deployments | Total number of deployments per function           |
| executions | Total number of executions per project |
| {functionInternalId}.executions | Total number of executions per function  |
| files.storage | Sum of files storage per project  (in bytes)      | 
| deployments.storage | Sum of deployments storage per project (in bytes) |
| {resourceType}.{resourceInternalId}.deployments.storage | Sum of deployments storage per function (in bytes)         |
| builds.storage | Sum of builds storage per project (in bytes)      |
| builds.compute | Sum of compute duration per project (in seconds)  |
| {functionInternalId}.builds.storage | Sum of builds storage per function (in bytes)              |
| {functionInternalId}.builds.compute | Sum of compute duration per function (in seconds) |
| network.requests | Total number of network requests per project |
| executions.compute | Sum of compute duration per project (in seconds) |
| network.inbound | Sum of network inbound traffic per project (in bytes)|
| network.outbound | Sum of network outbound traffic per project (in bytes)|

> Note: The curly brackets in the metric name represents a template and is replaced with a value when the metric is processed.

Metrics are collected within 3 scopes Daily, monthly, an infinity. Adding new usage metric in order to aggregate usage stats is very simple, but very much dependent on where do you want to collect
statistics ,via API or via background worker. For both cases you will need to add a `const` variable in `app/init.php` under the usage metrics list using the naming convention `METRIC_<RESOURCE_NAME>` as shown below.

```php
// Usage metrics
const METRIC_FUNCTIONS  = 'functions';
const METRIC_DEPLOYMENTS  = 'deployments';
const METRIC_DEPLOYMENTS_STORAGE  = 'deployments.storage';
```

Next follow the appropriate steps below depending on whether you're adding the metric to the API or the worker. 

**API**

In file `app/controllers/shared/api.php` On the database listener, add to an existing or create a new switch case. Add a call to the usage worker with your new metric const like so:

```php
      case $document->getCollection() === 'teams':
            $queueForUsage
                ->addMetric(METRIC_TEAMS, $value); // per project
            break;
```
There are cases when you need to handle metric that has a parent entity, like buckets.
Files are linked to a parent bucket, you should verify you remove the files stats when you delete a bucket.

In that case you need also to handle children removal using addReduce() method call.

```php

 case $document->getCollection() === 'buckets': //buckets
            $queueForUsage
                ->addMetric(METRIC_BUCKETS, $value); // per project
            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                $queueForUsage
                    ->addReduce($document);
            }
            break;
  
```

In addition, you will also need to add some logic to the `reduce()` method of the Usage worker located in `/src/Appwrite/Platform/Workers/Usage.php`, like so:

```php
case $document->getCollection() === 'buckets':
       $files = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{bucketInternalId}', $document->getInternalId(), METRIC_BUCKET_ID_FILES)));
       $storage = $dbForProject->getDocument('stats', md5(self::INFINITY_PERIOD . str_replace('{bucketInternalId}', $document->getInternalId(), METRIC_BUCKET_ID_FILES_STORAGE)));

       if (!empty($files['value'])) {
           $metrics[] = [
              'key' => METRIC_FILES,
              'value' => ($files['value'] * -1),
           ];
        }

        if (!empty($storage['value'])) {
           $metrics[] = [
              'key' => METRIC_FILES_STORAGE,
              'value' => ($storage['value'] * -1),
             ];
         }
       break;
```

**Background worker**

You need to inject the usage queue in the desired worker on the constructor method
```php
/**
* @throws Exception
*/
public function __construct()
{
   $this
      ->desc('Functions worker')
      ->groups(['functions'])
      ->inject('message')
      ->inject('dbForProject')
      ->inject('queueForFunctions')
      ->inject('queueForEvents')
      ->inject('queueForUsage')
      ->inject('log')
      ->callback(fn (Message $message, Database $dbForProject, Func $queueForFunctions, Event $queueForEvents, Usage $queueForUsage, Log $log) => $this->action($message, $dbForProject, $queueForFunctions, $queueForEvents, $queueForUsage, $log));
}
```

and then trigger the queue with the new metric like so: 

```php
$queueForUsage
  ->addMetric(METRIC_BUILDS, 1)
  ->addMetric(METRIC_BUILDS_STORAGE, $build->getAttribute('size', 0))
  ->addMetric(METRIC_BUILDS_COMPUTE, (int)$build->getAttribute('duration', 0) * 1000)
  ->addMetric(str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_BUILDS), 1) 
  ->addMetric(str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_BUILDS_STORAGE), $build->getAttribute('size', 0))
  ->addMetric(str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_BUILDS_COMPUTE), (int)$build->getAttribute('duration', 0) * 1000)
  ->setProject($project)
  ->trigger();
```


## Build

To build a new version of the Appwrite server, all you need to do is run the build.sh file like this:

```bash
bash ./build.sh X.X.X
```

Before running the command, make sure you have proper write permissions to the Appwrite docker hub team.

**Build for Multicore**

```bash
docker buildx build --platform linux/amd64,linux/arm64,linux/arm/v6,linux/arm/v7,linux/arm64/v8,linux/ppc64le,linux/s390x -t appwrite/appwrite:dev --push .
```

**Build Functions Runtimes**

The Runtimes for all supported cloud functions (multicore builds) can be found at the [open-runtimes/open-runtimes](https://github.com/open-runtimes/open-runtimes) repository.

## Generate SDK

The following steps are used to generate a new console SDK:

1. Update the console spec file located at `app/config/specs/swagger2-<version-number>.console.json` using Appwrite Tasks. Run the `php app/cli.php specs version=<version-number> mode=normal` command in a running `appwrite/appwrite` container.
2. Generate a new SDK using the command `php app/cli.php sdks`.
3. Change your working dir using `cd app/sdks/console-web`.
4. Build the new SDK `npm run build`.
5. Copy `iife/sdk.js` to `appwrite.js`.
6. Go back to the root of the project `run npm run build`.

## Checklist for Releasing SDKs

Things to remember when releasing SDKs:

- Update the Changelogs in **docs/sdks** (right now only Dart and Flutter are using these).
- Update **GETTING_STARTED.md** in **docs/sdks** for each SDKs if any changes in the related APIs are in there.
- Update SDK versions as required on **app/config/platforms.php**.
- Generate SDKs using the command `php app/cli.php sdks` and follow the instructions.
- Release new tags on GitHub repository for each SDK.

## Debug

Appwrite uses [XDebug](https://github.com/xdebug/xdebug) debugger, which can be made available during build of Appwrite. You can connect to the debugger using VS Code's [PHP Debug](https://marketplace.visualstudio.com/items?itemName=felixfbecker.php-debug) extension.

If you are in PHP Storm you don't need any plugin. Below are the settings required for remote debugger connection:

1. Set **DEBUG** build arg in **appwrite** service in **docker-compose.yml** file.
2. If needed edit the **dev/xdebug.ini** file to your needs.
3. Launch your Appwrite instance while your debugger is listening for connections.

## Profiling
Appwrite uses XDebug [Profiler](https://xdebug.org/docs/profiler) for generating **CacheGrind** files. The generated file would be located in each of the `appwrite` containers inside the `/tmp/xdebug` folder.

To disable the profiler while debugging remove the `,profiler` mode from the `xdebug.ini` file
```diff
zend_extension=xdebug

[xdebug]
-xdebug.mode=develop,debug,profile
+xdebug.mode=develop,debug
```

### VS Code Launch Configuration

```json
{
  "name": "Listen for Xdebug",
  "type": "php",
  "request": "launch",
  "port": 9005,
  "pathMappings": {
    "/usr/src/code": "${workspaceRoot}"
  }
}
```

### PHPStorm Setup

In settings, go to **Languages & Frameworks** > **PHP** > **Debug**, under **Xdebug** set the debug port to **9005** and enable the **can accept external connections** checkbox.

## Tests

To run all tests manually, use the Appwrite Docker CLI from your terminal:

```bash
docker compose exec appwrite test
```

To run unit tests use:

```bash
docker compose exec appwrite test /usr/src/code/tests/unit
```

To run end-2-end tests use:

```bash
docker compose exec appwrite test /usr/src/code/tests/e2e
```

To run end-2-end tests for a specific service use:

```bash
docker compose exec appwrite test /usr/src/code/tests/e2e/Services/[ServiceName]
```

To run one specific test:

```bash
docker compose exec appwrite vendor/bin/phpunit --filter [FunctionName]
```

## Benchmarking

You can use WRK Docker image to benchmark the server performance. Benchmarking is extremely useful when you want to compare how the server behaves before and after a change has been applied. Replace [APPWRITE_HOSTNAME_OR_IP] with your Appwrite server hostname or IP. Note that localhost is not accessible from inside the WRK container.

```
  Options:
    -c, --connections <N>  Connections to keep open
    -d, --duration    <T>  Duration of test
    -t, --threads     <N>  Number of threads to use

    -s, --script      <S>  Load Lua script file
    -H, --header      <H>  Add header to request
        --latency          Print latency statistics
        --timeout     <T>  Socket/request timeout
    -v, --version          Print version details
```

```bash
docker run --rm skandyla/wrk -t3 -c100 -d30  https://[APPWRITE_HOSTNAME_OR_IP]
```

## Code Maintenance

We use some automation tools to help us keep a healthy codebase.

**Run Formatter:**

```bash
# Run on all files
composer format
# Run on single file or folder
composer format <your file path>
```

**Run Linter:**

```bash
# Run on all files
composer lint
# Run on single file or folder
composer lint <your file path>
```

## Clearing the Cache

If you need to clear the cache, you can do so by running the following command:

```bash
docker compose exec redis redis-cli FLUSHALL
```

## Tutorials

From time to time, our team will add tutorials that will help contributors find their way in the Appwrite source code. Below is a list of currently available tutorials:

- [Adding Support for a New OAuth2 Provider](./docs/tutorials/add-oauth2-provider.md)
- [Appwrite Environment Variables](./docs/tutorials/add-environment-variable.md)
- [Running in Production](https://appwrite.io/docs/advanced/self-hosting/production)
- [Adding Storage Adapter](./docs/tutorials/add-storage-adapter.md)

## Other Ways to Help

Pull requests are great, but there are many other ways you can help Appwrite.

### Blogging & Speaking

Blogging, speaking about, or creating tutorials about one of Appwrite’s many features are great ways to get the word out about Appwrite. Mention [@appwrite on Twitter](https://twitter.com/appwrite) and/or [email team@appwrite.io](mailto:team@appwrite.io) so we can give pointers and tips and help you spread the word by promoting your content on the different Appwrite communication channels. Please add your blog posts and videos of talks to our [Awesome Appwrite](https://github.com/appwrite/awesome-appwrite) repo on GitHub.

### Presenting at Meetups

We encourage our contributors to present at meetups and conferences about your Appwrite projects. Your unique challenges and successes in building things with Appwrite can provide great speaking material. We’d love to review your talk abstract/CFP, so get in touch with us if you’d like some help!

### Sending Feedbacks and Reporting Bugs

Sending feedback is a great way for us to understand your different use cases of Appwrite better. If you had any issues, bugs, or want to share your experience, feel free to do so on our GitHub issues page or at our [Discord channel](https://discord.gg/GSeTUeA).

### Submitting New Ideas

If you think Appwrite could use a new feature, please open an issue on our GitHub repository, stating as much information as you have about your new idea and its implications. We would also use this issue to gather more information, get more feedback from the community, and have a proper discussion about the new feature.

### Improving Documentation

Submitting documentation updates, enhancements, designs, or bug fixes, as well as spelling or grammar fixes is much appreciated.

### Helping Someone

Consider searching for Appwrite on Discord, GitHub, or StackOverflow to help someone who needs help. You can also help by teaching others how to contribute to Appwrite's repo!
