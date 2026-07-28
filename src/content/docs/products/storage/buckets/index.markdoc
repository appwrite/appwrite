---
layout: article
title: Buckets
description: Organize and manage your files effectively with Appwrite Storage Buckets. Explore how to create, configure, and use storage buckets for seamless file organization.
---

Storage buckets are a group of files, similar to tables in Appwrite Databases.
Buckets let you limit file size and extensions, whether or not to encrypt the files, and more.

# Create Bucket {% #create-bucket %}
You can create your bucket from the Appwrite Console, a [Server SDK](/docs/sdks#server), or the [CLI](/docs/tooling/command-line/buckets).

{% tabs %}
{% tabsitem #console title="Console" %}
You can create a bucket by heading to the **Storage** page and clicking **Create bucket**.

{% only_dark %}
![Create bucket on console](/images/docs/storage/dark/create-bucket.avif)
{% /only_dark %}
{% only_light %}
![Create bucket on console](/images/docs/storage/create-bucket.avif)
{% /only_light %}
{% /tabsitem %}

{% tabsitem #server-sdk title="Server SDK" %}
You can also create tables programmatically using a [Server SDK](/docs/sdks#server). Appwrite [Server SDKs](/docs/sdks#server) require an [API key](/docs/partners/project/api-keys).

{% multicode %}

```server-nodejs
const sdk = require('node-appwrite');

// Init SDK
const client = new sdk.Client();

const storage = new sdk.Storage(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

const promise = storage.createBucket({
    bucketId: '<BUCKET_ID>',
    name: '<NAME>'
});

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});
```
```deno
import * as sdk from "npm:node-appwrite";

// Init SDK
let client = new sdk.Client();

let storage = new sdk.Storage(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;


let promise = storage.createBucket({
    bucketId: '<BUCKET_ID>',
    name: '<NAME>'
});

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});
```
```php
<?php

use Appwrite\Client;
use Appwrite\Services\Storage;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<PROJECT_ID>') // Your project ID
    ->setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

$storage = new Storage($client);

$result = $storage->createBucket('<BUCKET_ID>', '<NAME>');
```
```python
from appwrite.client import Client
from appwrite.services.storage import Storage

client = Client()

(client
  .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
  .set_project('<PROJECT_ID>') # Your project ID
  .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key
)

storage = Storage(client)

result = storage.create_bucket(bucket_id='<BUCKET_ID>', name='<NAME>')
```
```ruby
require 'Appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<PROJECT_ID>') # Your project ID
    .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

storage = Storage.new(client)

response = storage.create_bucket(bucket_id: '<BUCKET_ID>', name: '<NAME>')

puts response.inspect
```
```csharp
using Appwrite;
using Appwrite.Services;
using Appwrite.Models;

var client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<PROJECT_ID>") // Your project ID
    .SetKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

var storage = new Storage(client);

Bucket result = await storage.CreateBucket(
    bucketId: "<BUCKET_ID>",
    name: "<NAME>");
```
```dart
import 'package:dart_appwrite/dart_appwrite.dart';

void main() { // Init SDK
  Client client = Client();
  Storage storage = Storage(client);

  client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
  ;

  Future result = storage.createBucket(
    bucketId: '<BUCKET_ID>',
    name: '<NAME>',
  );

  result
    .then((response) {
      print(response);
    }).catchError((error) {
      print(error.response);
  });
}
```
```kotlin
import io.appwrite.Client
import io.appwrite.services.Storage

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>") // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

val storage = Storage(client)

val response = storage.createBucket(
    bucketId = "<BUCKET_ID>",
    name = "<NAME>",
)
```
```java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Storage;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>") // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

Storage storage = new Storage(client);

storage.createBucket(
    "<BUCKET_ID>",
    "<NAME>",
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);
```
```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>") // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

let storage = Storage(client)

let bucket = try await storage.createBucket(
    bucketId: "<BUCKET_ID>",
    name: "<NAME>"
)

```
```server-rust
use appwrite::Client;
use appwrite::services::storage::Storage;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        .set_project("<PROJECT_ID>")                 // Your project ID
        .set_key("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

    let storage = Storage::new(&client);

    let bucket = storage.create_bucket(
        "<BUCKET_ID>",
        "<NAME>",
        None, // permissions (optional)
        None, // file_security (optional)
        None, // enabled (optional)
        None, // maximum_file_size (optional)
        None, // allowed_file_extensions (optional)
        None, // compression (optional)
        None, // encryption (optional)
        None, // antivirus (optional)
        None, // transformations (optional)
    ).await?;

    println!("{:?}", bucket);
    Ok(())
}
```
{% /multicode %}

You can also configure permission, file size and extension restrictions, and more in the `createBucket` method, learn more about the `createBucket` in the [API references](/docs/references/cloud/server-nodejs/storage#createBucket).
{% /tabsitem %}

{% tabsitem #cli title="CLI" %}

Create a bucket using the CLI command `appwrite init buckets`.

```sh
appwrite init buckets
```

This will initialize your bucket in your `appwrite.config.json` file. To push your initialized bucket, use the `appwrite push buckets`.

```sh
appwrite push buckets
```

This will create your bucket in the Console with all of your `appwrite.config.json` configurations.

{% arrow_link href="/docs/tooling/command-line/buckets#commands" %}
Learn more about the CLI buckets commands
{% /arrow_link %}

{% /tabsitem %}
{% /tabs %}

# Permissions {% #permissions %}
Appwrite uses permissions to control file access.
For security, only users that are granted permissions can access a file.
This helps prevent accidental data leaks by forcing you to make more concious decisions around permissions.

By default, Appwrite doesn't grants permissions to any users when a new bucket is created.
This means users can't create new files or read, update, and delete existing files.

[Learn about configuring permissions](/docs/products/storage/permissions).

# Encryption {% #encryption %}
Appwrite provides added security settings for your buckets. Enable encryption under your bucket's **Settings** > **Security settings**.
You can enable encryption to encrypt files in your buckets. If your files are leaked, encrypted files cannot be read by the malicious actor.
Files bigger than 20MB cannot be encrypted.

# Compression {% #compression %}
Appwrite allows you to compress your files.
Two algorithms are available, which are [gzip](https://www.gzip.org/) and [zstd](https://github.com/facebook/zstd).
You can enable compress under your bucket's **Settings** > **Compression**.
For files larger than 20MB, compression will be skipped even when enabled.

# Maximum file size {% #max-size %}
Limit the maximum file size allowed in the bucket to prevent abuse.
You can configure maximum file size under your bucket's **Settings** > **Maximum file size**.

# File extensions {% #extensions %}
Limit the file extensions allowed in the bucket to prevent abuse. A maximum of 100 file extensions can be added. Leave blank to allow all file types.
You can configure maximum file size under your bucket's **Settings** > **File extensions**.
