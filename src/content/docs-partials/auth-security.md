# Persistence {% #persistence %}

Appwrite handles the persistence of the session in a consistent way across SDKs. After authenticating with an SDK, the SDK will persist the session so that the user will not need to log in again the next time they open the app. The mechanism for persistence depends on the SDK.

{% info title="Best Practice" %}
Only keep user sessions active as long as needed and maintain exactly **one** instance of the Client SDK in your app to avoid conflicting session data.
{% /info %}

|                                                                                                                      {% width=70 %}                                                                                                                       | Framework {% width=120 %} |                                            Storage method                                            |
| :-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------: | :-----------------------: | :--------------------------------------------------------------------------------------------------: |
| {% only_dark %}{% icon_image src="/images/platforms/dark/javascript.svg" alt="Javascript logo" size="m" /%}{% /only_dark %}{% only_light %}{% icon_image src="/images/platforms/light/javascript.svg" alt="Javascript logo" size="m" /%}{% /only_light %} |            Web            | Uses a secure session cookie and falls back to local storage when a session cookie is not available. |
|    {% only_dark %}{% icon_image src="/images/platforms/dark/flutter.svg" alt="Javascript logo" size="m" /%}{% /only_dark %}{% only_light %}{% icon_image src="/images/platforms/light/flutter.svg" alt="Javascript logo" size="m" /%}{% /only_light %}    |          Flutter          |     Uses a session cookie stored in Application Documents through the **path_provider** package.     |
|      {% only_dark %}{% icon_image src="/images/platforms/dark/apple.svg" alt="Javascript logo" size="m" /%}{% /only_dark %}{% only_light %}{% icon_image src="/images/platforms/light/apple.svg" alt="Javascript logo" size="m" /%}{% /only_light %}      |           Apple           |                          Uses a session cookie stored in **UserDefaults**.                           |
|    {% only_dark %}{% icon_image src="/images/platforms/dark/android.svg" alt="Javascript logo" size="m" /%}{% /only_dark %}{% only_light %}{% icon_image src="/images/platforms/light/android.svg" alt="Javascript logo" size="m" /%}{% /only_light %}    |          Android          |                        Uses a session cookie stored in **SharedPreferences**.                        |

# Session limits {% #session-limits %}

In Appwrite versions 1.2 and above, you can limit the number of active sessions created per user to prevent the accumulation of unused but active sessions. New sessions created by the same user past the session limit delete the oldest session.

You can change the session limit under **Auth** > **Policies** > **Sessions** > **Sessions limit** in the Appwrite Console. The default session limit is 10 with a maximum configurable limit of 100.

# Permissions {% #permissions %}

Security is very important to protect users' data and privacy.
Appwrite uses a [permissions model](/docs/advanced/security/permissions) coupled with user sessions to ensure users need correct permissions to access resources.
With all Appwrite services, including databases and storage, access is granted at the table, bucket, row, or file level.
These permissions are enforced for client SDKs and server SDKs when using JWT, but are ignored when using a server SDK with an API key.

# Password strength {% #password-strength %}

Password strength lets you set the minimum requirements a password must meet when a user creates an account or changes their password. Enforcing these rules makes passwords harder to guess and brute-force.

You can configure two kinds of requirements:

- **Minimum length**: the smallest number of characters a password is allowed to have.
- **Character requirements**: require any combination of an uppercase letter, a lowercase letter, a number, and a special character. Each requirement is an independent toggle, so you can enforce as few or as many as your app needs.

Passwords that don't meet the configured requirements are rejected when a user signs up and whenever they change their password. To configure password strength, navigate to **Auth** > **Policies** > **Passwords** > **Strength**, set the minimum length and character requirements, then click **Update**.

# Password history {% #password-history %}

Password history prevents users from reusing recent passwords. This protects user accounts from security risks by enforcing a new password every time it's changed.

Password history can be enabled under **Auth** > **Policies** > **Passwords** > **History** in the Appwrite Console. You can choose how many previous passwords to remember, up to a maximum of 20, and block users from reusing them.

# Password dictionary {% #password-dictionary %}

Password dictionary protects users from using bad passwords. It compares the user's password to the [10,000 most common passwords](https://github.com/danielmiessler/SecLists/blob/master/Passwords/Common-Credentials/10k-most-common.txt) and throws an error if there's a match. Together with [rate limits](/docs/advanced/security/rate-limits), password dictionary will significantly reduce the chance of a malicious actor guessing user passwords.

Password dictionary can be enabled under **Auth** > **Policies** > **Passwords** > **Dictionary** in the Appwrite Console.

# Breached passwords {% #breached-passwords %}

Breached password detection checks user passwords against [Have I Been Pwned](https://haveibeenpwned.com/Passwords), a public corpus of passwords exposed in known data breaches. A leaked password is a target for credential stuffing even when it passes every strength and dictionary rule, so checking it against real breach data catches passwords that other checks miss.

The check uses k-anonymity. Appwrite hashes the password with SHA-1 and sends only the first five characters of the hash to the service. The service returns every known hash suffix for that prefix, and Appwrite compares them on its own servers, so neither the password nor its full hash is shared.

## How the check works {% #breached-passwords-how-it-works %}

Breached password detection is turned on by default for every project and only records a result. Whenever a user signs up, signs in with email and password, changes their password, or completes a password recovery, Appwrite checks the password and stores the outcome on the user as `passwordPwned`:

| Value   | Meaning                                                                                                          |
| ------- | ---------------------------------------------------------------------------------------------------------------- |
| `true`  | The password was found in a known data breach the last time it was checked.                                      |
| `false` | The password was not found in any known data breach.                                                             |
| `null`  | The password has never been checked, for example because the user signs in with OAuth or the check is turned off. |

Sign-ins are checked too, so the flag stays current. If a user's password shows up in a new breach, it is flagged the next time they sign in with it.

On top of recording, you can turn on two enforcement options:

- **Reject breached passwords.** A breached password can't be set when a user signs up, changes their password, or completes a password recovery. Users created or updated through the server-side Users API are checked too. The request fails with the `password_pwned` error.
- **Block sign-in with a breached password.** An email and password sign-in with a breached password is refused with the `user_password_reset_required` error until the user resets their password through [password recovery](/docs/products/auth/email-password#password-recovery).

If the breach service can't be reached, Appwrite does not treat the password as safe. The request fails with the `general_pwned_passwords_unavailable` error and can be retried.

## Configure breached password detection {% #configure-breached-passwords %}

1. Open your project in the Appwrite Console.
2. Navigate to **Auth** in the sidebar.
3. Open the **Policies** tab and select **Passwords**.
4. In the **Breached passwords** card, turn on **Check passwords against known data breaches**.
5. Optionally, check **Reject breached passwords** and **Block sign-in with a breached password**.
6. Click **Update**.

{% only_dark %}
![Breached passwords card in the Appwrite Console](/images/docs/auth/breached-passwords/dark/breached-passwords.avif)
{% /only_dark %}
{% only_light %}
![Breached passwords card in the Appwrite Console](/images/docs/auth/breached-passwords/breached-passwords.avif)
{% /only_light %}

The enforcement options only apply while the check is turned on. The users table shows the latest result for each user as a shield icon. A red shield means the password was found in a breach, a green one means it wasn't, and a dash means it has never been checked. A user's page shows a **breached password** badge when their password has leaked.

{% only_dark %}
![Users table in the Appwrite Console with breached password results](/images/docs/auth/breached-passwords/dark/users-table.avif)
{% /only_dark %}
{% only_light %}
![Users table in the Appwrite Console with breached password results](/images/docs/auth/breached-passwords/users-table.avif)
{% /only_light %}

{% info title="Self-hosted instances" %}
On self-hosted Appwrite 2.3 and later, the [`_APP_PWNED_PASSWORDS_DSN`](/docs/advanced/self-hosting/configuration/environment-variables#general) environment variable chooses the breach service. The default, `none://localhost`, reports every password as safe, so set it to `hibp://localhost` to check passwords against Have I Been Pwned.
{% /info %}

## Handle breached password errors {% #handle-breached-password-errors %}

When enforcement is on, catch the two error types in your sign-up and sign-in flows. On `password_pwned`, ask the user to choose a different password. On `user_password_reset_required`, send the user a password recovery email so they can set a new password.

{% multicode %}
```client-web
import { Client, Account, ID } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const account = new Account(client);

// Sign-up rejected by "Reject breached passwords"
try {
    await account.create({
        userId: ID.unique(),
        email: 'email@example.com',
        password: '<PASSWORD>'
    });
} catch (error) {
    if (error.type === 'password_pwned') {
        // Ask the user to choose a different password
    }
}

// Sign-in refused by "Block sign-in with a breached password"
try {
    await account.createEmailPasswordSession({
        email: 'email@example.com',
        password: '<PASSWORD>'
    });
} catch (error) {
    if (error.type === 'user_password_reset_required') {
        await account.createRecovery({
            email: 'email@example.com',
            url: 'https://example.com/recovery'
        });
    }
}
```

```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final account = Account(client);

// Sign-up rejected by "Reject breached passwords"
try {
  await account.create(
    userId: ID.unique(),
    email: 'email@example.com',
    password: '<PASSWORD>',
  );
} on AppwriteException catch (error) {
  if (error.type == 'password_pwned') {
    // Ask the user to choose a different password
  }
}

// Sign-in refused by "Block sign-in with a breached password"
try {
  await account.createEmailPasswordSession(
    email: 'email@example.com',
    password: '<PASSWORD>',
  );
} on AppwriteException catch (error) {
  if (error.type == 'user_password_reset_required') {
    await account.createRecovery(
      email: 'email@example.com',
      url: 'https://example.com/recovery',
    );
  }
}
```

```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let account = Account(client)

// Sign-up rejected by "Reject breached passwords"
do {
    _ = try await account.create(
        userId: ID.unique(),
        email: "email@example.com",
        password: "<PASSWORD>"
    )
} catch let error as AppwriteException {
    if error.type == "password_pwned" {
        // Ask the user to choose a different password
    }
}

// Sign-in refused by "Block sign-in with a breached password"
do {
    _ = try await account.createEmailPasswordSession(
        email: "email@example.com",
        password: "<PASSWORD>"
    )
} catch let error as AppwriteException {
    if error.type == "user_password_reset_required" {
        _ = try await account.createRecovery(
            email: "email@example.com",
            url: "https://example.com/recovery"
        )
    }
}
```

```client-android-kotlin
import io.appwrite.Client
import io.appwrite.ID
import io.appwrite.exceptions.AppwriteException
import io.appwrite.services.Account

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val account = Account(client)

// Sign-up rejected by "Reject breached passwords"
try {
    account.create(
        userId = ID.unique(),
        email = "email@example.com",
        password = "<PASSWORD>",
    )
} catch (error: AppwriteException) {
    if (error.type == "password_pwned") {
        // Ask the user to choose a different password
    }
}

// Sign-in refused by "Block sign-in with a breached password"
try {
    account.createEmailPasswordSession(
        email = "email@example.com",
        password = "<PASSWORD>",
    )
} catch (error: AppwriteException) {
    if (error.type == "user_password_reset_required") {
        account.createRecovery(
            email = "email@example.com",
            url = "https://example.com/recovery",
        )
    }
}
```
{% /multicode %}

## Find users with breached passwords {% #find-users-with-breached-passwords %}

The `passwordPwned` attribute can be queried through the server-side Users API. List the users whose password was found in a breach to notify them or ask them to change it, even before you turn on enforcement.

{% multicode %}
```server-nodejs
import { Client, Users, Query } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const users = new Users(client);

const result = await users.list({
    queries: [Query.equal('passwordPwned', true)]
});
```

```server-python
from appwrite.client import Client
from appwrite.query import Query
from appwrite.services.users import Users

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')

users = Users(client)

result = users.list(
    queries = [Query.equal('passwordPwned', True)]
)
```

```server-php
<?php

use Appwrite\Client;
use Appwrite\Query;
use Appwrite\Services\Users;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    ->setProject('<PROJECT_ID>')
    ->setKey('<YOUR_API_KEY>');

$users = new Users($client);

$result = $users->list(
    queries: [Query::equal('passwordPwned', true)]
);
```

```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

Users users = Users(client);

UserList result = await users.list(
    queries: [Query.equal('passwordPwned', true)],
);
```
{% /multicode %}

# Password hashing {% #password-hashing %}

Appwrite protects passwords by using the [Argon2](https://github.com/P-H-C/phc-winner-argon2) password-hashing algorithm.

Argon 2 is a resilient and secure password hashing algorithm that is also the winner of the [Password Hashing Competition](https://www.password-hashing.net/).

Appwrite combines Argon 2 with the use of techniques such as salting, adjustable work factors, and memory hardness to securely handle passwords.

If an user is imported into Appwrite with hash differnt than Argon2, the password will be re-hashed on first successful user's sign in. This ensures all passwords are stored as securely as possible.

# Personal data {% #personal-data %}

Encourage passwords that are hard to guess by disallowing users to pick passwords that contain personal data.
Personal data includes the user's name, email, and phone number.

Disallowing personal data can be enabled under **Auth** > **Policies** > **Passwords** > **Personal data** in the Appwrite Console.

# Email policies {% #email-policies %}

Email policies let you restrict which email addresses can sign up for your project. You can independently block free email providers, aliased addresses, and disposable email services to keep throwaway accounts, signup spam, and bot registrations out of your user base. Policies run at sign-up and on email updates, and existing users can still sign in even if their address would not pass the current policy.

Email policies can be enabled under **Auth** > **Policies** > **Emails** in the Appwrite Console, or programmatically through the Project service. Learn more in the [Email policies](/docs/products/auth/email-policies) docs.

# Session alerts {% #session-alerts %}

Enable email alerts for your users so that whenever a new session is created for their account, they will be alerted with details about the sign-in. This helps users quickly spot unauthorized access and take action to secure their account.

## When alerts are not sent

Session alerts are intentionally skipped in a few situations to avoid redundant or confusing emails:

- **First session after sign-up**: the very first sign-in a user makes after creating their account does not trigger an alert. A brand-new account doesn't yet hold anything worthy of protection, so alerting at this stage adds no real security value. It also prevents a double-email situation in flows where your project may already be sending a welcome or verification email.
- **[Magic URL](/docs/products/auth/magic-url), [Email OTP](/docs/products/auth/email-otp), and [OAuth2](/docs/products/auth/oauth2) sign-ins**: these authentication methods already verify the user's access to the sign-in channel (their inbox or identity provider), so no additional alert is needed.
- **No email address on file**: users who have not set an email address on their account will not receive alerts.

To toggle session alerts, navigate to **Auth** > **Policies** > **Sessions** > **Session alerts**.

# Memberships privacy {% #memberships-privacy %}

In certain use cases, your app may not need to share members' personal information with others. You can safeguard privacy by marking specific membership details as private. To configure this setting, navigate to **Auth** > **Policies** > **Memberships** > **Privacy**.

These details can be made private:

- `userName` - The member's name
- `userEmail` - The member's email address
- `mfa` - Whether the member has enabled multi-factor authentication

# Mock phone numbers {% #mock-phone-numbers %}

Creating and using mock phone numbers allows users to test SMS authentication without needing an actual phone number. This can be useful for testing edge cases where a user doesn't have a phone number but needs to sign in to your application using SMS.

To create a mock phone number, navigate to **Auth** > **Settings** > **Mock phone numbers**. After defining a mock phone number, you need to define a specific OTP code that will be used for SMS sign-in instead of the SMS secret code sent to a real phone number.
