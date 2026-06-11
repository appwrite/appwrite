---
layout: article
title: Upload and download
description: Effortlessly upload and download files with Appwrite Storage. Learn how to handle file uploads, manage file versions, and ensure secure downloads in your applications.
---

You can upload and download files both programmatically using SDKs or through the Appwrite Console.

# Create file {% #create-file %}

After you create a bucket or have navigated to bucket details, you can access the **Files** tab so you can upload, view, delete and update files in the bucket using the Appwrite project's dashboard. You can also perform all those operations from Appwrite's client SDK, server SDKs, and REST APIs as long as you have the proper permission.

When you are in the **Files** tab, you can click **Add File** and select a file to upload. If the bucket is configured to accept the file type and size you are uploading, your file will be uploaded, and you will see the file in the list of files.

You can also upload files programmatically using our SDKs:

{% multicode %}
  ```client-web
  import { Client, Storage, ID } from "appwrite";

  const client = new Client()
      .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
      .setProject('<PROJECT_ID>');

  const storage = new Storage(client);

  const promise = storage.createFile({
      bucketId: '<BUCKET_ID>',
      fileId: ID.unique(),
      file: document.getElementById('uploader').files[0]
  });

  promise.then(function (response) {
      console.log(response); // Success
  }, function (error) {
      console.log(error); // Failure
  });
  ```

  ```server-nodejs
  const sdk = require('node-appwrite');
  const { InputFile } = require('node-appwrite/file');

  const client = new sdk.Client()
      .setEndpoint('https://cloud.appwrite.io/v1')
      .setProject('<PROJECT_ID>')
      .setKey('<API_KEY>');

  const storage = new sdk.Storage(client);

  // If running in a browser environment, you can use File directly
  const browserFile = new File(['hello'], 'hello.txt');
  await storage.createFile({
    bucketId: '<BUCKET_ID>',
    fileId: ID.unique(),
    file: browserFile
  });

  // If running in Node.js, use InputFile
  const nodeFile = InputFile.fromPath('/path/to/file.jpg', 'file.jpg');
  await storage.createFile({
    bucketId: '<BUCKET_ID>',
    fileId: ID.unique(),
    file: nodeFile
  });
  ```

  ```client-flutter
  import 'package:appwrite/appwrite.dart';

  void main() { // Init SDK
    final client = Client()
      .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
      .setProject('<PROJECT_ID>');

    final storage = Storage(client);

    final file = await storage.createFile(
      bucketId: '<BUCKET_ID>',
      fileId: ID.unique(),
      file: InputFile.fromPath(path: './path-to-files/image.jpg', filename: 'image.jpg'),
    );
  }
  ```

  ```client-android-kotlin
  import io.appwrite.Client
  import io.appwrite.services.Storage

  suspend fun main() {
      val client = Client(applicationContext)
          .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
          .setProject("<PROJECT_ID>") // Your project ID

      val storage = Storage(client)

      val file = storage.createFile(
          bucketId = "<BUCKET_ID>",
          fileId = ID.unique(),
          file = File("./path-to-files/image.jpg"),
      )
  }
  ```

  ```client-react-native
  import { Client, Storage, ID } from 'react-native-appwrite';

  const client = new Client()
      .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
      .setProject('<PROJECT_ID>');

  const storage = new Storage(client);

  const promise = storage.createFile({
      bucketId: '<BUCKET_ID>',
      fileId: ID.unique(),
      file: {
          name: 'image.jpg',
          type: 'image/jpeg',
          size: 1234567,
          uri: 'file:///path/to/file.jpg',
      }
  });

  promise.then(function (response) {
      console.log(response); // Success
  }, function (error) {
      console.log(error); // Failure
  });
  ```

  ```client-apple
  import Appwrite

  func main() async throws {
      let client = Client()
        .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
        .setProject("<PROJECT_ID>")

      let storage = Storage(client)

      let file = try await storage.createFile(
          bucketId: "<BUCKET_ID>",
          fileId: ID.unique(),
          file: InputFile.fromBuffer(yourByteBuffer,
              filename: "image.jpg",
              mimeType: "image/jpeg"
          )
      )
  }
  ```

  ```server-rust
  use appwrite::Client;
  use appwrite::services::storage::Storage;
  use appwrite::input_file::InputFile;
  use appwrite::id::ID;

  #[tokio::main]
  async fn main() -> Result<(), Box<dyn std::error::Error>> {
      let client = Client::new()
          .set_endpoint("https://cloud.appwrite.io/v1")
          .set_project("<PROJECT_ID>")
          .set_key("<API_KEY>");

      let storage = Storage::new(&client);

      let file = InputFile::from_path("/path/to/file.jpg", None).await?;
      let result = storage.create_file(
          "<BUCKET_ID>",
          ID::unique(),
          file,
          None, // permissions (optional)
      ).await?;

      println!("{:?}", result);
      Ok(())
  }
  ```

  ```http
  POST /v1/storage/buckets/{bucketId}/files HTTP/1.1
  Content-Type: multipart/form-data; boundary="cec8e8123c05ba25"
  Content-Length: *Length of your entity body in bytes*
  X-Appwrite-Project: <PROJECT_ID>

  --cec8e8123c05ba25
  Content-Disposition: form-data; name="operations"

  { "query": "mutation CreateFile($bucketId: String!, $fileId: String!, $file: InputFile!) { storageCreateFile(bucketId: $bucketId, fileId: $fileId, file: $file) { id } }", "variables": { "bucketId": "<BUCKET_ID>", "fileId": "<FILE_ID>", "file": null } }
  --cec8e8123c05ba25
  Content-Disposition: form-data; name="map"

  { "0": ["variables.file"] }
  --cec8e8123c05ba25
  Content-Disposition: form-data; name="0"; filename="file.txt"
  Content-Type: text/plain

  File content.

  --cec8e8123c05ba25--
  ```

{% /multicode %}

# Large files {% #large-files %}
When you are trying to upload any files above 5MB, you will need to upload them in chunks for better reliability and performance.
If you're using an Appwrite SDK, this is handled automatically.
If you're not using an SDK, you can [learn more about REST API file handling](/docs/apis/rest#files).

# InputFile {% #input-file %}
Every language and platform handles file inputs differently. This section rows the expected input type of each SDK. Where applicable, Appwrite provides an `InputFile` class to accept multiple file sources, like paths, buffers, or plain text.

# Client SDKs {% #client-sdks %}

{% tabs %}
{% tabsitem #web title="Web" %}
The Appwrite Web SDK expects a [File](https://developer.mozilla.org/en-US/docs/Web/API/File) object for file creation. This is most commonly associated with DOM file inputs.

For example, for the input tag `<input type="file" id="uploader">`, you would call create file like this:

```js
const promise = storage.createFile({
    bucketId: '<BUCKET_ID>',
    fileId: ID.unique(),
    file: document.getElementById('uploader').files[0]
});
```
{% /tabsitem %}

{% tabsitem #flutter title="Flutter" %}

The Appwrite Flutter SDK expects an `InputFile` class for file inputs.

| Method                                     | Description                                                  |
| ------------------------------------------ | ------------------------------------------------------------ |
| `InputFile.fromPath(path: [PATH], filename: [NAME], contentType: [MIME TYPE])` | Used to upload files from a provided path, `filename` and `contentType` are optional. Used for Flutter apps on mobile and desktop. |
| `InputFile.fromBytes(bytes: [BYTE LIST], filename: [NAME], contentType: [MIME TYPE])` | Used to upload files from a byte list, `contentType` is optional. Used for Flutter apps on the web. |
{% /tabsitem %}

{% tabsitem #android title="Android" %}

The Appwrite Android SDK expects an `InputFile` class for file inputs.

| Method                         | Description                                      |
| ------------------------------ | ------------------------------------------------ |
| `InputFile.fromPath(path: String)` | Used to upload files from a provided path.     |
| `InputFile.fromFile(file: File)`   | Used to upload files from a [File](https://docs.oracle.com/javase/8/docs/api/java/io/File.html) object. |
| `InputFile.fromBytes(bytes: ByteArray, filename: String, mimeType: String)` | Used to upload files from a [ByteArray](https://kotlinlang.org/api/latest/jvm/stdlib/kotlin/-byte-array/) object. Specify the file [MIME type](https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/MIME_types) using the `mimeType` param. |

{% /tabsitem %}

{% tabsitem #apple title="Apple" %}
The Appwrite Apple SDK expects an `InputFile` class for file inputs.

| Method                                     | Description                                                  |
| ------------------------------------------ | ------------------------------------------------------------ |
| `InputFile.fromPath(_ path: String)`        | Used to upload files from a provided path.                  |
| `InputFile.fromData(_ data: Data, filename: String, mimeType: String)` | Used to upload files from a [Data](https://developer.apple.com/documentation/foundation/data) object. Specify the file [MIME type](https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/MIME_types) using the `mimeType` param. |
| `InputFile.fromBuffer(_ buffer: ByteBuffer, filename: String, mimeType: String)` | Used to upload files from a [NIO Buffer](https://swiftinit.org/reference/swift-nio/niocore/bytebuffer) object. Specify the file [MIME type](https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/MIME_types) using the `mimeType` param. |
{% /tabsitem %}

{% tabsitem #react-native title="React Native" %}
The Appwrite React Native SDK expects a file object with the following properties for file inputs:

| Property | Description |
| -------- | ----------- |
| `name` | The name of the file. |
| `type` | The MIME type of the file. |
| `size` | The size of the file in bytes. |
| `uri` | The URI of the file on the device. |

This object structure aligns with what is typically returned from image picker libraries such as `react-native-image-picker`:

```js
// Example with react-native-image-picker
import { launchImageLibrary } from 'react-native-image-picker';

const pickImage = async () => {
  const result = await launchImageLibrary({
    mediaType: 'photo',
  });

  if (result.assets && result.assets[0]) {
    const fileInfo = result.assets[0];

    return {
      name: fileInfo.fileName,
      type: fileInfo.type,
      size: fileInfo.fileSize,
      uri: fileInfo.uri,
    };
  }
};
```

You can also use the file picker or row picker from Expo:

```js
// Example with expo-row-picker
import * as DocumentPicker from 'expo-row-picker';

const pickDocument = async () => {
  const result = await DocumentPicker.getRowAsync();

  if (result.assets && result.assets[0]) {
    return {
      name: result.assets[0].name,
      type: result.assets[0].mimeType,
      size: result.assets[0].size,
      uri: result.assets[0].uri,
    };
  }
};
```
{% /tabsitem %}
{% /tabs %}

# Server SDKs {% #server-sdks %}

{% tabs %}
{% tabsitem #nodejs title="Node.js" %}
In browser environments, you can use the `File` object directly. For Node.js environments, import the `InputFile` class from 'node-appwrite/file'.

When using `InputFile`, the following methods are available:

| Method                                     | Description                                                  |
| ------------------------------------------ | ------------------------------------------------------------ |
| `InputFile.fromPath(filePath, filename)`     | Used to upload files from a provided path.                  |
| `InputFile.fromBuffer(buffer, filename)`     | Used to upload files from a [Buffer](https://nodejs.org/api/buffer.html#buffer) or [Blob](https://developer.mozilla.org/en-US/docs/Web/API/Blob) object. |
| `InputFile.fromPlainText(content, filename)`  | Used to upload files in plain text. Expects a string encoded in UTF-8. |
{% /tabsitem %}

{% tabsitem #php title="PHP" %}

The Appwrite PHP SDK expects an `InputFile` class for file inputs.

| Method                                     | Description                                                  |
| ------------------------------------------ | ------------------------------------------------------------ |
| `InputFile.withPath(string $path, ?string $mimeType = null, ?string $filename = null)` | Used to upload files from a provided path.                  |
| `InputFile.withData(string $data, ?string $mimeType = null, ?string $filename = null)` | Used to upload files from a string.                         |
{% /tabsitem %}

{% tabsitem #python title="Python" %}
The Appwrite Python SDK expects an `InputFile` class for file inputs.

| Method                                     | Description                                                  |
| ------------------------------------------ | ------------------------------------------------------------ |
| `InputFile.from_path(path)`                | Used to upload files from a provided path.                  |
| `InputFile.from_bytes(bytes)`              | Used to upload files from an array of bytes.               |
{% /tabsitem %}

{% tabsitem #ruby title="Ruby" %}
The Appwrite Ruby SDK expects an `InputFile` class for file inputs.

| Method                                     | Description                                                  |
| ------------------------------------------ | ------------------------------------------------------------ |
| `InputFile.from_path(path)`                | Used to upload files from a provided path.                  |
| `InputFile.from_string(string)`            | Used to upload files from a String.                         |
| `InputFile.from_bytes(bytes)`              | Used to upload files from an array of bytes.               |

{% /tabsitem %}

{% tabsitem #deno title="Deno" %}
The Appwrite Deno SDK expects an `InputFile` class for file inputs.

| Method                                     | Description                                                  |
| ------------------------------------------ | ------------------------------------------------------------ |
| `InputFile.fromPath(filePath, filename)`     | Used to upload files from a provided path.                  |
| `InputFile.fromBuffer(buffer, filename)`     | Used to upload files from a [Uint8Array](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Uint8Array) object. |
| `InputFile.fromPlainText(content, filename)`  | Used to upload files in plain text. Expects a string encoded in UTF-8. |
{% /tabsitem %}

{% tabsitem #dart title="Dart" %}
The Appwrite Dart SDK expects an `InputFile` class for file inputs.

| Method                                     | Description                                                  |
| ------------------------------------------ | ------------------------------------------------------------ |
| `InputFile.fromPath(path: [PATH], filename: [NAME], contentType: [MIME TYPE])` | Used to upload files from a provided path, `filename` and `contentType` are optional. |
| `InputFile.fromBytes(bytes: [BYTE LIST], filename: [NAME], contentType: [MIME TYPE])` | Used to upload files from a byte list, `contentType` is optional. |
{% /tabsitem %}

{% tabsitem #kotlin title="Kotlin" %}

The Appwrite Kotlin SDK expects an `InputFile` class for file inputs.

| Method                                     | Description                                                  |
| ------------------------------------------ | ------------------------------------------------------------ |
| `InputFile.fromPath(path: String)`          | Used to upload files from a provided path.                  |
| `InputFile.fromFile(file: File)`            | Used to upload files from a [File](https://docs.oracle.com/javase/8/docs/api/java/io/File.html) object. |
| `InputFile.fromBytes(bytes: ByteArray, filename: String, mimeType: String)` | Used to upload files from a [ByteArray](https://kotlinlang.org/api/latest/jvm/stdlib/kotlin/-byte-array/) object. Specify the file [MIME type](https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/MIME_types) using the `mimeType` param. |
{% /tabsitem %}

{% tabsitem #swift title="Swift" %}
The Appwrite Swift SDK expects an `InputFile` class for file inputs.

| Method                                     | Description                                                  |
| ------------------------------------------ | ------------------------------------------------------------ |
| `InputFile.fromPath(_ path: String)`        | Used to upload files from a provided path.                  |
| `InputFile.fromData(_ data: Data, filename: String, mimeType: String)` | Used to upload files from a [Data](https://developer.apple.com/documentation/foundation/data) object. Specify the file [MIME type](https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/MIME_types) using the `mimeType` param. |
| `InputFile.fromBuffer(_ buffer: ByteBuffer, filename: String, mimeType: String)` | Used to upload files from a [NIO Buffer](https://swiftinit.org/reference/swift-nio/niocore/bytebuffer) object. Specify the file [MIME type](https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/MIME_types) using the `mimeType` param. |
{% /tabsitem %}

{% tabsitem #dotnet title=".NET" %}
The Appwrite .NET SDK expects an `InputFile` class for file inputs.

| Method                                     | Description                                                  |
| ------------------------------------------ | ------------------------------------------------------------ |
| `InputFile.FromPath(string path)`           | Used to upload files from a provided path.                  |
| `InputFile.FromBytes(byte[] bytes, string filename, string mimeType)` | Used to upload files from an array of bytes. Specify the file [MIME type](https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/MIME_types) using the `mimeType` param. |
{% /tabsitem %}
{% /tabs %}

# Get file {% #get-file %}
To get a metadata about a file, use the `getFile` method.

{% multicode %}
```client-web
import { Client, Storage } from "appwrite";

const client = new Client();

const storage = new Storage(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>') // Your project ID
;

const promise = storage.getFile({
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>'
});

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});
```
```client-flutter
import 'package:appwrite/appwrite.dart';

void main() { // Init SDK
  Client client = Client();
  Storage storage = Storage(client);

  client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>') // Your project ID
  ;
  // downloading file
  Future result = storage.getFile(
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
  ).then((bytes) {
    final file = File('path_to_file/filename.ext');
    file.writeAsBytesSync(bytes)
  }).catchError((error) {
      print(error.response);
  })
}

//displaying image preview
FutureBuilder(
  future: storage.getFile(
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
  ), //works for both public file and private file, for private files you need to be logged in
  builder: (context, snapshot) {
    return snapshot.hasData && snapshot.data != null
      ? Image.memory(
          snapshot.data,
        )
      : CircularProgressIndicator();
  },
);
```
```client-apple
import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
      .setProject("<PROJECT_ID>")

      let storage = Storage(client)

      let byteBuffer = try await storage.getFile(
          bucketId: "<BUCKET_ID>",
          fileId: "<FILE_ID>"
      )

      print(String(describing: byteBuffer)
}
```
```client-android-kotlin
import androidx.appcompat.app.AppCompatActivity
import android.os.Bundle
import kotlinx.coroutines.GlobalScope
import kotlinx.coroutines.launch
import io.appwrite.Client
import io.appwrite.services.Storage

class MainActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        val client = Client(applicationContext)
            .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
            .setProject("<PROJECT_ID>") // Your project ID

        val storage = Storage(client)

        val result = storage.getFile(
            bucketId = "<BUCKET_ID>",
            fileId = "<FILE_ID>"
        )
        println(result); // Resource URL
    }
}
```
```client-react-native
import { Client, Storage } from 'react-native-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const storage = new Storage(client);

const promise = storage.getFile({
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>'
});

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});
```
{% /multicode %}

# Download file {% #download-file %}
To download a file, use the `getFileDownload` method.

{% multicode %}
```client-web
import { Client, Storage } from "appwrite";

const client = new Client();

const storage = new Storage(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>') // Your project ID
;

const result = storage.getFileDownload({
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>'
});

console.log(result); // Resource URL
```
```client-flutter
import 'package:appwrite/appwrite.dart';

void main() { // Init SDK
  Client client = Client();
  Storage storage = Storage(client);

  client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>') // Your project ID
  ;
  // downloading file
  Future result = storage.getFileDownload(
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
  ).then((bytes) {
    final file = File('path_to_file/filename.ext');
    file.writeAsBytesSync(bytes)
  }).catchError((error) {
      print(error.response);
  })
}

//displaying image preview
FutureBuilder(
  future: storage.getFileDownload(
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
  ), //works for both public file and private file, for private files you need to be logged in
  builder: (context, snapshot) {
    return snapshot.hasData && snapshot.data != null
      ? Image.memory(
          snapshot.data,
        )
      : CircularProgressIndicator();
  },
);
```
```client-apple
import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
      .setProject("<PROJECT_ID>") // Your project ID
    let storage = Storage(client)
    let byteBuffer = try await storage.getFileDownload(
        bucketId: "<BUCKET_ID>",
        fileId: "<FILE_ID>"
    )

    print(String(describing: byteBuffer))
}
```
```client-android-kotlin
import androidx.appcompat.app.AppCompatActivity
import android.os.Bundle
import kotlinx.coroutines.GlobalScope
import kotlinx.coroutines.launch
import io.appwrite.Client
import io.appwrite.services.Storage

class MainActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        val client = Client(applicationContext)
            .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
            .setProject("<PROJECT_ID>") // Your project ID

        val storage = Storage(client)

        val result = storage.getFileDownload(
            bucketId = "<BUCKET_ID>",
            fileId = "<FILE_ID>"
        )
        println(result); // Resource URL
    }
}
```
```client-react-native
import { Client, Storage } from 'react-native-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const storage = new Storage(client);

// Downloads the file data as ArrayBuffer
const result = await storage.getFileDownload({
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>'
});

console.log(result); // ArrayBuffer with file data

// To get just the download URL without downloading:
const downloadUrl = storage.getFileDownloadURL({
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>'
});

console.log(downloadUrl); // URL object
```
{% /multicode %}

# Get File Preview {% #get-file-preview %}
To get a file preview image , use the `getFilePreview` method.

{% multicode %}
```client-web
import { Client, Storage } from "appwrite";

const client = new Client();

const storage = new Storage(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>') // Your project ID
;

const result = storage.getFilePreview({
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>'
});

console.log(result); // Resource URL
```
```client-flutter
import 'package:appwrite/appwrite.dart';

void main() { // Init SDK
  Client client = Client();
  Storage storage = Storage(client);

  client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>') // Your project ID
  ;
  // downloading file
  Future result = storage.getFilePreview(
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
  ).then((bytes) {
    final file = File('path_to_file/filename.ext');
    file.writeAsBytesSync(bytes)
  }).catchError((error) {
      print(error.response);
  })
}

//displaying image preview
FutureBuilder(
  future: storage.getFilePreview(
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
  ), //works for both public file and private file, for private files you need to be logged in
  builder: (context, snapshot) {
    return snapshot.hasData && snapshot.data != null
      ? Image.memory(
          snapshot.data,
        )
      : CircularProgressIndicator();
  },
);
```
```client-apple
import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
      .setProject("<PROJECT_ID>") // Your project ID
    let storage = Storage(client)
    let byteBuffer = try await storage.getFilePreview(
        bucketId: "<BUCKET_ID>",
        fileId: "<FILE_ID>"
    )

    print(String(describing: byteBuffer))
}
```
```client-android-kotlin
import androidx.appcompat.app.AppCompatActivity
import android.os.Bundle
import kotlinx.coroutines.GlobalScope
import kotlinx.coroutines.launch
import io.appwrite.Client
import io.appwrite.services.Storage

class MainActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        val client = Client(applicationContext)
            .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
            .setProject("<PROJECT_ID>") // Your project ID

        val storage = Storage(client)

        val result = storage.getFilePreview(
            bucketId = "<BUCKET_ID>",
            fileId = "<FILE_ID>"
        )
        println(result); // Resource URL
    }
}
```
```client-react-native
import { Client, Storage, ImageGravity } from 'react-native-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const storage = new Storage(client);

// Downloads the preview image data as ArrayBuffer
const result = await storage.getFilePreview({
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
    width: 200,
    height: 200,
    gravity: ImageGravity.Center,
    quality: 100
});

console.log(result); // ArrayBuffer with image data

// To get just the preview URL without downloading:
const previewUrl = storage.getFilePreviewURL({
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
    width: 200,
    height: 200,
    gravity: ImageGravity.Center,
    quality: 100
});

console.log(previewUrl); // URL object
```
{% /multicode %}

# View File {% #view-file%}

To view a file, use the `getFileView` method.

{% multicode %}
```client-web
import { Client, Storage } from "appwrite";

const client = new Client();

const storage = new Storage(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>') // Your project ID
;

const result = storage.getFileView({
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>'
});

console.log(result); // Resource URL
```
```client-flutter
import 'package:appwrite/appwrite.dart';

void main() { // Init SDK
  Client client = Client();
  Storage storage = Storage(client);

  client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>') // Your project ID
  ;
  // downloading file
  Future result = storage.getFileView(
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
  ).then((bytes) {
    final file = File('path_to_file/filename.ext');
    file.writeAsBytesSync(bytes)
  }).catchError((error) {
      print(error.response);
  })
}

//displaying image preview
FutureBuilder(
  future: storage.getFileView(
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
  ), //works for both public file and private file, for private files you need to be logged in
  builder: (context, snapshot) {
    return snapshot.hasData && snapshot.data != null
      ? Image.memory(
          snapshot.data,
        )
      : CircularProgressIndicator();
  },
);
```
```client-apple
import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
      .setProject("<PROJECT_ID>") // Your project ID
    let storage = Storage(client)
    let byteBuffer = try await storage.getFileView(
        bucketId: "<BUCKET_ID>",
        fileId: "<FILE_ID>"
    )

    print(String(describing: byteBuffer))
}
```
```client-android-kotlin
import androidx.appcompat.app.AppCompatActivity
import android.os.Bundle
import kotlinx.coroutines.GlobalScope
import kotlinx.coroutines.launch
import io.appwrite.Client
import io.appwrite.services.Storage

class MainActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        val client = Client(applicationContext)
            .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
            .setProject("<PROJECT_ID>") // Your project ID

        val storage = Storage(client)

        val result = storage.getFileView(
            bucketId = "<BUCKET_ID>",
            fileId = "<FILE_ID>"
        )
        println(result); // Resource URL
    }
}
```
```client-react-native
import { Client, Storage } from 'react-native-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const storage = new Storage(client);

// Downloads the file data as ArrayBuffer
const result = await storage.getFileView({
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>'
});

console.log(result); // ArrayBuffer with file data

// To get just the view URL without downloading:
const viewUrl = storage.getFileViewURL({
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>'
});

console.log(viewUrl); // URL object
```
{% /multicode %}
