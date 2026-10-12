---
layout: article
title: Screenshots
description: Capture webpage screenshots with customizable viewport, theme, browser settings, and geolocation options for comprehensive web page documentation.
---

The screenshots endpoint allows you to capture full webpage screenshots with extensive customization options. You can control the browser viewport size, theme, user agent, geolocation, permissions, and other browser settings to capture web pages exactly as they would appear in different scenarios.

This is valuable for various [use cases](#use-cases), including generating visual documentation, creating link previews, automating QA testing across different devices and browsers, or archiving web pages for compliance and record-keeping. Instead of manually taking screenshots or setting up complex headless browser infrastructure, you can generate high-quality screenshots on-demand through a simple API call, ensuring consistency and saving development time.

# Get webpage screenshot {% #get-screenshot %}

Capture a screenshot of a remote webpage with customizable browser settings and viewport options.

{% multicode %}
```client-web
import { Client, Avatars } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const avatars = new Avatars(client);

const result = avatars.getScreenshot({
    url: 'https://example.com'
});

console.log(result); // Resource URL
```
```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final avatars = Avatars(client);

Future result = avatars.getScreenshot(
    url: 'https://example.com'
).then((bytes) {
    // Use the screenshot image bytes
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

let byteBuffer = try await avatars.getScreenshot(
    url: "https://example.com"
)
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Avatars

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val avatars = Avatars(client)

val result = avatars.getScreenshot(
    url = "https://example.com"
)
```
```client-react-native
import { Client, Avatars } from 'react-native-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const avatars = new Avatars(client);

const result = avatars.getScreenshot({
    url: 'https://example.com'
});

console.log(result); // Resource URL
```
{% /multicode %}

# Parameters {% #parameters %}

The `getScreenshot` method accepts the following parameters:

| Parameter | Type | Description |
| --------- | ---- | ----------- |
| url | string | The URL of the website to capture. Must be a valid HTTP or HTTPS URL. |
| headers | object | HTTP headers to send with the browser request. Pass a key-value object with custom headers. Defaults to `{}` if not provided. |
| viewportWidth | integer | The width of the browser viewport in pixels. Accepts values between `1-1920`. Defaults to `1280` if not provided. |
| viewportHeight | integer | The height of the browser viewport in pixels. Accepts values between `1-1080`. Defaults to `720` if not provided. |
| scale | float | The device pixel ratio for the screenshot. Accepts values between `0.1-3` for different DPI settings. Defaults to `1` if not provided. |
| theme | string | The browser color scheme theme. Accepts: `light` or `dark`. Defaults to `light` if not provided. |
| userAgent | string | A custom user agent string for the browser request. Defaults to browser default if not provided. |
| fullpage | boolean | Capture the full scrollable page (`true`) or only the viewport (`false`). Defaults to `false` if not provided. |
| locale | string | The browser locale code (e.g., `en-US`, `fr-FR`). Defaults to browser default if not provided. |
| timezone | string | IANA timezone identifier (e.g., `America/New_York`, `Europe/London`). Defaults to browser default if not provided. |
| latitude | float | Geolocation latitude for the screenshot. Accepts values between `-90` to `90`. Defaults to `0` if not provided. |
| longitude | float | Geolocation longitude for the screenshot. Accepts values between `-180` to `180`. Defaults to `0` if not provided. |
| accuracy | float | Geolocation accuracy in meters. Accepts values between `0-100000`. Defaults to `0` if not provided. |
| touch | boolean | Enable touch device support (`true`) or disable it (`false`). Defaults to `false` if not provided. |
| permissions | array | Array of browser permissions to grant. Accepts: `geolocation`, `camera`, `microphone`, `notifications`, `midi`, `push`, `clipboard-read`, `clipboard-write`, `payment-handler`, `usb`, `bluetooth`, `accelerometer`, `gyroscope`, `magnetometer`, `ambient-light-sensor`, `background-sync`, `persistent-storage`, `screen-wake-lock`, `web-share`, `xr-spatial-tracking`. Defaults to `[]` if not provided. |
| sleep | integer | Wait time in seconds before taking the screenshot. Accepts values between `0-10`. Defaults to `0` if not provided. |
| width | integer | The output image width in pixels. Pass `0` to use original width, or `1-2000` for custom width. Defaults to `0` (original width) if not provided. |
| height | integer | The output image height in pixels. Pass `0` to use original height, or `1-2000` for custom height. Defaults to `0` (original height) if not provided. |
| quality | integer | Screenshot quality. Accepts values between `0-100`. `-1` preserves original image quality. Defaults to `-1` if not provided. |
| output | string | Output image format. Supported formats: `jpg`, `jpeg`, `png`, `gif`, `webp`. Defaults to `png` if not provided. |

# Viewport customization {% #viewport-customization %}

Control the browser viewport to capture web pages as they would appear on different devices and screen sizes.

{% multicode %}
```client-web
// Desktop viewport
const desktopScreenshot = avatars.getScreenshot({
    url: 'https://example.com',
    viewportWidth: 1920,
    viewportHeight: 1080
});

// Tablet viewport
const tabletScreenshot = avatars.getScreenshot({
    url: 'https://example.com',
    viewportWidth: 768,
    viewportHeight: 1024
});

// Mobile viewport
const mobileScreenshot = avatars.getScreenshot({
    url: 'https://example.com',
    viewportWidth: 375,
    viewportHeight: 667
});

// Full page capture
const fullpageScreenshot = avatars.getScreenshot({
    url: 'https://example.com',
    fullpage: true
});
```
```client-flutter
// Desktop viewport
Future desktopScreenshot = avatars.getScreenshot(
    url: 'https://example.com',
    viewportWidth: 1920,
    viewportHeight: 1080
);

// Tablet viewport
Future tabletScreenshot = avatars.getScreenshot(
    url: 'https://example.com',
    viewportWidth: 768,
    viewportHeight: 1024
);

// Mobile viewport
Future mobileScreenshot = avatars.getScreenshot(
    url: 'https://example.com',
    viewportWidth: 375,
    viewportHeight: 667
);

// Full page capture
Future fullpageScreenshot = avatars.getScreenshot(
    url: 'https://example.com',
    fullpage: true
);
```
```client-apple
// Desktop viewport
let desktopScreenshot = try await avatars.getScreenshot(
    url: "https://example.com",
    viewportWidth: 1920,
    viewportHeight: 1080
)

// Tablet viewport
let tabletScreenshot = try await avatars.getScreenshot(
    url: "https://example.com",
    viewportWidth: 768,
    viewportHeight: 1024
)

// Mobile viewport
let mobileScreenshot = try await avatars.getScreenshot(
    url: "https://example.com",
    viewportWidth: 375,
    viewportHeight: 667
)

// Full page capture
let fullpageScreenshot = try await avatars.getScreenshot(
    url: "https://example.com",
    fullpage: true
)
```
```client-android-kotlin
// Desktop viewport
val desktopScreenshot = avatars.getScreenshot(
    url = "https://example.com",
    viewportWidth = 1920,
    viewportHeight = 1080
)

// Tablet viewport
val tabletScreenshot = avatars.getScreenshot(
    url = "https://example.com",
    viewportWidth = 768,
    viewportHeight = 1024
)

// Mobile viewport
val mobileScreenshot = avatars.getScreenshot(
    url = "https://example.com",
    viewportWidth = 375,
    viewportHeight = 667
)

// Full page capture
val fullpageScreenshot = avatars.getScreenshot(
    url = "https://example.com",
    fullpage = true
)
```
{% /multicode %}

# Browser customization {% #browser-customization %}

Customize browser settings like theme, user agent, locale, and timezone to simulate different browser environments.

{% multicode %}
```client-web
import { Client, Avatars, Theme, Timezone } from "appwrite";

// Dark theme screenshot
const darkThemeScreenshot = avatars.getScreenshot({
    url: 'https://example.com',
    theme: Theme.Dark
});

// Custom locale and timezone
const localizedScreenshot = avatars.getScreenshot({
    url: 'https://example.com',
    locale: 'fr-FR',
    timezone: Timezone.EuropeParis
});

// Custom user agent
const chromeScreenshot = avatars.getScreenshot({
    url: 'https://example.com',
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
});

// Touch device
const touchScreenshot = avatars.getScreenshot({
    url: 'https://example.com',
    touch: true
});
```
```client-flutter
import 'package:appwrite/appwrite.dart';

// Dark theme screenshot
Future darkThemeScreenshot = avatars.getScreenshot(
    url: 'https://example.com',
    theme: Theme.dark
);

// Custom locale and timezone
Future localizedScreenshot = avatars.getScreenshot(
    url: 'https://example.com',
    locale: 'fr-FR',
    timezone: Timezone.europeParis
);

// Custom user agent
Future chromeScreenshot = avatars.getScreenshot(
    url: 'https://example.com',
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15'
);

// Touch device
Future touchScreenshot = avatars.getScreenshot(
    url: 'https://example.com',
    touch: true
);
```
```client-apple
import Appwrite

// Dark theme screenshot
let darkThemeScreenshot = try await avatars.getScreenshot(
    url: "https://example.com",
    theme: Theme.dark
)

// Custom locale and timezone
let localizedScreenshot = try await avatars.getScreenshot(
    url: "https://example.com",
    locale: "fr-FR",
    timezone: Timezone.europeParis
)

// Custom user agent
let chromeScreenshot = try await avatars.getScreenshot(
    url: "https://example.com",
    userAgent: "Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15"
)

// Touch device
let touchScreenshot = try await avatars.getScreenshot(
    url: "https://example.com",
    touch: true
)
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Avatars
import io.appwrite.enums.Theme
import io.appwrite.enums.Timezone

// Dark theme screenshot
val darkThemeScreenshot = avatars.getScreenshot(
    url = "https://example.com",
    theme = Theme.DARK
)

// Custom locale and timezone
val localizedScreenshot = avatars.getScreenshot(
    url = "https://example.com",
    locale = "fr-FR",
    timezone = Timezone.EUROPE_PARIS
)

// Custom user agent
val chromeScreenshot = avatars.getScreenshot(
    url = "https://example.com",
    userAgent = "Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36"
)

// Touch device
val touchScreenshot = avatars.getScreenshot(
    url = "https://example.com",
    touch = true
)
```
{% /multicode %}

# Geolocation simulation {% #geolocation-simulation %}

Simulate different geographic locations to see how web pages render with location-based content and features.

{% multicode %}
```client-web
import { Client, Avatars, Timezone } from "appwrite";

// New York location
const nyScreenshot = avatars.getScreenshot({
    url: 'https://example.com',
    permissions: ["geolocation"],
    latitude: 40.7128,
    longitude: -74.0060,
    accuracy: 100,
    timezone: Timezone.AmericaNewYork
});

// London location
const londonScreenshot = avatars.getScreenshot({
    url: 'https://example.com',
    permissions: ["geolocation"],
    latitude: 51.5074,
    longitude: -0.1278,
    accuracy: 100,
    timezone: Timezone.EuropeLondon
});

// Tokyo location
const tokyoScreenshot = avatars.getScreenshot({
    url: 'https://example.com',
    permissions: ["geolocation"],
    latitude: 35.6762,
    longitude: 139.6503,
    accuracy: 100,
    timezone: Timezone.AsiaTokyo
});
```
```client-flutter
import 'package:appwrite/appwrite.dart';

// New York location
Future nyScreenshot = avatars.getScreenshot(
    url: 'https://example.com',
    permissions: ["geolocation"],
    latitude: 40.7128,
    longitude: -74.0060,
    accuracy: 100,
    timezone: Timezone.americaNewYork
);

// London location
Future londonScreenshot = avatars.getScreenshot(
    url: 'https://example.com',
    permissions: ["geolocation"],
    latitude: 51.5074,
    longitude: -0.1278,
    accuracy: 100,
    timezone: Timezone.europeLondon
);

// Tokyo location
Future tokyoScreenshot = avatars.getScreenshot(
    url: 'https://example.com',
    permissions: ["geolocation"],
    latitude: 35.6762,
    longitude: 139.6503,
    accuracy: 100,
    timezone: Timezone.asiaTokyo
);
```
```client-apple
import Appwrite

// New York location
let nyScreenshot = try await avatars.getScreenshot(
    url: "https://example.com",
    permissions: ["geolocation"],
    latitude: 40.7128,
    longitude: -74.0060,
    accuracy: 100,
    timezone: Timezone.americaNewYork
)

// London location
let londonScreenshot = try await avatars.getScreenshot(
    url: "https://example.com",
    permissions: ["geolocation"],
    latitude: 51.5074,
    longitude: -0.1278,
    accuracy: 100,
    timezone: Timezone.europeLondon
)

// Tokyo location
let tokyoScreenshot = try await avatars.getScreenshot(
    url: "https://example.com",
    permissions: ["geolocation"],
    latitude: 35.6762,
    longitude: 139.6503,
    accuracy: 100,
    timezone: Timezone.asiaTokyo
)
```
```client-android-kotlin
import io.appwrite.services.Avatars
import io.appwrite.enums.Timezone

// New York location
val nyScreenshot = avatars.getScreenshot(
    url = "https://example.com",
    permissions = listOf("geolocation"),
    latitude = 40.7128,
    longitude = -74.0060,
    accuracy = 100,
    timezone = Timezone.AMERICA_NEW_YORK
)

// London location
val londonScreenshot = avatars.getScreenshot(
    url = "https://example.com",
    permissions = listOf("geolocation"),
    latitude = 51.5074,
    longitude = -0.1278,
    accuracy = 100,
    timezone = Timezone.EUROPE_LONDON
)

// Tokyo location
val tokyoScreenshot = avatars.getScreenshot(
    url = "https://example.com",
    permissions = listOf("geolocation"),
    latitude = 35.6762,
    longitude = 139.6503,
    accuracy = 100,
    timezone = Timezone.ASIA_TOKYO
)
```
{% /multicode %}

# Wait time before capture {% #wait-time %}

Use the `sleep` parameter to wait for a specified duration before capturing the screenshot. This is useful when pages need time to fully load dynamic content, animations, or async resources.

{% multicode %}
```client-web
// Wait for page to load fully
const delayedScreenshot = avatars.getScreenshot({
    url: 'https://example.com',
    sleep: 5 // Wait 5 seconds before capture
});

// Immediate capture
const instantScreenshot = avatars.getScreenshot({
    url: 'https://example.com',
    sleep: 0 // No wait time
});
```
```client-flutter
// Wait for page to load fully
Future delayedScreenshot = avatars.getScreenshot(
    url: 'https://example.com',
    sleep: 5 // Wait 5 seconds before capture
);

// Immediate capture
Future instantScreenshot = avatars.getScreenshot(
    url: 'https://example.com',
    sleep: 0 // No wait time
);
```
```client-apple
// Wait for page to load fully
let delayedScreenshot = try await avatars.getScreenshot(
    url: "https://example.com",
    sleep: 5 // Wait 5 seconds before capture
)

// Immediate capture
let instantScreenshot = try await avatars.getScreenshot(
    url: "https://example.com",
    sleep: 0 // No wait time
)
```
```client-android-kotlin
// Wait for page to load fully
val delayedScreenshot = avatars.getScreenshot(
    url = "https://example.com",
    sleep = 5 // Wait 5 seconds before capture
)

// Immediate capture
val instantScreenshot = avatars.getScreenshot(
    url = "https://example.com",
    sleep = 0 // No wait time
)
```
{% /multicode %}

# Browser permissions {% #browser-permissions %}

Grant specific browser permissions to allow web pages to access features like geolocation, camera, microphone, and other APIs during screenshot capture. When you visit a web page that requires certain browser capabilities, it typically prompts users to grant permissions. With the `permissions` parameter, you can pre-grant these permissions to the browser session, allowing you to capture screenshots of pages that depend on these features without manual intervention.

## Available permissions

The following permissions can be granted to the browser session:

| Permission | Description |
| ---------- | ----------- |
| `geolocation` | Access device location coordinates |
| `camera` | Access camera for video/photo capture |
| `microphone` | Access microphone for audio recording |
| `notifications` | Send browser notifications |
| `midi` | Access MIDI devices for music applications |
| `push` | Receive push notifications |
| `clipboard-read` | Read from clipboard |
| `clipboard-write` | Write to clipboard |
| `payment-handler` | Handle payment requests |
| `usb` | Access USB devices |
| `bluetooth` | Access Bluetooth devices |
| `accelerometer` | Access device accelerometer sensor |
| `gyroscope` | Access device gyroscope sensor |
| `magnetometer` | Access device magnetometer sensor |
| `ambient-light-sensor` | Access ambient light sensor |
| `background-sync` | Sync data in the background |
| `persistent-storage` | Use persistent storage |
| `screen-wake-lock` | Prevent screen from sleeping |
| `web-share` | Use Web Share API |
| `xr-spatial-tracking` | Track spatial positioning for XR/VR |

By pre-granting permissions, you can capture screenshots of fully functional pages in their active state, rather than showing permission prompts or degraded experiences.

{% multicode %}
```client-web
import { Client, Avatars, Output } from "appwrite";

// Grant geolocation permission
const geoScreenshot = avatars.getScreenshot({
    url: 'https://example.com/map',
    permissions: ["geolocation"],
    latitude: 40.7128,
    longitude: -74.0060,
    output: Output.Png
});

// Grant multiple permissions
const multiPermScreenshot = avatars.getScreenshot({
    url: 'https://example.com/video-call',
    permissions: ["camera", "microphone", "notifications"],
    output: Output.Webp
});

// Grant storage and sync permissions
const storageScreenshot = avatars.getScreenshot({
    url: 'https://example.com/offline-app',
    permissions: ["persistent-storage", "background-sync"],
    output: Output.Jpg
});

// No special permissions
const defaultScreenshot = avatars.getScreenshot({
    url: 'https://example.com',
    permissions: []
});
```
```client-flutter
import 'package:appwrite/appwrite.dart';

// Grant geolocation permission
Future geoScreenshot = avatars.getScreenshot(
    url: 'https://example.com/map',
    permissions: ["geolocation"],
    latitude: 40.7128,
    longitude: -74.0060,
    output: Output.png
);

// Grant multiple permissions
Future multiPermScreenshot = avatars.getScreenshot(
    url: 'https://example.com/video-call',
    permissions: ["camera", "microphone", "notifications"],
    output: Output.webp
);

// Grant storage and sync permissions
Future storageScreenshot = avatars.getScreenshot(
    url: 'https://example.com/offline-app',
    permissions: ["persistent-storage", "background-sync"],
    output: Output.jpg
);

// No special permissions
Future defaultScreenshot = avatars.getScreenshot(
    url: 'https://example.com',
    permissions: []
);
```
```client-apple
import Appwrite

// Grant geolocation permission
let geoScreenshot = try await avatars.getScreenshot(
    url: "https://example.com/map",
    permissions: ["geolocation"],
    latitude: 40.7128,
    longitude: -74.0060,
    output: Output.png
)

// Grant multiple permissions
let multiPermScreenshot = try await avatars.getScreenshot(
    url: "https://example.com/video-call",
    permissions: ["camera", "microphone", "notifications"],
    output: Output.webp
)

// Grant storage and sync permissions
let storageScreenshot = try await avatars.getScreenshot(
    url: "https://example.com/offline-app",
    permissions: ["persistent-storage", "background-sync"],
    output: Output.jpg
)

// No special permissions
let defaultScreenshot = try await avatars.getScreenshot(
    url: "https://example.com",
    permissions: []
)
```
```client-android-kotlin
import io.appwrite.services.Avatars
import io.appwrite.enums.Output

// Grant geolocation permission
val geoScreenshot = avatars.getScreenshot(
    url = "https://example.com/map",
    permissions = listOf("geolocation"),
    latitude = 40.7128,
    longitude = -74.0060,
    output = Output.PNG
)

// Grant multiple permissions
val multiPermScreenshot = avatars.getScreenshot(
    url = "https://example.com/video-call",
    permissions = listOf("camera", "microphone", "notifications"),
    output = Output.WEBP
)

// Grant storage and sync permissions
val storageScreenshot = avatars.getScreenshot(
    url = "https://example.com/offline-app",
    permissions = listOf("persistent-storage", "background-sync"),
    output = Output.JPG
)

// No special permissions
val defaultScreenshot = avatars.getScreenshot(
    url = "https://example.com",
    permissions = emptyList()
)
```
{% /multicode %}

# Use cases {% #use-cases %}

Screenshots are commonly used for:

- **Web documentation**: Automatically generate visual documentation of web applications and websites
- **Link previews**: Display website previews in link cards and social sharing interfaces
- **Performance testing**: Capture pages in different browser environments to verify responsive design
- **Accessibility testing**: Generate screenshots with different viewport sizes and accessibility settings
- **Localization testing**: Verify how pages render in different locales and timezones
- **Browser compatibility**: Test how pages appear with different user agents and browser settings
- **Content archival**: Create visual snapshots of web pages for archival and reference
- **A/B testing**: Compare visual renderings of different page variants
- **Automated reporting**: Generate visual reports and dashboards with live web page screenshots
- **QA automation**: Verify visual consistency across different browser and device configurations
