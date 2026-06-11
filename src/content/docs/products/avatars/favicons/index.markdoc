---
layout: article
title: Favicons
description: Fetch favicons from remote websites for link previews and bookmark displays.
---

The favicon endpoint retrieves favicons from remote websites. This is useful for displaying website icons in link previews, bookmarks, and social sharing interfaces.

# Get favicon {% #get-favicon %}

Retrieve a favicon from a remote website URL.

{% multicode %}
```client-web
import { Client, Avatars } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const avatars = new Avatars(client);

const result = avatars.getFavicon({
    url: 'https://example.com',
    width: 100,
    height: 100
});

console.log(result); // Resource URL
```
```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final avatars = Avatars(client);

Future result = avatars.getFavicon(
    url: 'https://example.com',
    width: 100,
    height: 100
).then((bytes) {
    // Use the favicon image bytes
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

let byteBuffer = try await avatars.getFavicon(
    url: "https://example.com",
    width: 100,
    height: 100
)
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Avatars

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val avatars = Avatars(client)

val result = avatars.getFavicon(
    url = "https://example.com",
    width = 100,
    height = 100
)
```
```client-react-native
import { Client, Avatars } from 'react-native-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const avatars = new Avatars(client);

const result = avatars.getFavicon({
    url: 'https://example.com',
    width: 100,
    height: 100
});

console.log(result); // Resource URL
```
{% /multicode %}

# Parameters {% #parameters %}

The `getFavicon` method accepts the following parameters:

| Parameter | Type | Description |
| --------- | ---- | ----------- |
| url | string | The URL of the website to fetch the favicon from. Must be a valid HTTP or HTTPS URL. |
| width | integer | The width of the output image in pixels. Accepts values between `0-2000`. |
| height | integer | The height of the output image in pixels. Accepts values between `0-2000`. |

# Favicon retrieval {% #favicon-retrieval %}

The service automatically attempts to retrieve favicons from common locations on the target website, including standard favicon paths and HTML meta tags.

{% multicode %}
```client-web
// Standard website
const exampleFavicon = avatars.getFavicon({
    url: 'https://example.com',
    width: 64,
    height: 64
});

// Website with subdomain
const subdomainFavicon = avatars.getFavicon({
    url: 'https://blog.example.com',
    width: 64,
    height: 64
});

// Website with path
const pathFavicon = avatars.getFavicon({
    url: 'https://example.com/page',
    width: 64,
    height: 64
});
```
```client-flutter
// Standard website
Future exampleFavicon = avatars.getFavicon(
    url: 'https://example.com',
    width: 64,
    height: 64
);

// Website with subdomain
Future subdomainFavicon = avatars.getFavicon(
    url: 'https://blog.example.com',
    width: 64,
    height: 64
);

// Website with path
Future pathFavicon = avatars.getFavicon(
    url: 'https://example.com/page',
    width: 64,
    height: 64
);
```
```client-apple
// Standard website
let exampleFavicon = try await avatars.getFavicon(
    url: "https://example.com",
    width: 64,
    height: 64
)

// Website with subdomain
let subdomainFavicon = try await avatars.getFavicon(
    url: "https://blog.example.com",
    width: 64,
    height: 64
)

// Website with path
let pathFavicon = try await avatars.getFavicon(
    url: "https://example.com/page",
    width: 64,
    height: 64
)
```
```client-android-kotlin
// Standard website
val exampleFavicon = avatars.getFavicon(
    url = "https://example.com",
    width = 64,
    height = 64
)

// Website with subdomain
val subdomainFavicon = avatars.getFavicon(
    url = "https://blog.example.com",
    width = 64,
    height = 64
)

// Website with path
val pathFavicon = avatars.getFavicon(
    url = "https://example.com/page",
    width = 64,
    height = 64
)
```
{% /multicode %}

# Use cases {% #use-cases %}

Favicons are commonly used for:

- **Link previews**: Display website icons in link preview cards and social sharing
- **Bookmarks**: Show website icons in bookmark lists and collections
- **Referral tracking**: Display source website icons in analytics and referral reports
- **Content aggregation**: Show website icons in RSS feed readers and content aggregators
- **Social media**: Display website icons in social media link previews and embeds

