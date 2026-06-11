---
layout: article
title: Multi-factor authentication
description: Add multiple layers of authentication to your applications powered by Appwrite Authentication.
---

Multi-factor authentication (MFA) greatly increases the security of your apps by adding additional layers of protection.
When MFA is enabled, a malicious actor needs to compromise multiple authentication factors to gain unauthorized access.
Appwrite Authentication lets you easily implement MFA in your apps, letting you build more securely and quickly.

{% info title="Looking for MFA on your Console account?" %}
This page covers MFA for your app's end-users.
If you are looking for MFA on your Appwrite Console account, please refer to the [Console MFA page](/docs/advanced/security/mfa).
{% /info %}

Appwrite currently allows two factors of authentication. More factors of authentication will be available soon.

Here are the steps to implement MFA in your application.

{% section #display-recover-code step=1 title="Display recovery codes" %}

Initialize your Appwrite SDK's `Client`, `Account`, and `Avatars`.
You'll use Avatars API to generate a QR code for the TOTP authenticator app, you can skip this import if you're not using TOTP.

{% multicode %}
```client-web
import { Client, Account, Avatars } from "appwrite";

const client = new Client();

const account = new Account(client);
const avatars = new Avatars(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>') // Your project ID
;
```

```client-flutter
import 'package:appwrite/appwrite.dart';

void main() { // Init SDK
  Client client = Client();
  Account account = Account(client);
  Avatars avatars = Avatars(client);

  client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>')             // Your project ID
  ;
}
```

```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>") // Your project ID

let account = Account(client)
let avatars = Avatars(client)
```

```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Account

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>") // Your project ID

val account = Account(client)
val avatars = Avatars(client)
```
{% /multicode %}

Before enabling MFA, you should display recovery codes to the user.
The codes are single use passwords the user can use to access their account if they lose access to their MFA email,
phone, or authenticator app. These codes can **only be generated once**, warn the users to save them.

The code will look like this, display them to the user and remind them to save the codes in a secure place.

```json
{
    "recoveryCodes": [
        "b654562828",
        "a97c13d8c0",
        "311580b5f3",
        "c4262b3f88",
        "7f6761afb4",
        "55a09989be",
    ]
}
```
These codes can be used to complete the [Complete challenge](#complete-challenge) step if the user loses access to their MFA factors.
Generate the recovery codes by calling `account.createMfaRecoveryCodes()`.

{% multicode %}
```client-web
const response = await account.createMfaRecoveryCodes();
console.log(response.recoveryCodes);
```

```client-flutter
Future result = account.createMfaRecoveryCodes();

result.then((response) {
    print(response.recoveryCodes);
}).catchError((error) {
    print(error.response);
});
```

```client-apple
let response = try await account.createMfaRecoveryCodes()
print(response.recoveryCodes)
```

```client-android-kotlin
val response = account.createMfaRecoveryCodes()
println(response.recoveryCodes)
```

```client-android-java
account.createMfaRecoveryCodes(new CoroutineCallback<>((result, error) -> {
   if (error != null) {
        error.printStackTrace();
        return;
    }

    Log.d("Appwrite", result.recoveryCodes.toString());
}));
```
{% /multicode %}
{% /section %}

{% section #verify-factors step=2 title="Verify MFA factors" %}
Any verified email, phone number, or TOTP authenticator app can be used as a factor for MFA.
Before they can be used as a factor, they need to be verified.

{% tabs %}
{% tabsitem #email title="Email" %}
First, set your user's email if they haven't already.
{% multicode %}
```client-web
const response = await account.updateEmail({
    email: 'email@example.com',
    password: 'password'
});
```
```client-flutter
Future result = account.updateEmail(
    email: 'email@example.com',
    password: 'password',
);

result.then((response) {
    print(response);
}).catchError((error) {
    print(error.response);
});
```
```client-apple
let response = try await account.updateEmail(
  email: "email@example.com",
  password: "password"
)
```
```client-android-kotlin
val response = account.updateEmail(
    email = "email@example.com",
    password = "password"
)
```
```client-android-java
account.updateEmail(
    "email@example.com", // email
    "password", // password
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);
```
{% /multicode %}

Then, initiate verification for the email by calling `account.createEmailVerification()`.
Calling `createEmailVerification` will send a verification email to the user's email address
with a link with the query parameter `userId` and `secret`.

{% multicode %}
```client-web
const res = await account.createVerification({
    url: 'https://example.com/verify-email'
});
```
```client-flutter
Future result = account.createVerification(
    url: 'https://example.com/verify-email',
);

result.then((response) {
    print(response);
}).catchError((error) {
    print(error.response);
});

```
```client-apple
let response = try await account.createVerification(
  url: "https://example.com/verify-email"
)
```
```client-android-kotlin
val response = account.createVerification(
    url = "https://example.com/verify-email"
)
```
```client-android-java
account.createVerification(
    "https://example.com/verify-email", // url
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);
```
{% /multicode %}

After the user clicks the link in the email, they will be redirected to your site with the query parameters `userId` and `secret`.
If you're on a mobile platform, you will need to create the appropriate deep link to handle the verification.

Finally, verify the email by calling `account.updateVerification()` with `userId` and `secret`.

{% multicode %}
```client-web
const response = await account.updateVerification({
    userId: '<USER_ID>',
    secret: '<SECRET>'
});
```
```client-flutter
Future result = account.updateVerification(
    userId: '<USER_ID>',
    secret: '<SECRET>',
);

result.then((response) {
    print(response);
}).catchError((error) {
    print(error.response);
});
```
```client-apple
let response = try await account.updateVerification(
  userId: "<USER_ID>",
  secret: "<SECRET>"
)
```
```client-android-kotlin
val response = account.updateVerification(
    userId = "<USER_ID>",
    secret = "<SECRET>"
)
```
```client-android-java
account.updateVerification(
    "<USER_ID>", // userId
    "<SECRET>", // secret
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);
```
{% /multicode %}

{% /tabsitem %}

{% tabsitem #phone title="Phone" %}
First, set your user's phone number if they haven't already.

{% multicode %}
```client-web
const response = await account.updatePhone({
    phone: '+12065550100',
    password: 'password'
});
```
```client-flutter
Future result = account.updatePhone(
    phone: '+12065550100',
    password: 'password',
);

result.then((response) {
    print(response);
}).catchError((error) {
    print(error.response);
});
```
```client-apple
let response = try await account.updatePhone(
  phone: "+12065550100",
  password: "password"
)
```
```client-android-kotlin
val response = account.updatePhone(
    phone = "+12065550100",
    password = "password"
)
```
```client-android-java
account.updatePhone(
    "+12065550100", // phone
    "password", // password
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);
```
{% /multicode %}

Then, initiate verification for the phone number by calling `account.createPhoneVerification()`.
{% multicode %}
```client-web
const response = await account.createPhoneVerification();
```
```client-flutter
Future result = account.createPhoneVerification();

result
    .then((response) {
        print(response);
    }).catchError((error) {
        print(error.response);
});
```
```client-apple
let response = try await account.createPhoneVerification()
```
```client-android-kotlin
val response = account.createPhoneVerification()
```
```client-android-java
account.createPhoneVerification(new CoroutineCallback<>((result, error) -> {
   if (error != null)
        error.printStackTrace();
        return;
    }

    Log.d("Appwrite", result.toString());
}));
```
{% /multicode %}

After the user receives the verification code, they can verify their phone number by calling `account.updatePhoneVerification()`.
{% multicode %}
```client-web
const response = await account.updatePhoneVerification({
    userId: '<USER_ID>',
    secret: '<SECRET>'
});
```
```client-flutter
Future result = account.updatePhoneVerification(
    userId: '<USER_ID>',
    secret: '<SECRET>',
);

result.then((response) {
        print(response);
    }).catchError((error) {
        print(error.response);
    });
```
```client-apple
let response = try await account.updatePhoneVerification(
  userId: "<USER_ID>",
  secret: "<SECRET>"
)
```
```client-android-kotlin
val response = account.updatePhoneVerification(
    userId = "<USER_ID>",
    secret = "<SECRET>"
)
```
```client-android-java
account.updatePhoneVerification(
    "<USER_ID>", // userId
    "<SECRET>", // secret
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);
```
{% /multicode %}

{% /tabsitem %}

{% tabsitem #authenticator title="Authenticator" %}
First, add a TOTP authenticator to the user's account by calling `account.addAuthenticator()`.

{% multicode %}
```client-web
const { secret, uri } = await account.createMfaAuthenticator({
    type: 'totp'
});
```
```client-flutter
Future result = account.createMfaAuthenticator(
    type: 'totp',
);

result.then((response) {
    print(response.secret);
    print(response.uri);
}).catchError((error) {
    print(error.response);
});
```
```client-apple
let response = try await account.createMfaAuthenticator(
  type: "totp"
)
print(response.secret)
print(response.uri)
```
```client-android-kotlin
val response = account.createMfaAuthenticator(
    type = "totp"
)
println(response.secret)
println(response.uri)
```
```client-android-java
account.createMfaAuthenticator(
    "totp", // type
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);
```
{% /multicode %}

This will create a secret and a URI.
The URI is a URL that can be used to generate a QR code for the user to scan with their TOTP authenticator app.

You can generate a QR code for the user to scan by calling `avatars.getQR()`.

{% multicode %}
```client-web
const result = await avatars.getQR({
    text: uri,
    size: 800,  // optional
    margin: 0,  // optional
    download: false // optional
});

console.log(result); // Resource URL
```
```client-flutter
// download QR code image
Future result = avatars.getQR(
    text: authenticatorUrl,
    size: 800, // optional
    margin: 0, // optional
    download: false, // optional
).then((bytes) {
    final file = File('path_to_file/filename.ext');
    file.writeAsBytesSync(bytes);
}).catchError((error) {
    print(error.response);
});

// display QR code image
FutureBuilder(
    future: avatars.getQR(
        text: authenticatorUrl,
        size: 800, // optional
        margin: 0, // optional
        download: false, // optional
    ), // works for both public file and private file, for private files you need to be logged in
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
let byteBuffer = try await avatars.getQR(
  text: authenticatorUrl,
  size: 800, // optional
  margin: 0, // optional
  download: xfalse // optional
)
```
```client-android-kotlin
val result = avatars.getQR(
    text = authenticatorUrl,
    size = 800, // optional
    margin = 0, // optional
    download = false // optional
)
```
```client-android-java
avatars.getQR(
    authenticatorUrl, // text
    800, // size (optional)
    0, // margin (optional)
    false, // download (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);
```
{% /multicode %}

If the user is unable to scan QR codes, you can display the `secret` to the user.

Finally prompt the user to enter a TOTP from their authenticator app, then
verify the authenticator by calling `account.verifyMfaAuthenticator()`.

{% multicode %}
```client-web
const promise = account.updateMfaAuthenticator({
    type: 'totp',
    otp: '<OTP>'
});
```
```client-flutter
  Future result = account.updateMfaAuthenticator(
    type: 'totp',
    otp: '<OTP>',
  );

  result.then((response) {
    print(response);
  }).catchError((error) {
    print(error.response);
  });
```
```client-apple
let response = try await account.updateMfaAuthenticator(
  type: "totp",
  otp: "<OTP>"
)
```
```client-android-kotlin
val response = account.updateMfaAuthenticator(
    type = "totp",
    otp = "<OTP>"
)
```
```client-android-java
account.updateMfaAuthenticator(
    "totp", // type
    "<OTP>", // otp
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);
```
{% /multicode %}

{% /tabsitem %}
{% /tabs %}

{% /section %}

{% section #enable-mfa step=3 title="Enable MFA on an account" %}
You can enable MFA on your account by calling `account.updateMFA()`.
You will need to have added more than 1 factors of authentication to an account before
the MFA is enforced.

{% multicode %}
```client-web
const result = await account.updateMFA({
    enabled: true
});
```

```client-flutter
Future result = account.updateMFA(
    mfa: true,
);

result.then((response) {
    print(response);
}).catchError((error) {
    print(error.response);
});
```

```client-apple
let response = try await account.updateMFA(
  mfa: xtrue
)
```

```client-android-kotlin
val response = account.updateMFA(
    mfa = true
)
```
{% /multicode %}
{% /section %}

{% section #init-login step=4 title="Initialize login" %}
Begin your login flow with the default authentication method used by your app, for example, email password.

{% multicode %}
```client-web
const session = await account.createEmailPasswordSession({
    email: 'email@example.com',
    password: 'password'
});
```

```client-flutter
Future session = account.createEmailPasswordSession(
email: 'email@example.com',
password: 'password',
);

result.then((response) {
    print(response);
}).catchError((error) {
    print(error.response);
});
```

```client-apple
let response = try await account.createEmailPasswordSession(
  email: "email@example.com",
  password: "password"
)
```

```client-android-kotlin
val session = account.createEmailPasswordSession(
    email = "email@example.com",
    password = "password"
)
```
{% /multicode %}
{% /section %}

{% section #check-for-mfa step=5 title="Check for multi-factor" %}
Upon successful login in the first authentication step, check the status of the login by calling `account.get()`.
If more than one factors are required, you will receive the error `user_more_factors_required`.
Redirect the user in your app to perform the MFA challenge.

{% multicode %}
```client-web
try {
    const response = await account.get();
    console.log(response);
} catch (error) {
    console.log(error);
    if (error.type === `user_more_factors_required`){
        // redirect to perform MFA
    }
    else {
        // handle other errors
    }
}
```

```client-flutter
const promise = account.get();

promise.then(function (response) {
    console.log(response);           // Success
}, function (error) {
    console.log(error);              // Failure
    if (error.type === "user_more_factors_required"){
        // redirect to perform MFA
    }
    else {
        // handle other errors
    }
});
```

```client-flutter
Future result = account.get();

result.then((response) {
    print(response);
}).catchError((error) {
    print(error.response);
    if (error.type == 'user_more_factors_required') {
    // redirect to perform MFA
    } else {
    // handle other errors
    }
});
```

```client-apple
do {
    let response = try await account.get()
} catch let error as AppwriteException {
    print(error.message)
    if error.type == "user_more_factors_required" {
        // redirect to perform MFA
    } else {
        // handle other errors
    }
}
```

```client-android-kotlin
try {
    val response = account.get()
    println(response)
} catch (error: AppwriteException) {
    println(error.message)
    if (error.type == "user_more_factors_required") {
        // redirect to perform MFA
    } else {
        // handle other errors
    }
}
```
{% /multicode %}
{% /section %}

{% section #list-provider step=6 title="List factors" %}
You can check which factors are enabled for an account using `account.listMfaFactors()`.
The returned object will be formatted like this.

```client-web
{
    totp: true, // time-based one-time password
    email: false, // email
    phone: true // phone
}
```

{% multicode %}
```client-web
const factors = await account.listMfaFactors();
// redirect based on factors returned.
```

```client-flutter
Future result = account.listMfaFactors();

result.then((response) {
    print(response);
    // redirect based on factors returned.
}).catchError((error) {
    print(error.response);
});
```

```client-apple
let response = try await account.listMfaFactors()
// redirect based on factors returned.
```

```client-android-kotlin
val response = account.listMfaFactors()
// redirect based on factors returned.
```
{% /multicode %}
{% /section %}

{% section #create-challenge step=7 title="Create challenge" %}
Based on the factors available, initialize an additional auth step.
Calling these methods will send a challenge to the user.
You will need to save the challenge ID to complete the challenge in a later step.

{% tabs %}
{% tabsitem #email title="Email" %}
Appwrite will use a verified email on the user's account to send the challenge code via email.
Note that this is only valid as a second factor if the user did not initialize their login with email OTP.

{% multicode %}
```client-web
const challenge = await account.createMfaChallenge({
    factor: 'email'
});

// Save the challenge ID to complete the challenge later
const challengeId = challenge.$id;
```

```client-flutter
Future result = account.createMfaChallenge(
factor: 'email',
);

result.then((response) {
    print(response);
    // Save the challenge ID to complete the challenge later
    var challengeId = response.$id;
}).catchError((error) {
    print(error.response);
});
```

```client-apple
let response = try await account.createMfaChallenge(
  factor: "email"
)
// Save the challenge ID to complete the challenge later
let challengeId = response.id
```

```client-android-kotlin
val response = account.createMfaChallenge(
    factor = "email"
)
// Save the challenge ID to complete the challenge later
val challengeId = response.id
```
{% /multicode %}
{% /tabsitem %}

{% tabsitem #phone title="Phone" %}
Appwrite will use a verified phone number on the user's account to send the challenge code via SMS.
You cannot use this method if the user initialized their login with phone OTP.

{% multicode %}
```client-web
const challenge = await account.createMfaChallenge({
    factor: 'phone'
});

// Save the challenge ID to complete the challenge later
const challengeId = challenge.$id;
```

```client-flutter
Future result = account.createMfaChallenge(
factor: 'phone',
);

result.then((response) {
    print(response);
    // Save the challenge ID to complete the challenge later
    var challengeId = response.$id;
}).catchError((error) {
    print(error.response);
});
```

```client-apple
let response = try await account.createMfaChallenge(
  factor: "phone"
)
// Save the challenge ID to complete the challenge later
let challengeId = response.id
```

```client-android-kotlin
val response = account.createMfaChallenge(
    factor = "phone"
)
// Save the challenge ID to complete the challenge later
val challengeId = response.id
```
{% /multicode %}
{% /tabsitem %}

{% tabsitem #totp title="TOTP" %}

Initiate a challenge for users to complete using an authenticator app.

{% multicode %}
```client-web
const challenge = await account.createMfaChallenge({
    factor: AuthenticationFactor.Totp
});

// Save the challenge ID to complete the challenge later
const challengeId = challenge.$id;
```

```client-flutter
Future result = account.createMfaChallenge(
factor: 'totp',
);

result.then((response) {
    print(response);
    // Save the challenge ID to complete the challenge later
    var challengeId = response.$id;
}).catchError((error) {
    print(error.response);
});
```

```client-apple
let response = try await account.createMfaChallenge(
  factor: "totp"
)
// Save the challenge ID to complete the challenge later
let challengeId = response.id
```

```client-android-kotlin
val response = account.createMfaChallenge(
    factor = "totp"
)
// Save the challenge ID to complete the challenge later
val challengeId = response.id
```
{% /multicode %}
{% /tabsitem %}
{% /tabs %}
{% /section %}

{% section #complete-challenge step=8 title="Complete challenge" %}
Once the user receives the challenge code, you can pass the code back to Appwrite to complete the challenge.

{% multicode %}
```client-web
const response = await account.updateMfaChallenge({
    challengeId: '<CHALLENGE_ID>',
    otp: '<OTP>'
});
```

```client-flutter
Future result = account.updateMfaChallenge(
    challengeId: '<CHALLENGE_ID>',
    otp: '<OTP>',
);

result.then((response) {
    print(response);
}).catchError((error) {
    print(error.response);
});
```

```client-apple
val response = account.updateMfaChallenge(
    challengeId = "<CHALLENGE_ID>",
    otp = "<OTP>"
)
```

```client-android-kotlin
let result = try await account.updateMfaChallenge(
  challengeId: "<CHALLENGE_ID>",
  otp: "<OTP>"
)
```
{% /multicode %}

After completing the challenge, the user is now authenticated and all requests will be authorized.
You can confirm this by running `account.get()`
{% /section %}

{% section #recovery step=9 title="Recovery" %}
In case your user needs to recover their account, they can use the recovery codes generated in the first step with the
recovery code factor. Initialize the challenge by calling `account.createMfaChallenge()` with the factor `recoverycode`.

{% multicode %}
```client-web
const challenge = await account.createMfaChallenge({
    factor: AuthenticationFactor.Recoverycode
});

// Save the challenge ID to complete the challenge later
const challengeId = challenge.$id;
```

```client-flutter
Future result = account.createMfaChallenge(
factor: 'recoverycode',
);

result.then((response) {
    print(response);
    // Save the challenge ID to complete the challenge later
    var challengeId = response.$id;
}).catchError((error) {
    print(error.response);
});
```

```client-apple
let response = try await account.createMfaChallenge(
  factor: "recoverycode"
)
// Save the challenge ID to complete the challenge later
let challengeId = response.id
```

```client-android-kotlin
val response = account.createMfaChallenge(
    factor = "recoverycode"
)
// Save the challenge ID to complete the challenge later
val challengeId = response.id
```
{% /multicode %}

Then complete the challenge by calling `account.updateMfaChallenge()` with the challenge ID and the recovery code.

{% multicode %}
```client-web
const response = await account.updateMfaChallenge({
    challengeId: '<CHALLENGE_ID>',
    otp: '<RECOVERY_CODE>'
});
```

```client-flutter
Future result = account.updateMfaChallenge(
    challengeId: '<CHALLENGE_ID>',
    otp: '<RECOVERY_CODE>',
);

result.then((response) {
    print(response);
}).catchError((error) {
    print(error.response);
});
```

```client-apple
val response = account.updateMfaChallenge(
    challengeId = "<CHALLENGE_ID>",
    otp = "<RECOVERY_CODE>"
)
```

```client-android-kotlin
let result = try await account.updateMfaChallenge(
  challengeId: "<CHALLENGE_ID>",
  otp: "<RECOVERY_CODE>"
)
```
{% /multicode %}
{% /section %}
