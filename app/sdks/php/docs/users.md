# Users Service

## List Users

```http request
GET https://appwrite.io/v1/users
```

** /docs/references/users/list-users.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| search | string | Search term to filter your list results. |  |
| limit | integer | Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request. | 25 |
| offset | integer | Results offset. The default value is 0. Use this param to manage pagination. | 0 |
| orderType | string | Order result by ASC or DESC order. | ASC |

## Create User

```http request
POST https://appwrite.io/v1/users
```

** /docs/references/users/create-user.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| email | string | User account email. |  |
| password | string | User account password. |  |
| name | string | User account name. |  |

## Get User

```http request
GET https://appwrite.io/v1/users/{userId}
```

** /docs/references/users/get-user.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | **Required** User unique ID. |  |

## Get User Logs

```http request
GET https://appwrite.io/v1/users/{userId}/logs
```

** /docs/references/users/get-user-logs.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | **Required** User unique ID. |  |

## Get User Prefs

```http request
GET https://appwrite.io/v1/users/{userId}/prefs
```

** /docs/references/users/get-user-prefs.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | **Required** User unique ID. |  |

## Update Account Prefs

```http request
PATCH https://appwrite.io/v1/users/{userId}/prefs
```

** /docs/references/users/update-user-prefs.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | **Required** User unique ID. |  |
| prefs | string | Prefs key-value JSON object string. |  |

## Get User Sessions

```http request
GET https://appwrite.io/v1/users/{userId}/sessions
```

** /docs/references/users/get-user-sessions.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | **Required** User unique ID. |  |

## Delete User Sessions

```http request
DELETE https://appwrite.io/v1/users/{userId}/sessions
```

** Delete all user sessions by its unique ID. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | **Required** User unique ID. |  |

## Delete User Session

```http request
DELETE https://appwrite.io/v1/users/{userId}/sessions/:session
```

** /docs/references/users/delete-user-session.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | **Required** User unique ID. |  |
| sessionId | string | User unique session ID. |  |

## Update user status

```http request
PATCH https://appwrite.io/v1/users/{userId}/status
```

** /docs/references/users/update-user-status.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | **Required** User unique ID. |  |
| status | string | User Status code. To activate the user pass 1, to blocking the user pass 2 and for disabling the user pass 0 |  |

