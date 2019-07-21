# Auth Service

## Login User

```http request
POST https://appwrite.test/v1/auth/login
```

** Allow the user to login into his account by providing a valid email and password combination. Use the success and failure arguments to provide a redirect URL\&#039;s back to your app when login is completed. 

Please notice that in order to avoid a [Redirect Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URL&#039;s are the once from domains you have set when added your platforms in the console interface.

When not using the success or failure redirect arguments this endpoint will result with a 200 status code and the user account object on success and with 401 status error on failure. This behavior was applied to help the web clients deal with browsers who don&#039;t allow to set 3rd party HTTP cookies needed for saving the account session token. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| email | string | User account email address |  |
| password | string | User account password |  |
| success | string | URL to redirect back to your app after a successful login attempt. |  |
| failure | string | URL to redirect back to your app after a failed login attempt. |  |

## Logout Current Session

```http request
DELETE https://appwrite.test/v1/auth/logout
```

** Use this endpoint to log out the currently logged in user from his account. When succeed this endpoint will delete the user session and remove the session secret cookie. **

## Logout Specific Session

```http request
DELETE https://appwrite.test/v1/auth/logout/{id}
```

** Use this endpoint to log out the currently logged in user from all his account sessions across all his different devices. When using the option id argument, only the session unique ID provider will be deleted. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| id | string | **Required** User specific session unique ID number. if 0 delete all sessions. |  |

## Password Recovery

```http request
POST https://appwrite.test/v1/auth/recovery
```

** Sends the user an email with a temporary secret token for password reset. When the user clicks the confirmation link he is redirected back to your app password reset redirect URL with a secret token and email address values attached to the URL query string. Use the query string params to submit a request to the /auth/password/reset endpoint to complete the process. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| email | string | User account email address. |  |
| redirect | string | Reset page in your app to redirect user after reset token has been sent to user email. |  |

## Password Reset

```http request
PUT https://appwrite.test/v1/auth/recovery/reset
```

** Use this endpoint to complete the user account password reset. Both the **userId** and **token** arguments will be passed as query parameters to the redirect URL you have provided when sending your request to the /auth/recovery endpoint.

Please notice that in order to avoid a [Redirect Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URL&#039;s are the once from domains you have set when added your platforms in the console interface. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | User account email address. |  |
| token | string | Valid reset token. |  |
| password-a | string | New password. |  |
| password-b | string | New password again. |  |

## Register User

```http request
POST https://appwrite.test/v1/auth/register
```

** Use this endpoint to allow a new user to register an account in your project. Use the success and failure URL&#039;s to redirect users back to your application after signup completes.

If registration completes successfully user will be sent with a confirmation email in order to confirm he is the owner of the account email address. Use the redirect parameter to redirect the user from the confirmation email back to your app. When the user is redirected, use the /auth/confirm endpoint to complete the account confirmation.

Please notice that in order to avoid a [Redirect Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URL&#039;s are the once from domains you have set when added your platforms in the console interface.

When not using the success or failure redirect arguments this endpoint will result with a 200 status code and the user account object on success and with 401 status error on failure. This behavior was applied to help the web clients deal with browsers who don&#039;t allow to set 3rd party HTTP cookies needed for saving the account session token. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| email | string | Account email |  |
| password | string | User password |  |
| name | string | User name |  |
| redirect | string | Confirmation page to redirect user after confirm token has been sent to user email |  |
| success | string | Redirect when registration succeed |  |
| failure | string | Redirect when registration failed |  |

## Confirm User

```http request
POST https://appwrite.test/v1/auth/register/confirm
```

** Use this endpoint to complete the confirmation of the user account email address. Both the **userId** and **token** arguments will be passed as query parameters to the redirect URL you have provided when sending your request to the /auth/register endpoint. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | User unique ID |  |
| token | string | Confirmation secret token |  |

## Resend Confirmation

```http request
POST https://appwrite.test/v1/auth/register/confirm/resend
```

** This endpoint allows the user to request your app to resend him his email confirmation message. The redirect arguments acts the same way as in /auth/register endpoint.

Please notice that in order to avoid a [Redirect Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URL&#039;s are the once from domains you have set when added your platforms in the console interface. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| redirect | string | Confirmation page to redirect user to your app after confirm token has been sent to user email. |  |

## OAuth Callback

```http request
GET https://appwrite.test/v1/oauth/callback/{provider}/{projectId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID |  |
| provider | string | **Required** OAuth provider |  |
| code | string | **Required** OAuth code |  |
| state | string | Login state params |  |

## OAuth Login

```http request
GET https://appwrite.test/v1/oauth/{provider}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| provider | string | **Required** OAuth Provider |  |
| success | string | URL to redirect back to your app after a successful login attempt. |  |
| failure | string | URL to redirect back to your app after a failed login attempt. |  |

