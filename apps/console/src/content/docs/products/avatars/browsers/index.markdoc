---
layout: article
title: Browser icons
description: Retrieve browser icons for displaying user agent information and device compatibility.
---

The browser icon endpoint provides access to icons for popular web browsers. This is useful for displaying user agent information, browser compatibility indicators, and device compatibility in your application.

# Get browser icon {% #get-browser %}

Retrieve a browser icon by browser code.

{% multicode %}
```client-web
import { Client, Avatars, Browser } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const avatars = new Avatars(client);

const result = avatars.getBrowser({
    code: Browser.GoogleChrome,
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

Future result = avatars.getBrowser(
    code: Browser.googleChrome,
    width: 100,
    height: 100
).then((bytes) {
    // Use the browser icon bytes
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

let byteBuffer = try await avatars.getBrowser(
    code: Browser.googleChrome,
    width: 100,
    height: 100
)
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Avatars
import io.appwrite.enums.Browser

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val avatars = Avatars(client)

val result = avatars.getBrowser(
    code = Browser.GOOGLE_CHROME,
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

const result = avatars.getBrowser({
    code: 'chrome',
    width: 100,
    height: 100
});

console.log(result); // Resource URL
```
{% /multicode %}

# Parameters {% #parameters %}

The `getBrowser` method accepts the following parameters:

| Parameter | Type | Description |
| --------- | ---- | ----------- |
| code | string | The browser code. Supported codes include `chrome`, `firefox`, `safari`, `edge`, `opera`, and others. |
| width | integer | The width of the output image in pixels. Accepts values between `0-2000`. |
| height | integer | The height of the output image in pixels. Accepts values between `0-2000`. |

# Supported browsers {% #supported-browsers %}

Common browser codes include:

{% multicode %}
```client-web
import { Browser } from "appwrite";

// Chrome
const chrome = avatars.getBrowser({
    code: Browser.GoogleChrome,
    width: 100,
    height: 100
});

// Firefox
const firefox = avatars.getBrowser({
    code: Browser.MozillaFirefox,
    width: 100,
    height: 100
});

// Safari
const safari = avatars.getBrowser({
    code: Browser.Safari,
    width: 100,
    height: 100
});

// Edge
const edge = avatars.getBrowser({
    code: Browser.MicrosoftEdge,
    width: 100,
    height: 100
});

// Opera
const opera = avatars.getBrowser({
    code: Browser.Opera,
    width: 100,
    height: 100
});
```
```client-flutter
import 'package:appwrite/appwrite.dart';

// Chrome
Future chrome = avatars.getBrowser(
    code: Browser.googleChrome,
    width: 100,
    height: 100
);

// Firefox
Future firefox = avatars.getBrowser(
    code: Browser.mozillaFirefox,
    width: 100,
    height: 100
);

// Safari
Future safari = avatars.getBrowser(
    code: Browser.safari,
    width: 100,
    height: 100
);

// Edge
Future edge = avatars.getBrowser(
    code: Browser.microsoftEdge,
    width: 100,
    height: 100
);

// Opera
Future opera = avatars.getBrowser(
    code: Browser.opera,
    width: 100,
    height: 100
);
```
```client-apple
import Appwrite

// Chrome
let chrome = try await avatars.getBrowser(
    code: Browser.googleChrome,
    width: 100,
    height: 100
)

// Firefox
let firefox = try await avatars.getBrowser(
    code: Browser.mozillaFirefox,
    width: 100,
    height: 100
)

// Safari
let safari = try await avatars.getBrowser(
    code: Browser.safari,
    width: 100,
    height: 100
)

// Edge
let edge = try await avatars.getBrowser(
    code: Browser.microsoftEdge,
    width: 100,
    height: 100
)

// Opera
let opera = try await avatars.getBrowser(
    code: Browser.opera,
    width: 100,
    height: 100
)
```
```client-android-kotlin
import io.appwrite.enums.Browser

// Chrome
val chrome = avatars.getBrowser(
    code = Browser.GOOGLE_CHROME,
    width = 100,
    height = 100
)

// Firefox
val firefox = avatars.getBrowser(
    code = Browser.MOZILLA_FIREFOX,
    width = 100,
    height = 100
)

// Safari
val safari = avatars.getBrowser(
    code = Browser.SAFARI,
    width = 100,
    height = 100
)

// Edge
val edge = avatars.getBrowser(
    code = Browser.MICROSOFT_EDGE,
    width = 100,
    height = 100
)

// Opera
val opera = avatars.getBrowser(
    code = Browser.OPERA,
    width = 100,
    height = 100
)
```
{% /multicode %}

# Use cases {% #use-cases %}

Browser icons are commonly used for:

- **Session information**: Display user session details visually alongside browser icons. Browser codes from session data match perfectly with the browser codes used in this endpoint, allowing you to create cohesive visual session displays
- **Analytics dashboards**: Display browser usage statistics and user agent information
- **Session management**: Show active sessions with browser information
- **Compatibility indicators**: Display supported browsers for features or content
- **Security logs**: Visualize login attempts and session information by browser
- **User activity**: Display browser information in activity feeds and audit logs

