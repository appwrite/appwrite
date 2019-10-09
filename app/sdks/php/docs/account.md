# Account Service

## Get Account

```http request
GET https://appwrite.io/v1/account
```

** Get currently logged in user data as JSON object. **

## Delete Account

```http request
DELETE https://appwrite.io/v1/account
```

** Delete currently logged in user account. **

## Update Account Email

```http request
PATCH https://appwrite.io/v1/account/email
```

** Update currently logged in user account email address. After changing user address, user confirmation status is being reset and a new confirmation mail is sent. For security measures, user password is required to complete this request. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| email | string | Email Address |  |
| password | string | User Password |  |

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

## Update Account Prefs

```http request
PATCH https://appwrite.io/v1/account/prefs
```

** Update currently logged in user account preferences. You can pass only the specific settings you wish to update. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| prefs | string | Prefs key-value JSON object string. |  |

## Get Account Security Log

```http request
GET https://appwrite.io/v1/account/security
```

** Get currently logged in user list of latest security activity logs. Each log returns user IP address, location and date and time of log. **

## Get Account Active Sessions

```http request
GET https://appwrite.io/v1/account/sessions
```

** Get currently logged in user list of active sessions across different devices. **

