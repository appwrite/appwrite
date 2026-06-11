---
layout: article
title: Country flags
description: Retrieve country flag icons by country code for displaying user locations and regional information.
---

The country flag endpoint provides access to flag icons for all countries. This is useful for displaying user locations, regional settings, and country-specific information in your application.

# Get country flag {% #get-flag %}

Retrieve a country flag icon by its [ISO 3166-1](https://en.wikipedia.org/wiki/ISO_3166-1) country code.

{% multicode %}
```client-web
import { Client, Avatars, Flag } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const avatars = new Avatars(client);

const result = avatars.getFlag({
    code: Flag.UnitedStates,
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
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let avatars = Avatars(client)

let byteBuffer = try await avatars.getFlag(
    code: Flag.unitedStates,
    width: 100,
    height: 100
)
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Avatars
import io.appwrite.enums.Flag

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val avatars = Avatars(client)

val result = avatars.getFlag(
    code = Flag.UNITED_STATES,
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

const result = avatars.getFlag({
    code: 'US',
    width: 100,
    height: 100
});

console.log(result); // Resource URL
```
{% /multicode %}

# Parameters {% #parameters %}

The `getFlag` method accepts the following parameters:

| Parameter | Type | Description |
| --------- | ---- | ----------- |
| code | string | The ISO [ISO 3166-1](https://en.wikipedia.org/wiki/ISO_3166-1) country code (e.g., `US`, `GB`, `FR`). |
| width | integer | The width of the output image in pixels. Accepts values between `0-2000`. |
| height | integer | The height of the output image in pixels. Accepts values between `0-2000`. |

# Country codes {% #country-codes %}

Use ISO 3166-1 alpha-2 country codes to specify the country. These are two-letter codes that uniquely identify countries.

{% multicode %}
```client-web
import { Flag } from "appwrite";

// United States
const usFlag = avatars.getFlag({
    code: Flag.UnitedStates,
    width: 100,
    height: 100
});

// United Kingdom
const ukFlag = avatars.getFlag({
    code: Flag.UnitedKingdom,
    width: 100,
    height: 100
});

// France
const frFlag = avatars.getFlag({
    code: Flag.France,
    width: 100,
    height: 100
});
```
```client-flutter
import 'package:appwrite/appwrite.dart';

// United States
Future usFlag = avatars.getFlag(
    code: Flag.unitedStates,
    width: 100,
    height: 100
);

// United Kingdom
Future ukFlag = avatars.getFlag(
    code: Flag.unitedKingdom,
    width: 100,
    height: 100
);

// France
Future frFlag = avatars.getFlag(
    code: Flag.france,
    width: 100,
    height: 100
);
```
```client-apple
import Appwrite

// United States
let usFlag = try await avatars.getFlag(
    code: Flag.unitedStates,
    width: 100,
    height: 100
)

// United Kingdom
let ukFlag = try await avatars.getFlag(
    code: Flag.unitedKingdom,
    width: 100,
    height: 100
)

// France
let frFlag = try await avatars.getFlag(
    code: Flag.france,
    width: 100,
    height: 100
)
```
```client-android-kotlin
import io.appwrite.enums.Flag

// United States
val usFlag = avatars.getFlag(
    code = Flag.UNITED_STATES,
    width = 100,
    height = 100
)

// United Kingdom
val ukFlag = avatars.getFlag(
    code = Flag.UNITED_KINGDOM,
    width = 100,
    height = 100
)

// France
val frFlag = avatars.getFlag(
    code = Flag.FRANCE,
    width = 100,
    height = 100
)
```
{% /multicode %}

# Use cases {% #use-cases %}

Country flags are commonly used for:

- **Session information**: Display user session location visually alongside flag icons. Country codes from session data match perfectly with flags codes, allowing you to create cohesive visual session displays
- **User profiles**: Display user country or location in profile pages
- **Regional settings**: Show available regions or languages by country
- **Analytics dashboards**: Visualize geographic data and user distribution
- **Localization**: Indicate content availability or regional restrictions
- **Shipping information**: Display origin and destination countries in shipping interfaces

