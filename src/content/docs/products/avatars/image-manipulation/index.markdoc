---
layout: article
title: Image proxy
description: Transform remote images with resizing, cropping, and quality adjustments for optimal display and performance.
---

The image proxy endpoint allows you to fetch and transform images from remote URLs. You can resize, crop, and adjust the quality of images to optimize them for your application's display requirements and performance needs.

# Proxy remote image {% #get-image %}

Fetch and transform an image from a remote URL with various transformation options.

{% multicode %}
```client-web
import { Client, Avatars } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const avatars = new Avatars(client);

const result = avatars.getImage({
    url: 'https://example.com/image.jpg',
    width: 800,
    height: 600,
    quality: 90
});

console.log(result); // Resource URL
```
```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final avatars = Avatars(client);

Future result = avatars.getImage(
    url: 'https://example.com/image.jpg',
    width: 800,
    height: 600,
    quality: 90
).then((bytes) {
    // Use the transformed image bytes
    return bytes;
}).catchError((error) {
    print(error.response);
});
```
```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let avatars = Avatars(client)

let byteBuffer = try await avatars.getImage(
    url: "https://example.com/image.jpg",
    width: 800,
    height: 600,
    quality: 90
)
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Avatars

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val avatars = Avatars(client)

val result = avatars.getImage(
    url = "https://example.com/image.jpg",
    width = 800,
    height = 600,
    quality = 90
)
```
```client-react-native
import { Client, Avatars } from 'react-native-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const avatars = new Avatars(client);

const result = avatars.getImage({
    url: 'https://example.com/image.jpg',
    width: 800,
    height: 600,
    quality: 90
});

console.log(result); // Resource URL
```
{% /multicode %}

# Parameters {% #parameters %}

The `getImage` method accepts the following parameters:

| Parameter | Type | Description |
| --------- | ---- | ----------- |
| url | string | The URL of the remote image to fetch and transform. Must be a valid HTTP or HTTPS URL. |
| width | integer | The width of the output image in pixels. The image will be resized maintaining aspect ratio. Accepts values between `0-4000`. |
| height | integer | The height of the output image in pixels. The image will be resized maintaining aspect ratio. Accepts values between `0-4000`. |
| quality | integer | The quality of the output image. Accepts values between `0-100`, where `100` is the highest quality. Defaults to `100` if not provided. |
| gravity | string | The gravity point for cropping when both width and height are provided. Accepts: `center`, `top-left`, `top`, `top-right`, `left`, `right`, `bottom-left`, `bottom`, `bottom-right`. Defaults to `center`. |
| output | string | The output image format. Supported formats: `jpg`, `jpeg`, `png`, `gif`, `webp`, `avif`, `heic`. If not provided, uses the original image format. |

# Resizing {% #resizing %}

Resize images to specific dimensions while maintaining aspect ratio. When only width or height is provided, the other dimension is calculated automatically.

{% multicode %}
```client-web
// Resize by width only (height calculated automatically)
const widthOnly = avatars.getImage({
    url: 'https://example.com/image.jpg',
    width: 800
});

// Resize by height only (width calculated automatically)
const heightOnly = avatars.getImage({
    url: 'https://example.com/image.jpg',
    height: 600
});

// Resize to specific dimensions
const specificSize = avatars.getImage({
    url: 'https://example.com/image.jpg',
    width: 800,
    height: 600
});
```
```client-flutter
// Resize by width only (height calculated automatically)
Future widthOnly = avatars.getImage(
    url: 'https://example.com/image.jpg',
    width: 800
);

// Resize by height only (width calculated automatically)
Future heightOnly = avatars.getImage(
    url: 'https://example.com/image.jpg',
    height: 600
);

// Resize to specific dimensions
Future specificSize = avatars.getImage(
    url: 'https://example.com/image.jpg',
    width: 800,
    height: 600
);
```
```client-apple
// Resize by width only (height calculated automatically)
let widthOnly = try await avatars.getImage(
    url: "https://example.com/image.jpg",
    width: 800
)

// Resize by height only (width calculated automatically)
let heightOnly = try await avatars.getImage(
    url: "https://example.com/image.jpg",
    height: 600
)

// Resize to specific dimensions
let specificSize = try await avatars.getImage(
    url: "https://example.com/image.jpg",
    width: 800,
    height: 600
)
```
```client-android-kotlin
// Resize by width only (height calculated automatically)
val widthOnly = avatars.getImage(
    url = "https://example.com/image.jpg",
    width = 800
)

// Resize by height only (width calculated automatically)
val heightOnly = avatars.getImage(
    url = "https://example.com/image.jpg",
    height = 600
)

// Resize to specific dimensions
val specificSize = avatars.getImage(
    url = "https://example.com/image.jpg",
    width = 800,
    height = 600
)
```
{% /multicode %}

# Cropping {% #cropping %}

When both width and height are specified, you can control how the image is cropped using the gravity parameter.

{% multicode %}
```client-web
// Crop from center
const centerCrop = avatars.getImage({
    url: 'https://example.com/image.jpg',
    width: 400,
    height: 400,
    gravity: 'center'
});

// Crop from top-left
const topLeftCrop = avatars.getImage({
    url: 'https://example.com/image.jpg',
    width: 400,
    height: 400,
    gravity: 'top-left'
});

// Crop from bottom-right
const bottomRightCrop = avatars.getImage({
    url: 'https://example.com/image.jpg',
    width: 400,
    height: 400,
    gravity: 'bottom-right'
});
```
```client-flutter
// Crop from center
Future centerCrop = avatars.getImage(
    url: 'https://example.com/image.jpg',
    width: 400,
    height: 400,
    gravity: 'center'
);

// Crop from top-left
Future topLeftCrop = avatars.getImage(
    url: 'https://example.com/image.jpg',
    width: 400,
    height: 400,
    gravity: 'top-left'
);

// Crop from bottom-right
Future bottomRightCrop = avatars.getImage(
    url: 'https://example.com/image.jpg',
    width: 400,
    height: 400,
    gravity: 'bottom-right'
);
```
```client-apple
// Crop from center
let centerCrop = try await avatars.getImage(
    url: "https://example.com/image.jpg",
    width: 400,
    height: 400,
    gravity: "center"
)

// Crop from top-left
let topLeftCrop = try await avatars.getImage(
    url: "https://example.com/image.jpg",
    width: 400,
    height: 400,
    gravity: "top-left"
)

// Crop from bottom-right
let bottomRightCrop = try await avatars.getImage(
    url: "https://example.com/image.jpg",
    width: 400,
    height: 400,
    gravity: "bottom-right"
)
```
```client-android-kotlin
// Crop from center
val centerCrop = avatars.getImage(
    url = "https://example.com/image.jpg",
    width = 400,
    height = 400,
    gravity = "center"
)

// Crop from top-left
val topLeftCrop = avatars.getImage(
    url = "https://example.com/image.jpg",
    width = 400,
    height = 400,
    gravity = "top-left"
)

// Crop from bottom-right
val bottomRightCrop = avatars.getImage(
    url = "https://example.com/image.jpg",
    width = 400,
    height = 400,
    gravity = "bottom-right"
)
```
{% /multicode %}

# Quality and format {% #quality-format %}

Adjust image quality and output format to optimize file size and performance.

{% multicode %}
```client-web
// High quality JPEG
const highQuality = avatars.getImage({
    url: 'https://example.com/image.jpg',
    width: 1200,
    quality: 95,
    output: 'jpg'
});

// Optimized WebP
const webpOptimized = avatars.getImage({
    url: 'https://example.com/image.jpg',
    width: 1200,
    quality: 85,
    output: 'webp'
});

// Compressed for mobile
const mobileOptimized = avatars.getImage({
    url: 'https://example.com/image.jpg',
    width: 800,
    quality: 75,
    output: 'jpg'
});
```
```client-flutter
// High quality JPEG
Future highQuality = avatars.getImage(
    url: 'https://example.com/image.jpg',
    width: 1200,
    quality: 95,
    output: 'jpg'
);

// Optimized WebP
Future webpOptimized = avatars.getImage(
    url: 'https://example.com/image.jpg',
    width: 1200,
    quality: 85,
    output: 'webp'
);

// Compressed for mobile
Future mobileOptimized = avatars.getImage(
    url: 'https://example.com/image.jpg',
    width: 800,
    quality: 75,
    output: 'jpg'
);
```
```client-apple
// High quality JPEG
let highQuality = try await avatars.getImage(
    url: "https://example.com/image.jpg",
    width: 1200,
    quality: 95,
    output: "jpg"
)

// Optimized WebP
let webpOptimized = try await avatars.getImage(
    url: "https://example.com/image.jpg",
    width: 1200,
    quality: 85,
    output: "webp"
)

// Compressed for mobile
let mobileOptimized = try await avatars.getImage(
    url: "https://example.com/image.jpg",
    width: 800,
    quality: 75,
    output: "jpg"
)
```
```client-android-kotlin
// High quality JPEG
val highQuality = avatars.getImage(
    url = "https://example.com/image.jpg",
    width = 1200,
    quality = 95,
    output = "jpg"
)

// Optimized WebP
val webpOptimized = avatars.getImage(
    url = "https://example.com/image.jpg",
    width = 1200,
    quality = 85,
    output = "webp"
)

// Compressed for mobile
val mobileOptimized = avatars.getImage(
    url = "https://example.com/image.jpg",
    width = 800,
    quality = 75,
    output = "jpg"
)
```
{% /multicode %}

# Use cases {% #use-cases %}

Image proxy is commonly used for:

- **Responsive images**: Generate multiple sizes for different screen sizes and devices
- **Thumbnail generation**: Create thumbnails from full-size images for galleries and lists
- **Format optimization**: Convert images to modern formats like WebP or AVIF for better compression
- **Performance optimization**: Reduce image file sizes while maintaining acceptable quality
- **Content delivery**: Transform images on-the-fly for CDN delivery and caching
- **Secure content serving**: Serve untrusted user content securely through Appwrite's trusted proxy with proper SSL certification, protecting your application from mixed content warnings and security vulnerabilities

