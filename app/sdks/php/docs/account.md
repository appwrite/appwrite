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

** Use this endpoint to allow a new user to register an account in your project. Use the success and failure URLs to redirect users back to your application after signup completes.

If registration completes successfully user will be sent with a confirmation email in order to confirm he is the owner of the account email address. Use the confirmation parameter to redirect the user from the confirmation email back to your app. When the user is redirected, use the /auth/confirm endpoint to complete the account confirmation.

Please note that in order to avoid a [Redirect Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URLs are the ones from domains you have set when adding your platforms in the console interface.

When accessing this route using Javascript from the browser, success and failure parameter URLs are required. Appwrite server will respond with a 301 redirect status code and will set the user session cookie. This behavior is enforced because modern browsers are limiting 3rd party cookies in XHR of fetch requests to protect user privacy. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| email | string | Account email |  |
| password | string | User password |  |
| name | string | User name |  |

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
| email | string | Email address |  |
| password | string | User password |  |

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
| name | string | User name |  |

## Update Account Password

```http request
PATCH https://appwrite.io/v1/account/password
```

** Update currently logged in user password. For validation, user is required to pass the password twice. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| password | string | New password |  |
| old-password | string | Old password |  |

## Get Account Preferences

```http request
GET https://appwrite.io/v1/account/prefs
```

** Get currently logged in user preferences key-value object. **

## Update Account Preferences

```http request
PATCH https://appwrite.io/v1/account/prefs
```

** Update currently logged in user account preferences. You can pass only the specific settings you wish to update. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| prefs | string | Prefs key-value JSON object. |  |

## Password Recovery

```http request
POST https://appwrite.io/v1/account/recovery
```

** Sends the user an email with a temporary secret token for password reset. When the user clicks the confirmation link he is redirected back to your app password reset redirect URL with a secret token and email address values attached to the URL query string. Use the query string params to submit a request to the /auth/password/reset endpoint to complete the process. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| email | string | User account email address. |  |
| url | string | URL to redirect the user back to your app from the recovery email. |  |

## Password Reset

```http request
PUT https://appwrite.io/v1/account/recovery
```

** Use this endpoint to complete the user account password reset. Both the **userId** and **token** arguments will be passed as query parameters to the redirect URL you have provided when sending your request to the /auth/recovery endpoint.

Please note that in order to avoid a [Redirect Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URLs are the ones from domains you have set when adding your platforms in the console interface. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | User account UID address. |  |
| secret | string | Valid reset token. |  |
| password-a | string | New password. |  |
| password-b | string | New password again. |  |

## Get Account Sessions

```http request
GET https://appwrite.io/v1/account/sessions
```

** Get currently logged in user list of active sessions across different devices. **

## Create Account Session

```http request
POST https://appwrite.io/v1/account/sessions
```

** Allow the user to login into his account by providing a valid email and password combination. Use the success and failure arguments to provide a redirect URL&#039;s back to your app when login is completed. 

Please note that in order to avoid a [Redirect Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URLs are the ones from domains you have set when adding your platforms in the console interface.

When accessing this route using Javascript from the browser, success and failure parameter URLs are required. Appwrite server will respond with a 301 redirect status code and will set the user session cookie. This behavior is enforced because modern browsers are limiting 3rd party cookies in XHR of fetch requests to protect user privacy. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| email | string | User account email address |  |
| password | string | User account password |  |

## Delete All Account Sessions

```http request
DELETE https://appwrite.io/v1/account/sessions
```

** Delete all sessions from the user account and remove any sessions cookies from the end client. **

## Delete Current Account Session

```http request
DELETE https://appwrite.io/v1/account/sessions/current
```

** Use this endpoint to log out the currently logged in user from his account. When successful this endpoint will delete the user session and remove the session secret cookie from the user client. **

## Create Account Session with OAuth

```http request
GET https://appwrite.io/v1/account/sessions/oauth/{provider}
```

** Allow the user to login to his account using the OAuth provider of his choice. Each OAuth provider should be enabled from the Appwrite console first. Use the success and failure arguments to provide a redirect URL&#039;s back to your app when login is completed. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| provider | string | **Required** OAuth Provider. Currently, supported providers are: bitbucket, facebook, github, gitlab, google, microsoft, linkedin, slack, dropbox, salesforce, amazon, vk, discord, twitch, spotify, yahoo, yandex, twitter, paypal, bitly, mock |  |
| success | string | **Required** URL to redirect back to your app after a successful login attempt. |  |
| failure | string | **Required** URL to redirect back to your app after a failed login attempt. |  |

## Delete Account Session

```http request
DELETE https://appwrite.io/v1/account/sessions/{id}
```

** Use this endpoint to log out the currently logged in user from all his account sessions across all his different devices. When using the option id argument, only the session unique ID provider will be deleted. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| id | string | **Required** Session unique ID. |  |

## Create Verification

```http request
POST https://appwrite.io/v1/account/verification
```

** Use this endpoint to send a verification message to your user email address to confirm they are the valid owners of that address. Both the **userId** and **token** arguments will be passed as query parameters to the URL you have provider to be attached to the verification email. The provided URL should redirect the user back for your app and allow you to complete the verification process by verifying both the **userId** and **token** parameters. Learn more about how to [complete the verification process](/docs/account#updateAccountVerification). 

Please note that in order to avoid a [Redirect Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URLs are the ones from domains you have set when adding your platforms in the console interface. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| url | string | URL to redirect the user back to your app from the verification email. |  |

## Updated Verification

```http request
PUT https://appwrite.io/v1/account/verification
```

** Use this endpoint to complete the user email verification process. Use both the **userId** and **token** parameters that were attached to your app URL to verify the user email ownership. If confirmed this route will return a 200 status code. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | User account UID address. |  |
| secret | string | Valid reset token. |  |
| password-b | string | New password again. |  |

