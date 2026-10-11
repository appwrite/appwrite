---
layout: article
title: Start with Storage
description: Get started quickly with Appwrite Storage. Follow step-by-step instructions to set up storage, upload files, and integrate cloud storage into your projects
difficulty: beginner
readtime: 5
---

You can create your first bucket, upload, and download your first file in minutes.

# Create bucket {% #create-bucket %}
You can create a bucket in the Appwrite Console by navigating to **Storage** > **Create bucket**.

In your bucket, navigate to **Settings** > **Permissions**, then add a new **Any** role with **CREATE** and **READ** permissions.
This allows anyone to create and read files in this bucket.

# Create file {% #create-file %}

To upload a file, add this to your app. For web apps, you can use the File object directly. For Node.js apps, use the InputFile class.

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

  const nodeFile = InputFile.fromPath('/path/to/file.jpg', 'file.jpg');
  await storage.createFile({
    bucketId: '<BUCKET_ID>',
    fileId: sdk.ID.unique(),
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

  const promise = storage.createFile(
      '<BUCKET_ID>',
      ID.unique(),
      {
          name: 'image.jpg',
          type: 'image/jpeg',
          size: 1234567,
          uri: 'file:///path/to/file.jpg',
      }
  );

  promise.then(function (response) {
      console.log(response); // Success
  }, function (error) {
      console.log(error); // Failure
  });
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

const result = storage.getFileDownload({
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>'
});

console.log(result); // Resource URL
```
{% /multicode %}
