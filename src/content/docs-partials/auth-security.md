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

You can change the session limit in the **Security** tab of the Auth Service in your Appwrite Console. The default session limit is 10 with a maximum configurable limit of 100.

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

Passwords that don't meet the configured requirements are rejected when a user signs up and whenever they change their password. To configure password strength, navigate to **Auth** > **Security** > **Password strength**, set the minimum length and character requirements, then click **Update**.

# Password history {% #password-history %}

Password history prevents users from reusing recent passwords. This protects user accounts from security risks by enforcing a new password every time it's changed.

Password history can be enabled in the Auth service's **Security** tab on the Appwrite Console. You can choose how many previous passwords to remember, up to a maximum of 20, and block users from reusing them.

# Password dictionary {% #password-dictionary %}

Password dictionary protects users from using bad passwords. It compares the user's password to the [10,000 most common passwords](https://github.com/danielmiessler/SecLists/blob/master/Passwords/Common-Credentials/10k-most-common.txt) and throws an error if there's a match. Together with [rate limits](/docs/advanced/security/rate-limits), password dictionary will significantly reduce the chance of a malicious actor guessing user passwords.

Password dictionary can be enabled in the Auth service's **Security** tab on the Appwrite Console.

# Password hashing {% #password-hashing %}

Appwrite protects passwords by using the [Argon2](https://github.com/P-H-C/phc-winner-argon2) password-hashing algorithm.

Argon 2 is a resilient and secure password hashing algorithm that is also the winner of the [Password Hashing Competition](https://www.password-hashing.net/).

Appwrite combines Argon 2 with the use of techniques such as salting, adjustable work factors, and memory hardness to securely handle passwords.

If an user is imported into Appwrite with hash differnt than Argon2, the password will be re-hashed on first successful user's sign in. This ensures all passwords are stored as securely as possible.

# Personal data {% #personal-data %}

Encourage passwords that are hard to guess by disallowing users to pick passwords that contain personal data.
Personal data includes the user's name, email, and phone number.

Disallowing personal data can be enabled in the Auth service's **Security** tab on the Appwrite Console.

# Email policies {% #email-policies %}

Email policies let you restrict which email addresses can sign up for your project. You can independently block free email providers, aliased addresses, and disposable email services to keep throwaway accounts, signup spam, and bot registrations out of your user base. Policies run at sign-up and on email updates, and existing users can still sign in even if their address would not pass the current policy.

Email policies can be enabled in the Auth service's **Security** tab on the Appwrite Console, or programmatically through the Project service. Learn more in the [Email policies](/docs/products/auth/email-policies) docs.

# Session alerts {% #session-alerts %}

Enable email alerts for your users so that whenever a new session is created for their account, they will be alerted with details about the sign-in. This helps users quickly spot unauthorized access and take action to secure their account.

## When alerts are not sent

Session alerts are intentionally skipped in a few situations to avoid redundant or confusing emails:

- **First session after sign-up**: the very first sign-in a user makes after creating their account does not trigger an alert. A brand-new account doesn't yet hold anything worthy of protection, so alerting at this stage adds no real security value. It also prevents a double-email situation in flows where your project may already be sending a welcome or verification email.
- **[Magic URL](/docs/products/auth/magic-url), [Email OTP](/docs/products/auth/email-otp), and [OAuth2](/docs/products/auth/oauth2) sign-ins**: these authentication methods already verify the user's access to the sign-in channel (their inbox or identity provider), so no additional alert is needed.
- **No email address on file**: users who have not set an email address on their account will not receive alerts.

To toggle session alerts, navigate to **Auth** > **Security** > **Session alerts**.

# Memberships privacy {% #memberships-privacy %}

In certain use cases, your app may not need to share members' personal information with others. You can safeguard privacy by marking specific membership details as private. To configure this setting, navigate to **Auth** > **Security** > **Memberships privacy**

These details can be made private:

- `userName` - The member's name
- `userEmail` - The member's email address
- `mfa` - Whether the member has enabled multi-factor authentication

# Mock phone numbers {% #mock-phone-numbers %}

Creating and using mock phone numbers allows users to test SMS authentication without needing an actual phone number. This can be useful for testing edge cases where a user doesn't have a phone number but needs to sign in to your application using SMS.

To create a mock phone number, navigate to **Auth** > **Security** > Mock Phone Numbers. After defining a mock phone number, you need to define a specific OTP code that will be used for SMS sign-in instead of the SMS secret code sent to a real phone number.
