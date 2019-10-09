# Account Service

## Get Account

```http request
GET https://appwrite.io/v1/account
```

** /docs/references/account/get.md **

## Delete Account

```http request
DELETE https://appwrite.io/v1/account
```

** /docs/references/account/delete.md **

## Update Account Email

```http request
PATCH https://appwrite.io/v1/account/email
```

** /docs/references/account/update-email.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| email | string | Email Address |  |
| password | string | User Password |  |

## Update Account Name

```http request
PATCH https://appwrite.io/v1/account/name
```

** /docs/references/account/update-name.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| name | string | User name |  |

## Update Account Password

```http request
PATCH https://appwrite.io/v1/account/password
```

** /docs/references/account/update-password.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| password | string | New password |  |
| old-password | string | Old password |  |

## Get Account Preferences

```http request
GET https://appwrite.io/v1/account/prefs
```

** /docs/references/account/get-prefs.md **

## Update Account Prefs

```http request
PATCH https://appwrite.io/v1/account/prefs
```

** /docs/references/account/update-prefs.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| prefs | string | Prefs key-value JSON object string. |  |

## Get Account Security Log

```http request
GET https://appwrite.io/v1/account/security
```

** /docs/references/account/get-security.md **

## Get Account Active Sessions

```http request
GET https://appwrite.io/v1/account/sessions
```

** /docs/references/account/get-sessions.md **

