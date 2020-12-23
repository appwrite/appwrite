# Users Service

## List Users

```http request
GET https://appwrite.io/v1/users
```

** Get a list of all the project users. You can use the query params to filter your results. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| search | string | Search term to filter your list results. Max length: 256 chars. |  |
| limit | integer | Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request. | 25 |
| offset | integer | Results offset. The default value is 0. Use this param to manage pagination. | 0 |
| orderType | string | Order result by ASC or DESC order. | ASC |

## Create User

```http request
POST https://appwrite.io/v1/users
```

** Create a new user. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| email | string | User email. |  |
| password | string | User password. Must be between 6 to 32 chars. |  |
| name | string | User name. Max length: 128 chars. |  |

## Get User

```http request
GET https://appwrite.io/v1/users/{userId}
```

** Get user by its unique ID. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | **Required** User unique ID. |  |

## Delete User

```http request
DELETE https://appwrite.io/v1/users/{userId}
```

** Delete a user by its unique ID. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | **Required** User unique ID. |  |

## Get User Logs

```http request
GET https://appwrite.io/v1/users/{userId}/logs
```

** Get user activity logs list by its unique ID. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | **Required** User unique ID. |  |

## Get User Preferences

```http request
GET https://appwrite.io/v1/users/{userId}/prefs
```

** Get user preferences by its unique ID. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | **Required** User unique ID. |  |

## Update User Preferences

```http request
PATCH https://appwrite.io/v1/users/{userId}/prefs
```

** Update user preferences by its unique ID. You can pass only the specific settings you wish to update. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | **Required** User unique ID. |  |
| prefs | object | Prefs key-value JSON object. |  |

## Get User Sessions

```http request
GET https://appwrite.io/v1/users/{userId}/sessions
```

** Get user sessions list by its unique ID. **

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
DELETE https://appwrite.io/v1/users/{userId}/sessions/{sessionId}
```

** Delete user sessions by its unique ID. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | **Required** User unique ID. |  |
| sessionId | string | **Required** User unique session ID. |  |

## Update User Status

```http request
PATCH https://appwrite.io/v1/users/{userId}/status
```

** Update user status by its unique ID. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| userId | string | **Required** User unique ID. |  |
| status | string | User Status code. To activate the user pass 1, to block the user pass 2 and for disabling the user pass 0 |  |

