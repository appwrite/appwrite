<?php

namespace Tests\E2E\Services\Projects;

use Appwrite\Auth\Auth;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Services\Domains\DomainsBase;
use Tests\E2E\Client;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\ID;

class DomainsRegistrarClientTest extends Scope
{
  use DomainsBase;
  use ProjectConsole;
  use SideClient;


  public function testCreateProject(): array
  {
      /**
       * Test for SUCCESS
       */
      $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
          'content-type' => 'application/json',
          'x-appwrite-project' => $this->getProject()['$id'],
      ], $this->getHeaders()), [
          'teamId' => ID::unique(),
          'name' => 'Project Test',
      ]);

      $this->assertEquals(201, $team['headers']['status-code']);
      $this->assertEquals('Project Test', $team['body']['name']);
      $this->assertNotEmpty($team['body']['$id']);

      $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
          'content-type' => 'application/json',
          'x-appwrite-project' => $this->getProject()['$id'],
      ], $this->getHeaders()), [
          'projectId' => ID::unique(),
          'name' => 'Project Test',
          'teamId' => $team['body']['$id'],
          'region' => 'default',
      ]);

      $this->assertEquals(201, $response['headers']['status-code']);
      $this->assertNotEmpty($response['body']['$id']);
      $this->assertEquals('Project Test', $response['body']['name']);
      $this->assertEquals($team['body']['$id'], $response['body']['teamId']);
      $this->assertArrayHasKey('platforms', $response['body']);
      $this->assertArrayHasKey('webhooks', $response['body']);
      $this->assertArrayHasKey('keys', $response['body']);

      $projectId = $response['body']['$id'];

      $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
          'content-type' => 'application/json',
          'x-appwrite-project' => $this->getProject()['$id'],
      ], $this->getHeaders()), [
          'projectId' => ID::unique(),
          'name' => 'Project Test',
          'teamId' => $team['body']['$id'],
      ]);

      $this->assertEquals(201, $response['headers']['status-code']);
      $this->assertNotEmpty($response['body']['$id']);
      $this->assertEquals('Project Test', $response['body']['name']);
      $this->assertEquals($team['body']['$id'], $response['body']['teamId']);
      $this->assertArrayHasKey('platforms', $response['body']);
      $this->assertArrayHasKey('webhooks', $response['body']);
      $this->assertArrayHasKey('keys', $response['body']);

      return ['projectId' => $projectId];
  }

    /**
     * @depends testCreateProject
     */
    public function testSuggestDomain($data): void
    {
      $id = $data['projectId'] ?? '';

      $response = $this->client->call(Client::METHOD_POST, '/domains/suggest', array_merge([
          'content-type' => 'application/json',
          'x-appwrite-project' => $this->getProject()['$id'],
      ], $this->getHeaders()), [
          'projectId' => $id,
          'domain' => 'kittens.com',
      ]);

      $this->assertEquals(200, $response['headers']['status-code']);
      $this->assertNotEmpty($response['body']['domains']);
    }

    /**
     * @depends testCreateProject
     */
    public function testAvailableDomain($data): void
    {
      $id = $data['projectId'] ?? '';

      $response = $this->client->call(Client::METHOD_POST, '/domains/available', array_merge([
          'content-type' => 'application/json',
          'x-appwrite-project' => $this->getProject()['$id'],
      ], $this->getHeaders()), [
          'projectId' => $id,
          'domain' => 'google.com',
      ]);

      $available = $response['body']['domain']['available'];

      $this->assertEquals(200, $response['headers']['status-code']);
      $this->assertNotEmpty($response['body']['domain']);
      $this->assertFalse($available);
    }

     /**
     * @depends testCreateProject
     */
    public function testCreate3rdPartyDomain($data): array
    {
      $id = $data['projectId'] ?? '';

      $response = $this->client->call(Client::METHOD_POST, '/domains', array_merge([
          'content-type' => 'application/json',
          'x-appwrite-project' => $this->getProject()['$id'],
      ], $this->getHeaders()), [
          'projectId' => $id,
          'domain' => 'example.com',
      ]);

      $this->assertEquals(201, $response['headers']['status-code']);
      $this->assertNotEmpty($response['body']['$id']);
      $this->assertEquals('example.com', $response['body']['domain']);
      $this->assertEquals('com', $response['body']['tld']);
      $this->assertEquals('example.com', $response['body']['registerable']);
      $this->assertEquals(false, $response['body']['verification']);


      /**
       * Test for FAILURE
       */
      $response = $response = $this->client->call(Client::METHOD_POST, '/domains', array_merge([
          'content-type' => 'application/json',
          'x-appwrite-project' => $this->getProject()['$id'],
      ], $this->getHeaders()), [
          'projectId' => $id,
          'domain' => 'sdkljgfhsdflkjghsdflkgjsh.com',
      ]);

      $this->assertEquals(409, $response['headers']['status-code']);

      $response = $response = $this->client->call(Client::METHOD_POST, '/domains', array_merge([
          'content-type' => 'application/json',
          'x-appwrite-project' => $this->getProject()['$id'],
      ], $this->getHeaders()), [
          'type' => 'web',
          'name' => 'Too Long Hostname',
          'key' => '',
          'store' => '',
          'hostname' => \str_repeat("bestdomain", 25) . '.com' // 250 + 4 chars total (exactly above limit)
      ]);

      return [];
    }

        /**
     * @depends testCreateProject
     */
    // public function testPurchaseDomain($data): void
    // {
    //   $id = $data['projectId'] ?? '';


    //   $response = $this->client->call(Client::METHOD_POST, '/domains/purchase', array_merge([
    //       'content-type' => 'application/json',
    //       'x-appwrite-project' => $this->getProject()['$id'],
    //   ], $this->getHeaders()), [
    //       'projectId' => $id,
    //       'domain' => 'dfksljgh24rlkgjhvlsdfkjgbhl.org',
    //       'firstname' => 'firstname',
    //       'lastname' => 'lastname',
    //       'phone' => '+18037889693',
    //       'email' => 'email@email.com',
    //       'address1' => 'address1 st',
    //       'address2' => 'unit address2',
    //       'address3' => 'apt. address3',
    //       'city' => 'city',
    //       'state' => 'state',
    //       'country' => 'us',
    //       'postalcode' => '29223',
    //       'org' => 'myorg',
    //   ]);



    //   $this->assertEquals(200, $response['headers']['status-code']);
    //   $this->assertNotEmpty($response['body']);
    //   $this->assertTrue($response['body'])
    // }

}