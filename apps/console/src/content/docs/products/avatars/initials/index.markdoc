---
layout: article
title: User initials
description: Generate avatar images from user names or initials with customizable appearance and dimensions.
---

The user initials endpoint generates avatar images from names or initials. This is particularly useful for displaying user profiles when no profile picture is available, creating a consistent visual identity across your application.

# Generate initials {% #generate-initials %}

Generate an avatar image from a user's name. The service automatically extracts initials from the name and displays them on a colored background.

{% multicode %}
```client-web
import { Client, Avatars } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const avatars = new Avatars(client);

const result = avatars.getInitials({
    name: 'John Doe',
    width: 200,
    height: 200,
    background: '000000'
});

console.log(result); // Resource URL
```
```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final avatars = Avatars(client);

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
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let avatars = Avatars(client)

let byteBuffer = try await avatars.getInitials(
    name: "John Doe",
    width: 200,
    height: 200,
    background: "000000"
)
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Avatars

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val avatars = Avatars(client)

val result = avatars.getInitials(
    name = "John Doe",
    width = 200,
    height = 200,
    background = "000000"
)
```
```client-react-native
import { Client, Avatars } from 'react-native-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const avatars = new Avatars(client);

const result = avatars.getInitials({
    name: 'John Doe',
    width: 200,
    height: 200,
    background: '000000'
});

console.log(result); // Resource URL
```
{% /multicode %}

# Parameters {% #parameters %}

The `getInitials` method accepts the following parameters:

| Parameter | Type | Description |
| --------- | ---- | ----------- |
| name | string | The name to generate initials from. The service extracts the first letter of each word. |
| width | integer | The width of the output image in pixels. Accepts values between `0-2000`. |
| height | integer | The height of the output image in pixels. Accepts values between `0-2000`. |
| background | string | The background color in hexadecimal format without the leading `#`. Defaults to a random color if not provided. |

# Customization {% #customization %}

You can customize the appearance of the initials avatar by adjusting the dimensions and background color. The service automatically selects appropriate text color based on the background for optimal contrast.

{% multicode %}
```client-web
// Square avatar with custom background
const squareAvatar = avatars.getInitials({
    name: 'Jane Smith',
    width: 150,
    height: 150,
    background: 'FF5733'
});

// Rectangular avatar
const rectangularAvatar = avatars.getInitials({
    name: 'Bob Johnson',
    width: 200,
    height: 100,
    background: '3498DB'
});

// Random background color (omit background parameter)
const randomBackground = avatars.getInitials({
    name: 'Alice Williams',
    width: 180,
    height: 180
});
```
```client-flutter
// Square avatar with custom background
Future squareAvatar = avatars.getInitials(
    name: 'Jane Smith',
    width: 150,
    height: 150,
    background: 'FF5733'
);

// Rectangular avatar
Future rectangularAvatar = avatars.getInitials(
    name: 'Bob Johnson',
    width: 200,
    height: 100,
    background: '3498DB'
);

// Random background color (omit background parameter)
Future randomBackground = avatars.getInitials(
    name: 'Alice Williams',
    width: 180,
    height: 180
);
```
```client-apple
// Square avatar with custom background
let squareAvatar = try await avatars.getInitials(
    name: "Jane Smith",
    width: 150,
    height: 150,
    background: "FF5733"
)

// Rectangular avatar
let rectangularAvatar = try await avatars.getInitials(
    name: "Bob Johnson",
    width: 200,
    height: 100,
    background: "3498DB"
)

// Random background color (omit background parameter)
let randomBackground = try await avatars.getInitials(
    name: "Alice Williams",
    width: 180,
    height: 180
)
```
```client-android-kotlin
// Square avatar with custom background
val squareAvatar = avatars.getInitials(
    name = "Jane Smith",
    width = 150,
    height = 150,
    background = "FF5733"
)

// Rectangular avatar
val rectangularAvatar = avatars.getInitials(
    name = "Bob Johnson",
    width = 200,
    height = 100,
    background = "3498DB"
)

// Random background color (omit background parameter)
val randomBackground = avatars.getInitials(
    name = "Alice Williams",
    width = 180,
    height = 180
)
```
{% /multicode %}

# Use cases {% #use-cases %}

User initials avatars are commonly used in:

- **User profiles**: Display avatars in user lists, comments, and profile pages when no profile picture is available
- **Team members**: Show team member avatars in collaboration interfaces
- **Notifications**: Display sender avatars in notification systems
- **Activity feeds**: Show user avatars in activity and timeline views

