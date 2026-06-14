---
layout: post
title: "Up to 7x faster Appwrite Storage uploads with parallel chunks"
description: Appwrite SDKs now upload Storage chunks in parallel on runtimes with native concurrency. Our Node SDK benchmarks show up to 7.10x faster uploads on large files, with no API changes.
date: 2026-05-21
cover: /images/blog/faster-storage-uploads-parallel-chunks/cover.avif
timeToRead: 9
author: eldad-fux
category: announcement
featured: false
---

Uploading large files to [Appwrite Storage](/docs/products/storage) should feel snappy on a good connection and **bearable on real-world networks**. Until now, SDKs typically sent file chunks one after another. That approach is simple and reliable, but it leaves performance on the table. Each chunk waits for the previous one, so you rarely saturate bandwidth or use the browser's ability to run several HTTP requests in parallel.

We are releasing updated **Appwrite SDKs** that upload **multiple chunks at the same time**, with defaults tuned for how browsers and HTTP clients actually behave. The speedup scales with file size: in our Node SDK benchmarks, a 1.28 GB upload dropped from **4 minutes 44 seconds to under 40 seconds, a 7.10x improvement** at the default concurrency of 8.

# Why sequential chunks cap your speed

Chunked uploads exist so large files do not need to live entirely in memory and so failures can be retried at chunk granularity. The tradeoff is that strictly sequential chunking means:

- **Underused bandwidth** - the connection often sits idle while the client waits on the next chunk.
- **Latency stacked in series** - every chunk pays its own round trip one after another.
- **Browser limits left unused** - browsers allow multiple connections per origin, but a single in-flight upload does not use that capacity.

The server must accept chunks in a well-defined way and assemble a complete file safely. Making uploads parallel required both smarter clients and a backend that could handle out-of-order and concurrent chunk writes without corrupting metadata or final assembly.

# What we shipped

The same establish-then-parallelize pattern is implemented everywhere the host runtime supports it. Each SDK uses that language's native concurrency primitives so multiple chunk requests run together without blocking each other on the wire. The updated SDKs:

- Establish the upload by sending the first chunk in a controlled way to obtain an upload identifier.
- Upload the remaining chunks concurrently up to a fixed maximum parallelism.

The examples below are the same `createFile` calls you use today. Point them at a large file, bump the SDK and server, and uploads get faster with no extra parameters for parallelism.

{% multicode %}
```client-web
import { Client, Storage, ID } from 'appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const storage = new Storage(client);

const uploaded = await storage.createFile({
    bucketId: 'videos',
    fileId: ID.unique(),
    file: document.getElementById('uploader').files[0]
});
```
```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final storage = Storage(client);

final uploaded = await storage.createFile(
    bucketId: 'videos',
    fileId: ID.unique(),
    file: InputFile.fromPath(
        path: './large-video.mp4',
        filename: 'large-video.mp4',
    ),
);
```
```client-react-native
import { Client, Storage, ID } from 'react-native-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const storage = new Storage(client);

const uploaded = await storage.createFile({
    bucketId: 'videos',
    fileId: ID.unique(),
    file: {
        name: 'large-video.mp4',
        type: 'video/mp4',
        size: fileSize,
        uri: fileUri
    }
});
```
```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let storage = Storage(client)

let uploaded = try await storage.createFile(
    bucketId: "videos",
    fileId: ID.unique(),
    file: InputFile.fromPath("./large-video.mp4")
)
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.ID
import io.appwrite.models.InputFile
import io.appwrite.services.Storage

suspend fun upload() {
    val client = Client(applicationContext)
        .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
        .setProject("<PROJECT_ID>")

    val storage = Storage(client)

    val uploaded = storage.createFile(
        bucketId = "videos",
        fileId = ID.unique(),
        file = InputFile.fromPath("./large-video.mp4")
    )
}
```
```server-nodejs
import { Client, Storage, ID } from 'node-appwrite';
import { InputFile } from 'node-appwrite/file';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<API_KEY>');

const storage = new Storage(client);

const uploaded = await storage.createFile({
    bucketId: 'videos',
    fileId: ID.unique(),
    file: InputFile.fromPath('./large-video.mp4', 'large-video.mp4')
});
```
```server-python
from appwrite.client import Client
from appwrite.services.storage import Storage
from appwrite.id import ID
from appwrite.input_file import InputFile

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<API_KEY>')

storage = Storage(client)

uploaded = storage.create_file(
    bucket_id='videos',
    file_id=ID.unique(),
    file=InputFile.from_path('./large-video.mp4'),
)
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<API_KEY>');

final storage = Storage(client);

final uploaded = await storage.createFile(
    bucketId: 'videos',
    fileId: ID.unique(),
    file: InputFile.fromPath(
        path: './large-video.mp4',
        filename: 'large-video.mp4',
    ),
);
```
```server-php
<?php

use Appwrite\Client;
use Appwrite\ID;
use Appwrite\InputFile;
use Appwrite\Services\Storage;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    ->setProject('<PROJECT_ID>')
    ->setKey('<API_KEY>');

$storage = new Storage($client);

$uploaded = $storage->createFile(
    bucketId: 'videos',
    fileId: ID::unique(),
    file: InputFile::withPath('./large-video.mp4'),
);
```
```server-ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
    .set_project('<PROJECT_ID>')
    .set_key('<API_KEY>')

storage = Storage.new(client)

uploaded = storage.create_file(
    bucket_id: 'videos',
    file_id: ID.unique,
    file: InputFile.from_path('./large-video.mp4'),
)
```
```server-go
package main

import (
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/file"
    "github.com/appwrite/sdk-for-go/id"
    "github.com/appwrite/sdk-for-go/storage"
)

func main() {
    clt := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1"),
        client.WithProject("<PROJECT_ID>"),
        client.WithKey("<API_KEY>"),
    )

    service := storage.New(clt)

    uploaded, err := service.CreateFile(
        "videos",
        id.Unique(),
        file.NewInputFile("./large-video.mp4", "large-video.mp4"),
    )
    _ = uploaded
    _ = err
}
```
```server-rust
use appwrite::Client;
use appwrite::services::Storage;
use appwrite::InputFile;
use appwrite::id::ID;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<API_KEY>");

    let storage = Storage::new(&client);

    let file = InputFile::from_path("./large-video.mp4", None).await?;

    let uploaded = storage.create_file(
        "videos",
        ID::unique(),
        file,
        None,
    ).await?;

    let _ = uploaded;
    Ok(())
}
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.ID
import io.appwrite.models.InputFile
import io.appwrite.services.Storage

suspend fun upload() {
    val client = Client()
        .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
        .setProject("<PROJECT_ID>")
        .setKey("<API_KEY>")

    val storage = Storage(client)

    val uploaded = storage.createFile(
        bucketId = "videos",
        fileId = ID.unique(),
        file = InputFile.fromPath("./large-video.mp4")
    )
}
```
```server-swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<API_KEY>")

let storage = Storage(client)

let uploaded = try await storage.createFile(
    bucketId: "videos",
    fileId: ID.unique(),
    file: InputFile.fromPath("./large-video.mp4")
)
```
```server-dotnet
using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1")
    .SetProject("<PROJECT_ID>")
    .SetKey("<API_KEY>");

Storage storage = new Storage(client);

File uploaded = await storage.CreateFile(
    bucketId: "videos",
    fileId: ID.Unique(),
    file: InputFile.FromPath("./large-video.mp4")
);
```
{% /multicode %}

# Benchmarks

We benchmarked the Node SDK uploading files from 10 MB up to 1.28 GB, comparing the old sequential client against the new parallel client at the default concurrency of 8.

| File size | Sequential | Parallel | Speedup | Concurrency | Chunks |
| --- | --- | --- | --- | --- | --- |
| 10 MB | 2,650 ms | 2,472 ms | 1.07x | 1 | 2 |
| 20 MB | 4,589 ms | 2,434 ms | 1.89x | 3 | 4 |
| 40 MB | 9,413 ms | 2,680 ms | 3.51x | 7 | 8 |
| 80 MB | 18,233 ms | 4,037 ms | 4.52x | 8 | 16 |
| 160 MB | 36,606 ms | 6,545 ms | 5.59x | 8 | 32 |
| 320 MB | 71,036 ms | 11,451 ms | 6.20x | 8 | 64 |
| 640 MB | 141,923 ms | 20,863 ms | 6.80x | 8 | 128 |
| 1.28 GB | 283,823 ms | 39,956 ms | **7.10x** | 8 | 256 |

The pattern is clear:

- **Small files** (a single chunk or two) cannot benefit from concurrency and stay close to the sequential baseline.
- **Large files** have enough chunks to saturate the worker pool, so the speedup climbs steeply, reaching up to 7.10x at 1.28 GB.

Your mileage will vary with region, device, bucket location, and network, but the larger the file, the larger the win.

# Get started

Parallel chunked uploads are now available on Appwrite Cloud. Upgrade your Appwrite SDK to the latest release for your language and your existing `createFile` calls inherit the faster uploads automatically - no API migration, no extra configuration.

The Appwrite Console also uses parallel chunking, so file uploads through the Console are faster too.

# More resources

- [Appwrite Storage documentation](/docs/products/storage)
- [The easiest way to add file uploads to your app](/blog/post/easiest-file-uploads)
