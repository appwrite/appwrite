<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Functions\Workers;

use Appwrite\Event\Message\Screenshot;
use Appwrite\Event\Realtime;
use Appwrite\Platform\Modules\Functions\Workers\Screenshots;
use Appwrite\Screenshots\Client;
use Appwrite\Screenshots\Exception as ScreenshotsException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Queue\Message;
use Utopia\Queue\PermanentFailure;
use Utopia\Storage\Device\Local;
use Utopia\Telemetry\Adapter\None;

require_once __DIR__ . '/../../../../../../app/init.php';

final class ScreenshotsTest extends TestCase
{
    private Database $dbForProject;

    private Database $dbForPlatform;

    private string $root;

    protected function setUp(): void
    {
        $this->dbForProject = $this->database('_1');
        $this->dbForProject->createCollection(Collection::create(id: 'deployments', attributes: [
            Attribute::string('resourceId'),
            Attribute::string('buildLogs', size: 1_000_000),
            Attribute::string('screenshotLight'),
            Attribute::string('screenshotDark'),
        ]));
        $this->dbForProject->createCollection(Collection::create(id: 'sites', attributes: [
            Attribute::string('framework'),
            Attribute::string('deploymentScreenshotLight'),
            Attribute::string('deploymentScreenshotDark'),
        ]));
        $this->dbForProject->createDocument('sites', new Document([
            '$id' => 'site',
            'framework' => 'nextjs',
            'deploymentScreenshotLight' => 'previous-light',
            'deploymentScreenshotDark' => 'previous-dark',
        ]));
        $deployment = $this->dbForProject->createDocument('deployments', new Document([
            '$id' => 'deployment',
            'resourceId' => 'site',
            'buildLogs' => '',
        ]));

        $this->dbForPlatform = $this->database('_console');
        $this->dbForPlatform->createCollection(Collection::create(id: 'rules', attributes: [
            Attribute::string('projectInternalId'),
            Attribute::string('type'),
            Attribute::string('deploymentInternalId'),
            Attribute::string('domain'),
        ]));
        $this->dbForPlatform->createCollection(Collection::create(id: 'buckets', attributes: [
            Attribute::string('name'),
        ]));
        $this->dbForPlatform->createDocument('rules', new Document([
            'projectInternalId' => '1',
            'type' => 'deployment',
            'deploymentInternalId' => $deployment->getSequence(),
            'domain' => 'admin.example.com',
        ]));
        $bucket = $this->dbForPlatform->createDocument('buckets', new Document(['$id' => 'screenshots', 'name' => 'screenshots']));
        $files = Config::getParam('collections', [])['buckets']['files'];
        $this->dbForPlatform->createCollection(Collection::create(
            id: 'bucket_' . $bucket->getSequence(),
            attributes: $files['attributes'],
            indexes: $files['indexes'],
        ));

        $this->root = \sys_get_temp_dir() . '/screenshots-' . \uniqid();
    }

    protected function tearDown(): void
    {
        (new Local($this->root))->delete($this->root, true);
    }

    /**
     * The browser service refuses a host whose DNS points at a private or
     * reserved address. nyc3 dead-lettered the same site's screenshots over and
     * over for it: no attempt can succeed until the customer changes DNS.
     */
    public function testUnroutableHostIsRefusedForGood(): void
    {
        $error = $this->capture($this->browser(new Response(
            400,
            body: new Stream(\json_encode(['error' => "Host 'admin.example.com' is not publicly routable."])),
        )));

        $this->assertInstanceOf(PermanentFailure::class, $error);
        $this->assertInstanceOf(ScreenshotsException::class, $error->getPrevious());
        $this->assertStringContainsString('Screenshot capturing failed', (string) $this->dbForProject->getDocument('deployments', 'deployment')->getAttribute('buildLogs'));
    }

    /**
     * The browser service answers a page that did not render in time with the
     * same 400. A slow cold start renders on the next attempt.
     */
    public function testPageTimeoutStaysRetriable(): void
    {
        $error = $this->capture($this->browser(new Response(
            400,
            body: new Stream(\json_encode(['error' => "screenshot: Timeout 30000ms exceeded.\nCall log:\n  - taking page screenshot\n  - waiting for fonts to load...\n"])),
        )));

        // Rethrown as the browser's own error, which the broker retries.
        $this->assertSame(ScreenshotsException::class, $error::class);
    }

    public function testDeletedDeploymentIsRefusedForGood(): void
    {
        $this->dbForProject->deleteDocument('deployments', 'deployment');

        $error = $this->capture($this->browser(fn () => $this->fail('A deleted deployment must not be captured')));

        $this->assertInstanceOf(PermanentFailure::class, $error);
    }

    /**
     * A deployment deleted while its pages were captured used to fail on the
     * log append with "Must define $id attribute", then again on the retry with
     * "Deployment not found". It must end once, and leave the site's preview of
     * its current deployment alone.
     */
    public function testDeploymentDeletedDuringCaptureIsRefusedForGood(): void
    {
        $error = $this->capture($this->browser(function (): ResponseInterface {
            $this->dbForProject->deleteDocument('deployments', 'deployment');

            return (new Response(200, body: new Stream('png')))->withHeader('Content-Type', 'image/png');
        }));

        $this->assertInstanceOf(PermanentFailure::class, $error);

        $site = $this->dbForProject->getDocument('sites', 'site');
        $this->assertSame('previous-light', $site->getAttribute('deploymentScreenshotLight'));
        $this->assertSame('previous-dark', $site->getAttribute('deploymentScreenshotDark'));
    }

    private function capture(Client $screenshots): ?\Throwable
    {
        $project = new Document(['$id' => 'project', '$sequence' => '1', 'teamId' => 'team']);

        try {
            (new Screenshots())->action(
                message: new Message([
                    'pid' => 'pid',
                    'queue' => 'v1-screenshots',
                    'timestamp' => \time(),
                    'payload' => (new Screenshot($project, 'deployment'))->toArray(),
                ]),
                queueForRealtime: $this->createStub(Realtime::class),
                dbForPlatform: $this->dbForPlatform,
                dbForProject: $this->dbForProject,
                project: $project,
                deviceForFiles: new Local($this->root),
                telemetry: new None(),
                screenshots: $screenshots,
            );
        } catch (\Throwable $error) {
            return $error;
        }

        return null;
    }

    private function browser(ResponseInterface|\Closure $response): Client
    {
        return new Client(new class ($response) implements ClientInterface {
            public function __construct(private ResponseInterface|\Closure $response)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response instanceof \Closure ? ($this->response)() : $this->response;
            }
        });
    }

    private function database(string $namespace): Database
    {
        // Workers run with authorization disabled, as app/worker.php sets it up.
        $authorization = new Authorization();
        $authorization->disable();

        $database = (new Database(new Memory(), new Cache(new MemoryCache())))
            ->setDatabase('appwrite')
            ->setNamespace($namespace)
            ->setAuthorization($authorization);
        $database->create();

        return $database;
    }
}
