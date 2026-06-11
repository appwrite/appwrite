---
layout: article
title: Payment methods
description: Retrieve payment method logos for checkout flows and transaction displays.
---

The payment method endpoint provides access to logos for popular payment methods and credit card brands. This is useful for displaying accepted payment methods in checkout flows, transaction history, and payment settings.

# Get payment method logo {% #get-credit-card %}

Retrieve a payment method or credit card logo by code.

{% multicode %}
```client-web
import { Client, Avatars, CreditCard } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const avatars = new Avatars(client);

const result = avatars.getCreditCard({
    code: CreditCard.Visa,
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

Future result = avatars.getCreditCard(
    code: CreditCard.visa,
    width: 100,
    height: 100
).then((bytes) {
    // Use the payment method logo bytes
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

let byteBuffer = try await avatars.getCreditCard(
    code: CreditCard.visa,
    width: 100,
    height: 100
)
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Avatars
import io.appwrite.enums.CreditCard

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val avatars = Avatars(client)

val result = avatars.getCreditCard(
    code = CreditCard.VISA,
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

const result = avatars.getCreditCard({
    code: 'visa',
    width: 100,
    height: 100
});

console.log(result); // Resource URL
```
{% /multicode %}

# Parameters {% #parameters %}

The `getCreditCard` method accepts the following parameters:

| Parameter | Type | Description |
| --------- | ---- | ----------- |
| code | string | The payment method or credit card code. Supported codes include `visa`, `mastercard`, `amex`, `discover`, `paypal`, and others. |
| width | integer | The width of the output image in pixels. Accepts values between `0-2000`. |
| height | integer | The height of the output image in pixels. Accepts values between `0-2000`. |

# Supported payment methods {% #supported-payment-methods %}

Common payment method codes include:

{% multicode %}
```client-web
import { CreditCard } from "appwrite";

// Visa
const visa = avatars.getCreditCard({
    code: CreditCard.Visa,
    width: 100,
    height: 100
});

// Mastercard
const mastercard = avatars.getCreditCard({
    code: CreditCard.Mastercard,
    width: 100,
    height: 100
});

// American Express
const amex = avatars.getCreditCard({
    code: CreditCard.AmericanExpress,
    width: 100,
    height: 100
});

// Discover
const discover = avatars.getCreditCard({
    code: CreditCard.Discover,
    width: 100,
    height: 100
});
```
```client-flutter
import 'package:appwrite/appwrite.dart';

// Visa
Future visa = avatars.getCreditCard(
    code: CreditCard.visa,
    width: 100,
    height: 100
);

// Mastercard
Future mastercard = avatars.getCreditCard(
    code: CreditCard.mastercard,
    width: 100,
    height: 100
);

// American Express
Future amex = avatars.getCreditCard(
    code: CreditCard.americanExpress,
    width: 100,
    height: 100
);

// Discover
Future discover = avatars.getCreditCard(
    code: CreditCard.discover,
    width: 100,
    height: 100
);
```
```client-apple
import Appwrite

// Visa
let visa = try await avatars.getCreditCard(
    code: CreditCard.visa,
    width: 100,
    height: 100
)

// Mastercard
let mastercard = try await avatars.getCreditCard(
    code: CreditCard.mastercard,
    width: 100,
    height: 100
)

// American Express
let amex = try await avatars.getCreditCard(
    code: CreditCard.americanExpress,
    width: 100,
    height: 100
)

// Discover
let discover = try await avatars.getCreditCard(
    code: CreditCard.discover,
    width: 100,
    height: 100
)
```
```client-android-kotlin
import io.appwrite.enums.CreditCard

// Visa
val visa = avatars.getCreditCard(
    code = CreditCard.VISA,
    width = 100,
    height = 100
)

// Mastercard
val mastercard = avatars.getCreditCard(
    code = CreditCard.MASTERCARD,
    width = 100,
    height = 100
)

// American Express
val amex = avatars.getCreditCard(
    code = CreditCard.AMERICAN_EXPRESS,
    width = 100,
    height = 100
)

// Discover
val discover = avatars.getCreditCard(
    code = CreditCard.DISCOVER,
    width = 100,
    height = 100
)
```
{% /multicode %}

# Use cases {% #use-cases %}

Payment method logos are commonly used for:

- **Checkout flows**: Display accepted payment methods during checkout
- **Payment settings**: Show saved payment methods in user account settings
- **Transaction history**: Display payment method used for each transaction
- **Subscription management**: Show payment methods associated with subscriptions
- **Billing information**: Display payment method logos in invoices and receipts

