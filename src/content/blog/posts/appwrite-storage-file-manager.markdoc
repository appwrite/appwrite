---
layout: post
title: How to build a file manager with Appwrite Storage
description: Learn how to create buckets, upload and download files, set permissions, and build a complete file manager UI using Appwrite Storage.
date: 2026-03-24
lastUpdated: 2026-06-29
cover: /images/blog/appwrite-storage-file-manager/cover.avif
timeToRead: 5
author: aditya-oberai
category: product, tutorial
featured: false
unlisted: true
faqs:
  - question: "What is the difference between Appwrite Storage and Appwrite Databases?"
    answer: "Appwrite Databases store structured data such as user profiles, product records, and settings. Appwrite Storage stores files such as images, PDFs, videos, and documents in buckets. They are intentionally separate, and it is common to store a file ID in a database row to link a file to its structured metadata."
  - question: "What does the fileSecurity flag on a bucket do?"
    answer: "When fileSecurity is set to true, each file can have its own permissions in addition to the bucket-level permissions. When false, only bucket-level permissions apply. For a multi-user file manager where each user owns specific files, you should enable fileSecurity so per-file permissions take effect."
  - question: "How does Appwrite Storage handle large files?"
    answer: "The Appwrite SDK handles chunked uploads automatically when you call createFile with a standard browser File object. You do not need to manage chunking yourself, which makes it straightforward to upload large files reliably from a browser file input or drag-and-drop zone."
  - question: "Can Appwrite generate image thumbnails or previews?"
    answer: "Yes. Appwrite Storage offers built-in image transformations through getFilePreview, where you can request a resized thumbnail with parameters like width, height, gravity, and quality. This means you can serve thumbnails without integrating a separate image transformation service."
  - question: "How do I make sure each user can only see their own uploaded files?"
    answer: "Set the bucket to allow authenticated users to create files (for example create(\"users\")) and set per-file permissions on upload to the uploading user only (read, update, and delete on user:USER_ID). Enable fileSecurity on the bucket so file-level permissions are enforced alongside the bucket-level permissions."
  - question: "What is the difference between getFileDownload and getFileView?"
    answer: "getFileDownload returns a URL that forces a browser download of the file. getFileView returns a URL that serves the file with appropriate content headers, which makes it suitable for inline display such as showing an image directly in an img tag."
---

File management is one of those features that sounds simple until you're deep in the weeds: S3 bucket policies, pre-signed URLs, CORS headers, permission checks. [Appwrite Storage](/docs/products/storage) is designed to cut through that complexity with a clean API and bucket-based organization that maps naturally to most app architectures.

This post walks through creating buckets, uploading and downloading files, listing files in a bucket, and setting permissions, then ties it all together with a simple file manager UI flow.

# What Appwrite Storage gives you

Appwrite Storage handles file uploads, downloads, deletions, and listings through a REST API (and SDK wrappers for all major platforms). Files live in buckets, which are isolated containers with their own permission settings, size limits, and allowed MIME types.

Storage is intentionally separate from Appwrite Databases. Databases store structured data: user profiles, product records, settings. Storage stores files: images, PDFs, videos, rows. Both can be used together, and it's common to store a file ID in a database row to link them.

# Creating a bucket

Before you can store anything, you need a bucket. You can create one in the Appwrite Console or via the Server SDK.

In the Console, go to **Storage**, click **Create bucket**, give it a name, and configure the settings: maximum file size, allowed file extensions, and whether files are encrypted at rest.

Via the Node.js Server SDK:

```js
import { Client, Storage, ID } from 'node-appwrite';

const client = new Client()
  .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
  .setProject('<PROJECT_ID>')
  .setKey('<API_KEY>');

const storage = new Storage(client);

const bucket = await storage.createBucket({
    bucketId: ID.unique(),
    name: 'User Uploads',
    permissions: ['any'],
    fileSecurity: true,
    enabled: true,
    maximumFileSize: 10000000,
    allowedFileExtensions: ['image/jpeg', 'image/png', 'application/pdf']
});
```

The `fileSecurity` flag is important. When set to `true`, each file can have its own permissions in addition to bucket-level permissions. When `false`, only bucket-level permissions apply. For a multi-user file manager, you'll want `fileSecurity: true`.

# Uploading files

From the browser, use the Client SDK:

```js
import { Client, Storage, ID } from 'appwrite';

const client = new Client()
  .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
  .setProject('<PROJECT_ID>');

const storage = new Storage(client);

async function uploadFile(bucketId, file) {
  const result = await storage.createFile({
    bucketId,
    fileId: ID.unique(),
    file,
    // Permissions for this specific file
    permissions: [
      'read("user:<USER_ID>")',
      'update("user:<USER_ID>")',
      'delete("user:<USER_ID>")'
    ]
  });
  return result;
}
```

The `file` argument is a standard browser `File` object from an `<input type="file">` element. The SDK handles chunked uploads automatically for large files, so you don't need to manage that yourself.

For a file manager UI, wire this up to a file input or drag-and-drop zone:

```js
const fileInput = document.getElementById('file-input');

fileInput.addEventListener('change', async (e) => {
  const file = e.target.files[0];
  if (!file) return;

  const uploaded = await uploadFile('<BUCKET_ID>', file);
  console.log('Uploaded:', uploaded.$id);
  await refreshFileList();
});
```

# Listing files

To display uploaded files, use `listFiles`:

```js
async function listFiles(bucketId) {
  const files = await storage.listFiles({ bucketId });
  return files.files; // array of file objects
}
```

Each file object contains metadata: `$id`, `name`, `mimeType`, `sizeOriginal`, `$createdAt`, and `$updatedAt`. You can use this to render a table or grid view without downloading the actual file content.

```js
async function renderFileList(bucketId) {
  const files = await listFiles(bucketId);
  const list = document.getElementById('file-list');

  list.innerHTML = files.map(file => `
    <div class="file-item" data-id="${file.$id}">
      <span>${file.name}</span>
      <span>${(file.sizeOriginal / 1024).toFixed(1)} KB</span>
      <span>${new Date(file.$createdAt).toLocaleDateString()}</span>
      <button onclick="downloadFile('${bucketId}', '${file.$id}', '${file.name}')">
        Download
      </button>
      <button onclick="deleteFile('${bucketId}', '${file.$id}')">
        Delete
      </button>
    </div>
  `).join('');
}
```

# Downloading files

To download a file, get the file view URL and trigger a browser download:

```js
async function downloadFile(bucketId, fileId, fileName) {
  const url = storage.getFileDownload({ bucketId, fileId });
  const a = document.createElement('a');
  a.href = url;
  a.download = fileName;
  a.click();
}
```

`getFileDownload` returns a URL that forces a download. If you want to display images inline instead, use `getFileView`, which serves the file with appropriate content headers.

For image previews, Appwrite also offers built-in transformations. You can request a resized thumbnail without any third-party service:

```js
const thumbnailUrl = storage.getFilePreview({
  bucketId,
  fileId,
  width: 200,
  height: 200,
  gravity: 'center',
  quality: 80
});
```

# Setting permissions

Permissions in Appwrite follow a consistent pattern across all resources. For storage, you set permissions at two levels: the bucket and (when `fileSecurity` is enabled) the individual file.

Permission strings take the form `action("role")`. Common patterns:

```js
// Only authenticated users can read
'read("users")'

// Only a specific user can read, update, and delete
'read("user:<USER_ID>")'
'update("user:<USER_ID>")'
'delete("user:<USER_ID>")'

// A team with a specific role
'read("team:<TEAM_ID>/editor")'
'update("team:<TEAM_ID>/editor")'

// Public read access (anyone, including guests)
'read("any")'
```

For a typical file manager where each user owns their files:

- Set the bucket to allow authenticated users to create files: `'create("users")'`
- Set each file's permissions to the uploading user only: `'read("user:<USER_ID>")'`, `'update("user:<USER_ID>")'`, `'delete("user:<USER_ID>")'`

This way, users can only see and manage their own files, even though all files live in the same bucket.

# Deleting files

```js
async function deleteFile(bucketId, fileId) {
  await storage.deleteFile({ bucketId, fileId });
  await renderFileList(bucketId);
}
```

# Putting it together: a minimal file manager

Here's a sketch of the full flow:

1. On page load, call `listFiles` and render the file list.
2. A file input or drop zone calls `uploadFile` when the user selects a file, using `Permission.write(Role.user(currentUser.$id))` for ownership.
3. Each row in the list has download and delete buttons wired to `getFileDownload` and `deleteFile`.
4. For image files, show a thumbnail using `getFilePreview`.

The result is a functional, permissioned file manager backed entirely by Appwrite, no custom file storage server required.

# Start building with Appwrite Storage

Appwrite Storage handles the infrastructure so you can focus on building the experience. Buckets, permissions, image transformations, and chunked uploads are all included out of the box.

- [Appwrite Storage docs](/docs/products/storage)
- [Storage quick start](/docs/products/storage/quick-start)
- [Permissions reference](/docs/advanced/platform/permissions)
- [Start building on Appwrite Cloud](https://cloud.appwrite.io)
