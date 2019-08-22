# Projects Service

## List Projects

```http request
GET https://appwrite.test/v1/projects
```

## Create Project

```http request
POST https://appwrite.test/v1/projects
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| name | string | Project name |  |
| teamId | string | Team unique ID. |  |
| description | string | Project description |  |
| logo | string | Project logo |  |
| url | string | Project URL |  |
| legalName | string | Project Legal Name |  |
| legalCountry | string | Project Legal Country |  |
| legalState | string | Project Legal State |  |
| legalCity | string | Project Legal City |  |
| legalAddress | string | Project Legal Address |  |
| legalTaxId | string | Project Legal Tax ID |  |

## Get Project

```http request
GET https://appwrite.test/v1/projects/{projectId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |

## Update Project

```http request
PATCH https://appwrite.test/v1/projects/{projectId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |
| name | string | Project name |  |
| description | string | Project description |  |
| logo | string | Project logo |  |
| url | string | Project URL |  |
| legalName | string | Project Legal Name |  |
| legalCountry | string | Project Legal Country |  |
| legalState | string | Project Legal State |  |
| legalCity | string | Project Legal City |  |
| legalAddress | string | Project Legal Address |  |
| legalTaxId | string | Project Legal Tax ID |  |

## Delete Project

```http request
DELETE https://appwrite.test/v1/projects/{projectId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |

## List Keys

```http request
GET https://appwrite.test/v1/projects/{projectId}/keys
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |

## Create Key

```http request
POST https://appwrite.test/v1/projects/{projectId}/keys
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |
| name | string | Key name |  |
| scopes | array | Key scopes list |  |

## Get Key

```http request
GET https://appwrite.test/v1/projects/{projectId}/keys/{keyId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |
| keyId | string | **Required** Key unique ID. |  |

## Update Key

```http request
PUT https://appwrite.test/v1/projects/{projectId}/keys/{keyId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |
| keyId | string | **Required** Key unique ID. |  |
| name | string | Key name |  |
| scopes | array | Key scopes list |  |

## Delete Key

```http request
DELETE https://appwrite.test/v1/projects/{projectId}/keys/{keyId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |
| keyId | string | **Required** Key unique ID. |  |

## Update Project OAuth

```http request
PATCH https://appwrite.test/v1/projects/{projectId}/oauth
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |
| provider | string | Provider Name |  |
| appId | string | Provider App ID |  |
| secret | string | Provider Secret Key |  |

## List Platforms

```http request
GET https://appwrite.test/v1/projects/{projectId}/platforms
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |

## Create Platform

```http request
POST https://appwrite.test/v1/projects/{projectId}/platforms
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |
| type | string | Platform name |  |
| name | string | Platform name |  |
| key | string | Package name for android or bundle ID for iOS |  |
| store | string | App store or Google Play store ID |  |
| url | string | Platform client URL |  |

## Get Platform

```http request
GET https://appwrite.test/v1/projects/{projectId}/platforms/{platformId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |
| platformId | string | **Required** Platform unique ID. |  |

## Update Platform

```http request
PUT https://appwrite.test/v1/projects/{projectId}/platforms/{platformId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |
| platformId | string | **Required** Platform unique ID. |  |
| name | string | Platform name |  |
| key | string | Package name for android or bundle ID for iOS |  |
| store | string | App store or Google Play store ID |  |
| url | string | Platform client URL |  |

## Delete Platform

```http request
DELETE https://appwrite.test/v1/projects/{projectId}/platforms/{platformId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |
| platformId | string | **Required** Platform unique ID. |  |

## List Tasks

```http request
GET https://appwrite.test/v1/projects/{projectId}/tasks
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |

## Create Task

```http request
POST https://appwrite.test/v1/projects/{projectId}/tasks
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |
| name | string | Task name |  |
| status | string | Task status |  |
| schedule | string | Task schedule syntax |  |
| security | integer | Certificate verification, 0 for disabled or 1 for enabled |  |
| httpMethod | string | Task HTTP method |  |
| httpUrl | string | Task HTTP URL |  |
| httpHeaders | array | Task HTTP headers list |  |
| httpUser | string | Task HTTP user |  |
| httpPass | string | Task HTTP password |  |

## Get Task

```http request
GET https://appwrite.test/v1/projects/{projectId}/tasks/{taskId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |
| taskId | string | **Required** Task unique ID. |  |

## Update Task

```http request
PUT https://appwrite.test/v1/projects/{projectId}/tasks/{taskId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |
| taskId | string | **Required** Task unique ID. |  |
| name | string | Task name |  |
| status | string | Task status |  |
| schedule | string | Task schedule syntax |  |
| security | integer | Certificate verification, 0 for disabled or 1 for enabled |  |
| httpMethod | string | Task HTTP method |  |
| httpUrl | string | Task HTTP URL |  |
| httpHeaders | array | Task HTTP headers list |  |
| httpUser | string | Task HTTP user |  |
| httpPass | string | Task HTTP password |  |

## Delete Task

```http request
DELETE https://appwrite.test/v1/projects/{projectId}/tasks/{taskId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |
| taskId | string | **Required** Task unique ID. |  |

## Get Project

```http request
GET https://appwrite.test/v1/projects/{projectId}/usage
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |

## List Webhooks

```http request
GET https://appwrite.test/v1/projects/{projectId}/webhooks
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |

## Create Webhook

```http request
POST https://appwrite.test/v1/projects/{projectId}/webhooks
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |
| name | string | Webhook name |  |
| events | array | Webhook events list |  |
| url | string | Webhook URL |  |
| security | integer | Certificate verification, 0 for disabled or 1 for enabled |  |
| httpUser | string | Webhook HTTP user |  |
| httpPass | string | Webhook HTTP password |  |

## Get Webhook

```http request
GET https://appwrite.test/v1/projects/{projectId}/webhooks/{webhookId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |
| webhookId | string | **Required** Webhook unique ID. |  |

## Update Webhook

```http request
PUT https://appwrite.test/v1/projects/{projectId}/webhooks/{webhookId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |
| webhookId | string | **Required** Webhook unique ID. |  |
| name | string | Webhook name |  |
| events | array | Webhook events list |  |
| url | string | Webhook URL |  |
| security | integer | Certificate verification, 0 for disabled or 1 for enabled |  |
| httpUser | string | Webhook HTTP user |  |
| httpPass | string | Webhook HTTP password |  |

## Delete Webhook

```http request
DELETE https://appwrite.test/v1/projects/{projectId}/webhooks/{webhookId}
```

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| projectId | string | **Required** Project unique ID. |  |
| webhookId | string | **Required** Webhook unique ID. |  |

