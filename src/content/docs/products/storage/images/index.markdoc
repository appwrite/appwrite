---
layout: article
title: Image transformations
description: Optimize image storage and processing with Appwrite. Explore image resizing, transformations, and manipulation to deliver rich media experiences in your apps.
---

Appwrite provides utilities to manipulate images for previewing images in your apps.

Appwrite Storage's [preview endpoint](/docs/references/cloud/client-web/storage#getFilePreview) let you manipulate resolution, add borders and the border-radius, add background-color, set the opacity for the image, and get the image in the appropriate output format.

You can manipulate images resolution to display appropriately on responsive websites. You can also adjust the image border, background color, and border-radius to match the theming of your application.
The Appwrite Storage also allows you to change the format and compression of your images for network transfer optimization and to help you speed your application. You can do all that without caring about how the image was originally uploaded.

{% info title="Caching" %}
When manipulating images in Appwrite, the resulting images are cached by Appwrite and your browser.
When you repeatedly use the same transformed images, the performance impact will be minimal.
{% /info %}

## Options {% #options %}

Below you can find all the different parameters offered by the preview endpoint to manipulate the image.

| Parameter     | Description                                                                                                     |
| --------------| --------------------------------------------------------------------------------------------------------------- |
| width         | Set the width of the output image in pixels, the image will be resized keeping the aspect ratio intact. Accepts integer between `0-4000`  |
| height        | Set the height of the output image in pixels, the image will be resized keeping the aspect ratio intact. Accepts integer between `0-4000` |
| gravity       | The gravity while cropping the image providing either width, height, or both. Accepts any of: `center`, `top-left`, `top`, `top-right`, `left`, `right`, `bottom-left`, `bottom`, `bottom-right`. |
| quality       | Set the quality of the output image. Accepts integer between `0-100`, where `100` is the highest quality.          |
| borderWidth   | Set a border with the given width in pixels to the output image. Accepts integer between `0-100`.               |
| borderColor   | Set a border-color for the output image. Accepts any valid hex color value without the leading `#`.            |
| borderRadius  | Set a border-radius in pixels. Accepts an integer between `0-4000`.                                             |
| opacity       | Set opacity for the output image. Accepts a float value between `0-1`, where `0` makes it transparent. Only works with output formats supporting alpha channels like `png`. |
| rotation      | Rotate the output image by a degree. Accepts an integer between `-360` to `360`.                                  |
| background    | Set a background-color. Accepts any valid hex color value without the leading `#`. Only works with output formats supporting alpha channels like `png`. |
| output        | Set the output image format. If not provided, will use the original image's format. Supported formats are: `jpg`, `jpeg`, `png`, `gif`, `webp`, `avif`, and `heic` |

# Examples {% #examples %}
Here are some examples using [Client SDKs](/docs/sdks#client).
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
    bucketId: 'photos',
    fileId: 'sunset.png',
    width: 1800,
    gravity: 'center',
    quality: '90',
    borderWidth: 5,
    borderColor: 'CDCA30',
    borderRadius: 15,
    background: 'FFFFFF'
});

console.log(result.href);
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
    bucketId: 'photos',
    fileId: 'sunset.png',
    width: 1800,
    height: 0,
    gravity: 'center',
    quality: '90',
    borderWidth: 5,
    borderColor: 'CDCA30',
    borderRadius: 15,
    opacity: 1,
    rotation: 0,
    background: "FFFFFF",
    output:'jpg'
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
            bucketId: "photos",
            fileId: "sunset.png",
            width: 1800,
            height: 0,
            gravity: "center",
            quality: "90",
            borderWidth: 5,
            borderColor: "CDCA30",
            borderRadius: 15,
            opacity: 1,
            rotation: 0,
            background: "FFFFFF",
            output:"jpg"
    )

    print(String(describing: byteBuffer)
}
```
```client-android-java
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
            bucketId = "photos",         // bucket ID
            fileId = "sunset.png",       // file ID
            width = 1800,                // width, will be resized using this value.
            height = 0,                  // height, ignored when 0
            gravity = "center",          // crop center
            quality = "90",              // slight compression
            borderWidth = 5,             // border width
            borderColor = "CDCA30",      // border color
            borderRadius = 15,           // border radius
            opacity = 1,                 // full opacity
            rotation = 0,                // no rotation
            background = "FFFFFF",       // background color
            output ="jpg"                // output jpg format
        )
        println(result); // Resource URL
    }
}
```
```client-react-native
import { Client, Storage, ImageGravity } from 'react-native-appwrite';
import { Image } from 'react-native';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const storage = new Storage(client);

// Get image with transformations
const result = storage.getFilePreview(
    'photos',           // bucket ID
    'sunset.png',       // file ID
    1800,               // width, will be resized using this value
    0,                  // height, ignored when 0
    ImageGravity.Center,// crop center
    90,                 // slight compression
    5,                  // border width
    'CDCA30',           // border color
    15,                 // border radius
    1,                  // full opacity
    0,                  // no rotation
    'FFFFFF',           // background color
    'jpg'               // output jpg format
);

console.log(imageUrl); // URL object

// Usage in a component
const ImagePreview = () => (
    <Image
        source={{ uri: imageUrl.toString() }}
        style={{ width: 300, height: 200 }}
        resizeMode="contain"
    />
);
```

{% /multicode %}
