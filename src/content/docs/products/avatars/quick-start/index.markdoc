---
layout: article
title: Start with Avatars
description: Get started quickly with Appwrite Avatars. Learn how to generate user initials, QR codes, and other avatar images in minutes.
difficulty: beginner
readtime: 5
---

You can start using Appwrite Avatars immediately. The service is publicly accessible and does not require authentication or API keys.

# Initialize the client {% #initialize-client %}

First, initialize the Appwrite client with your project endpoint and project ID.

{% multicode %}
```client-web
import { Client, Avatars } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const avatars = new Avatars(client);
```
```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final avatars = Avatars(client);
```
```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let avatars = Avatars(client)
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Avatars

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val avatars = Avatars(client)
```
```client-react-native
import { Client, Avatars } from 'react-native-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const avatars = new Avatars(client);
```
{% /multicode %}

# Generate user initials {% #generate-initials %}

Generate an avatar image from a user's name or initials. This is useful for displaying user profiles when no profile picture is available.

{% multicode %}
```client-web
const result = avatars.getInitials({
    name: 'John Doe',
    width: 200,
    height: 200,
    background: '000000'
});

console.log(result); // Resource URL
```
```client-flutter
Future result = avatars.getInitials(
    name: 'John Doe',
    width: 200,
    height: 200,
    background: '000000'
).then((bytes) {
    // Use the image bytes
    return bytes;
}).catchError((error) {
    print(error.response);
});
```
```client-apple
let byteBuffer = try await avatars.getInitials(
    name: "John Doe",
    width: 200,
    height: 200,
    background: "000000"
)
```
```client-android-kotlin
val result = avatars.getInitials(
    name = "John Doe",
    width = 200,
    height = 200,
    background = "000000"
)
```
```client-react-native
const result = avatars.getInitials({
    name: 'John Doe',
    width: 200,
    height: 200,
    background: '000000'
});

console.log(result); // Resource URL
```
{% /multicode %}

# Generate QR code {% #generate-qr %}

Create a QR code from any text string. This is commonly used for two-factor authentication, sharing links, or encoding data.

{% multicode %}
```client-web
const result = avatars.getQR({
    text: 'https://example.com',
    size: 300,
    margin: 1,
    download: false
});

console.log(result); // Resource URL
```
```client-flutter
Future result = avatars.getQR(
    text: 'https://example.com',
    size: 300,
    margin: 1,
    download: false
).then((bytes) {
    // Use the QR code image bytes
    return bytes;
}).catchError((error) {
    print(error.response);
});
```
```client-apple
let byteBuffer = try await avatars.getQR(
    text: "https://example.com",
    size: 300,
    margin: 1,
    download: false
)
```
```client-android-kotlin
val result = avatars.getQR(
    text = "https://example.com",
    size = 300,
    margin = 1,
    download = false
)
```
```client-react-native
const result = avatars.getQR({
    text: 'https://example.com',
    size: 300,
    margin: 1,
    download: false
});

console.log(result); // Resource URL
```
{% /multicode %}

# Get country flag {% #get-flag %}

Retrieve a country flag icon by country code. This is useful for displaying user locations or regional information.

{% multicode %}
```client-web
import { Flag } from "appwrite";

const result = avatars.getFlag({
    code: Flag.UnitedStates,
    width: 100,
    height: 100
});

console.log(result); // Resource URL
```
```client-flutter
Future result = avatars.getFlag(
    code: Flag.unitedStates,
    width: 100,
    height: 100
).then((bytes) {
    // Use the flag image bytes
    return bytes;
}).catchError((error) {
    print(error.response);
});
```
```client-apple
let byteBuffer = try await avatars.getFlag(
    code: Flag.unitedStates,
    width: 100,
    height: 100
)
```
```client-android-kotlin
import io.appwrite.enums.Flag

val result = avatars.getFlag(
    code = Flag.UNITED_STATES,
    width = 100,
    height = 100
)
```
```client-react-native
const result = avatars.getFlag({
    code: 'US',
    width: 100,
    height: 100
});

console.log(result); // Resource URL
```
{% /multicode %}

# Next steps {% #next-steps %}

Now that you've generated your first avatars, explore more advanced features:

- Learn about [user initials](/docs/products/avatars/initials) customization
- Discover [QR code](/docs/products/avatars/qr-codes) options
- Explore [image manipulation](/docs/products/avatars/image-manipulation) capabilities

