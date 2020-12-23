/// Swift Appwrite SDK
/// Produced by Appwrite SDK Generator
///

# Account Service

## Get Account

```http request
GET https://appwrite.io/v1/account
```

** Get currently logged in user data as JSON object. **

## Create Account

```http request
POST https://appwrite.io/v1/account
```

** Use this endpoint to allow a new user to register a new account in your project. After the user registration completes successfully, you can use the [/account/verfication](/docs/client/account#createVerification) route to start verifying the user email address. To allow your new user to login to his new account, you need to create a new [account session](/docs/client/account#createSession). **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| email | string | User email. |  |
| password | string | User password. Must be between 6 to 32 chars. |  |
| name | string | User name. |  |

## Delete Account

```http request
DELETE https://appwrite.io/v1/account
```

** Delete a currently logged in user account. Behind the scene, the user record is not deleted but permanently blocked from any access. This is done to avoid deleted accounts being overtaken by new users with the same email address. Any user-related resources like documents or storage files should be deleted separately. **

## Update Account Email

```http request
PATCH https://appwrite.io/v1/account/email
```

** Update currently logged in user account email address. After changing user address, user confirmation status is being reset and a new confirmation mail is sent. For security measures, user password is required to complete this request. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| email | string | User email. |  |
| password | string | User password. Must be between 6 to 32 chars. |  |

## Get Account Logs

```http request
GET https://appwrite.io/v1/account/logs
```

** Get currently logged in user list of latest security activity logs. Each log returns user IP address, location and date and time of log. **

## Update Account Name

```http request
PATCH https://appwrite.io/v1/account/name
```

** Update currently logged in user account name. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| name | string | User name. |  |

## Update Account Password

```http request
PATCH https://appwrite.io/v1/account/password
```

** Update currently logged in user password. For validation, user is required to pass the password twice. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| password | string | New user password. Must be between 6 to 32 chars. |  |
| oldPassword | string | Old user password. Must be between 6 to 32 chars. |  |

## Get Account Preferences

```http request
GET https://appwrite.io/v1/account/prefs
```

** Get currently logged in user preferences as a key-value object. **

## Update Account Preferences

```http request
PATCH https://appwrite.io/v1/account/prefs
```

** Update currently logged in user account preferences. You can pass only the specific settings you wish to update. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| prefs | object | Prefs key-value JSON object. |  |

## Create Password Recovery

```http request
POST https://appwrite.io/v1/account/recovery
```

** Sends the user an email with a temporary secret key for password reset. When the user clicks the confirmation link he is redirected back to your app password reset URL with the secret key and email address values attached to the URL query string. Use the query string params to submit a request to the [PUT /account/recovery](/docs/client/account#updateRecovery) endpoint to complete the process. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| email | string | User email. |  |
| url | string | URL to redirect the user back to your app from the recovery email. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API. |  |

## Complete Password Recovery

```http request
PUT https://appwrite.io/v1/account/recovery
```

** Use this endpoint to complete the user account password reset. Both the **userId** and **secret** arguments will be passed as query parameters to the redirect URL you have provided when sending your request to the [POST /account/recovery](/docs/client/account#createRecovery) endpoint.

Please note that in order to avoid a [Redirect Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URLs are the ones from domains you have set when adding your platforms in the console interface. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | User account UID address. |  |
| secret | string | Valid reset token. |  |
| password | string | New password. Must be between 6 to 32 chars. |  |
| passwordAgain | string | New password again. Must be between 6 to 32 chars. |  |

## Get Account Sessions

```http request
GET https://appwrite.io/v1/account/sessions
```

** Get currently logged in user list of active sessions across different devices. **

## Create Account Session

```http request
POST https://appwrite.io/v1/account/sessions
```

** Allow the user to login into his account by providing a valid email and password combination. This route will create a new session for the user. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| email | string | User email. |  |
| password | string | User password. Must be between 6 to 32 chars. |  |

## Delete All Account Sessions

```http request
DELETE https://appwrite.io/v1/account/sessions
```

** Delete all sessions from the user account and remove any sessions cookies from the end client. **

## Create Account Session with OAuth2

```http request
GET https://appwrite.io/v1/account/sessions/oauth2/{provider}
```

** Allow the user to login to his account using the OAuth2 provider of his choice. Each OAuth2 provider should be enabled from the Appwrite console first. Use the success and failure arguments to provide a redirect URL&#039;s back to your app when login is completed. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| provider | string | **Required** OAuth2 Provider. Currently, supported providers are: amazon, apple, bitbucket, bitly, box, discord, dropbox, facebook, github, gitlab, google, linkedin, microsoft, paypal, paypalSandbox, salesforce, slack, spotify, twitch, vk, yahoo, yandex. |  |
| success | string | URL to redirect back to your app after a successful login attempt.  Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API. | https://appwrite.io/auth/oauth2/success |
| failure | string | URL to redirect back to your app after a failed login attempt.  Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API. | https://appwrite.io/auth/oauth2/failure |
| scopes | array | A list of custom OAuth2 scopes. Check each provider internal docs for a list of supported scopes. | [] |

## Delete Account Session

```http request
DELETE https://appwrite.io/v1/account/sessions/{sessionId}
```

** Use this endpoint to log out the currently logged in user from all his account sessions across all his different devices. When using the option id argument, only the session unique ID provider will be deleted. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| sessionId | string | **Required** Session unique ID. Use the string &#039;current&#039; to delete the current device session. |  |

## Create Email Verification

```http request
POST https://appwrite.io/v1/account/verification
```

** Use this endpoint to send a verification message to your user email address to confirm they are the valid owners of that address. Both the **userId** and **secret** arguments will be passed as query parameters to the URL you have provided to be attached to the verification email. The provided URL should redirect the user back to your app and allow you to complete the verification process by verifying both the **userId** and **secret** parameters. Learn more about how to [complete the verification process](/docs/client/account#updateAccountVerification). 

Please note that in order to avoid a [Redirect Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md), the only valid redirect URLs are the ones from domains you have set when adding your platforms in the console interface.
 **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| url | string | URL to redirect the user back to your app from the verification email. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API. |  |

## Complete Email Verification

```http request
PUT https://appwrite.io/v1/account/verification
```

** Use this endpoint to complete the user email verification process. Use both the **userId** and **secret** parameters that were attached to your app URL to verify the user email ownership. If confirmed this route will return a 200 status code. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | User unique ID. |  |
| secret | string | Valid verification token. |  |

