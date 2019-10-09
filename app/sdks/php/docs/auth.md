# Auth Service

## Login User

```http request
POST https://appwrite.io/v1/auth/login
```

** /docs/references/auth/login.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| email | string | User account email address |  |
| password | string | User account password |  |
| success | string | URL to redirect back to your app after a successful login attempt. |  |
| failure | string | URL to redirect back to your app after a failed login attempt. |  |

## Logout Current Session

```http request
DELETE https://appwrite.io/v1/auth/logout
```

** /docs/references/auth/logout.md **

## Logout Specific Session

```http request
DELETE https://appwrite.io/v1/auth/logout/{id}
```

** /docs/references/auth/logout-by-session.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| id | string | **Required** User specific session unique ID number. if 0 delete all sessions. |  |

## OAuth Login

```http request
GET https://appwrite.io/v1/auth/oauth/{provider}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| provider | string | **Required** OAuth Provider |  |
| success | string | URL to redirect back to your app after a successful login attempt. |  |
| failure | string | URL to redirect back to your app after a failed login attempt. |  |

## Password Recovery

```http request
POST https://appwrite.io/v1/auth/recovery
```

** /docs/references/auth/recovery.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| email | string | User account email address. |  |
| reset | string | Reset URL in your app to redirect the user after the reset token has been sent to the user email. |  |

## Password Reset

```http request
PUT https://appwrite.io/v1/auth/recovery/reset
```

** /docs/references/auth/recovery-reset.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | User account email address. |  |
| token | string | Valid reset token. |  |
| password-a | string | New password. |  |
| password-b | string | New password again. |  |

## Register User

```http request
POST https://appwrite.io/v1/auth/register
```

** /docs/references/auth/register.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| email | string | Account email |  |
| password | string | User password |  |
| confirm | string | Confirmation URL to redirect user after confirm token has been sent to user email |  |
| success | string | Redirect when registration succeed |  |
| failure | string | Redirect when registration failed |  |
| name | string | User name |  |

## Confirm User

```http request
POST https://appwrite.io/v1/auth/register/confirm
```

** /docs/references/auth/confirm.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | User unique ID |  |
| token | string | Confirmation secret token |  |

## Resend Confirmation

```http request
POST https://appwrite.io/v1/auth/register/confirm/resend
```

** /docs/references/auth/confirm-resend.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| confirm | string | Confirmation URL to redirect user to your app after confirm token has been sent to user email. |  |

