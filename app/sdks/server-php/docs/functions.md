# Functions Service

## List Functions

```http request
GET https://appwrite.io/v1/functions
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| search | string | Search term to filter your list results. |  |
| limit | integer | Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request. | 25 |
| offset | integer | Results offset. The default value is 0. Use this param to manage pagination. | 0 |
| orderType | string | Order result by ASC or DESC order. | ASC |

## Create Function

```http request
POST https://appwrite.io/v1/functions
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| name | string | Function name. |  |
| vars | object | Key-value JSON object. | [] |
| trigger | string | Function trigger type. | event |
| events | array | Events list. | [] |
| schedule | string | Schedule CRON syntax. |  |
| timeout | integer | Function maximum execution time in seconds. | 10 |

## Get Function

```http request
GET https://appwrite.io/v1/functions/{functionId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| functionId | string | **Required** Function unique ID. |  |

## Update Function

```http request
PUT https://appwrite.io/v1/functions/{functionId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| functionId | string | **Required** Function unique ID. |  |
| name | string | Function name. |  |
| vars | object | Key-value JSON object. | [] |
| trigger | string | Function trigger type. | event |
| events | array | Events list. | [] |
| schedule | string | Schedule CRON syntax. |  |
| timeout | integer | Function maximum execution time in seconds. | 10 |

## Delete Function

```http request
DELETE https://appwrite.io/v1/functions/{functionId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| functionId | string | **Required** Function unique ID. |  |

## Update Function Active Tag

```http request
PATCH https://appwrite.io/v1/functions/{functionId}/active
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| functionId | string | **Required** Function unique ID. |  |
| active | string | Active tag unique ID. |  |

## List Executions

```http request
GET https://appwrite.io/v1/functions/{functionId}/executions
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| functionId | string | **Required** Function unique ID. |  |
| search | string | Search term to filter your list results. |  |
| limit | integer | Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request. | 25 |
| offset | integer | Results offset. The default value is 0. Use this param to manage pagination. | 0 |
| orderType | string | Order result by ASC or DESC order. | ASC |

## Create Execution

```http request
POST https://appwrite.io/v1/functions/{functionId}/executions
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| functionId | string | **Required** Function unique ID. |  |
| async | integer | Execute code asynchronously. Pass 1 for true, 0 for false. Default value is 1. | 1 |

## Get Execution

```http request
GET https://appwrite.io/v1/functions/{functionId}/executions/{executionId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| functionId | string | **Required** Function unique ID. |  |
| executionId | string | **Required** Execution unique ID. |  |

## List Tags

```http request
GET https://appwrite.io/v1/functions/{functionId}/tags
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| functionId | string | **Required** Function unique ID. |  |
| search | string | Search term to filter your list results. |  |
| limit | integer | Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request. | 25 |
| offset | integer | Results offset. The default value is 0. Use this param to manage pagination. | 0 |
| orderType | string | Order result by ASC or DESC order. | ASC |

## Create Tag

```http request
POST https://appwrite.io/v1/functions/{functionId}/tags
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| functionId | string | **Required** Function unique ID. |  |
| env | string | Execution enviornment. |  |
| command | string | Code execution command. |  |
| code | string | Code package. Use the Appwrite code packager to create a deployable package file. |  |

## Get Tag

```http request
GET https://appwrite.io/v1/functions/{functionId}/tags/{tagId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| functionId | string | **Required** Function unique ID. |  |
| tagId | string | **Required** Tag unique ID. |  |

## Delete Tag

```http request
DELETE https://appwrite.io/v1/functions/{functionId}/tags/{tagId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| functionId | string | **Required** Function unique ID. |  |
| tagId | string | **Required** Tag unique ID. |  |

