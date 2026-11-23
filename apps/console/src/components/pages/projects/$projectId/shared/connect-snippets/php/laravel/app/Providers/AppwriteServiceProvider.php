<?php

namespace App\Providers;

use Appwrite\Client;
use Appwrite\Services\Project;
use Illuminate\Support\ServiceProvider;

class AppwriteServiceProvider extends ServiceProvider
{
  public function register(): void
  {
    $this->app->singleton(Client::class, fn () => (new Client())
      ->setEndpoint(env('APPWRITE_ENDPOINT'))
      ->setProject(env('APPWRITE_PROJECT_ID'))
      ->setKey(env('APPWRITE_API_KEY')));

    $this->app->singleton(
      Project::class,
      fn ($app) => new Project($app->make(Client::class))
    );
  }
}
