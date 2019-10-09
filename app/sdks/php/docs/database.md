# Database Service

## List Collections

```http request
GET https://appwrite.io/v1/database
```

** /docs/references/database/list-collections.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| search | string | Search term to filter your list results. |  |
| limit | integer | Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request. | 25 |
| offset | integer | Results offset. The default value is 0. Use this param to manage pagination. | 0 |
| orderType | string | Order result by ASC or DESC order. | ASC |

## Create Collection

```http request
POST https://appwrite.io/v1/database
```

** /docs/references/database/create-collection.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| name | string | Collection name. |  |
| read | array | An array of strings with read permissions. [Learn more about permissions and roles](/docs/permissions). | [] |
| write | array | An array of strings with write permissions. [Learn more about permissions and roles](/docs/permissions). | [] |
| rules | array | Array of [rule objects](/docs/rules). Each rule define a collection field name, data type and validation | [] |

## Get Collection

```http request
GET https://appwrite.io/v1/database/{collectionId}
```

** /docs/references/database/get-collection.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| collectionId | string | **Required** Collection unique ID. |  |

## Update Collection

```http request
PUT https://appwrite.io/v1/database/{collectionId}
```

** /docs/references/database/update-collection.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| collectionId | string | **Required** Collection unique ID. |  |
| name | string | Collection name. |  |
| read | array | An array of strings with read permissions. [Learn more about permissions and roles](/docs/permissions). | [] |
| write | array | An array of strings with write permissions. [Learn more about permissions and roles](/docs/permissions). | [] |
| rules | array | Array of [rule objects](/docs/rules). Each rule define a collection field name, data type and validation | [] |

## Delete Collection

```http request
DELETE https://appwrite.io/v1/database/{collectionId}
```

** /docs/references/database/delete-collection.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| collectionId | string | **Required** Collection unique ID. |  |

## List Documents

```http request
GET https://appwrite.io/v1/database/{collectionId}/documents
```

** /docs/references/database/list-documents.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| collectionId | string | **Required** Collection unique ID. |  |
| filters | array | Array of filter strings. Each filter is constructed from a key name, comparison operator (=, !=, &gt;, &lt;, &lt;=, &gt;=) and a value. You can also use a dot (.) separator in attribute names to filter by child document attributes. Examples: &#039;name=John Doe&#039; or &#039;category.$uid&gt;=5bed2d152c362&#039; | [] |
| offset | integer | Offset value. Use this value to manage pagination. | 0 |
| limit | integer | Maximum number of documents to return in response.  Use this value to manage pagination. | 50 |
| order-field | string | Document field that results will be sorted by. | $uid |
| order-type | string | Order direction. Possible values are DESC for descending order, or ASC for ascending order. | ASC |
| order-cast | string | Order field type casting. Possible values are int, string, date, time or datetime. The database will attempt to cast the order field to the value you pass here. The default value is a string. | string |
| search | string | Search query. Enter any free text search. The database will try to find a match against all document attributes and children. |  |
| first | integer | Return only first document. Pass 1 for true or 0 for false. The default value is 0. | 0 |
| last | integer | Return only last document. Pass 1 for true or 0 for false. The default value is 0. | 0 |

## Create Document

```http request
POST https://appwrite.io/v1/database/{collectionId}/documents
```

** /docs/references/database/create-document.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| collectionId | string | **Required** Collection unique ID. |  |
| data | string | Document data as JSON string. |  |
| read | array | An array of strings with read permissions. [Learn more about permissions and roles](/docs/permissions). | [] |
| write | array | An array of strings with write permissions. [Learn more about permissions and roles](/docs/permissions). | [] |
| parentDocument | string | Parent document unique ID. Use when you want your new document to be a child of a parent document. |  |
| parentProperty | string | Parent document property name. Use when you want your new document to be a child of a parent document. |  |
| parentPropertyType | string | Parent document property connection type. You can set this value to **assign**, **append** or **prepend**, default value is assign. Use when you want your new document to be a child of a parent document. | assign |

## Get Document

```http request
GET https://appwrite.io/v1/database/{collectionId}/documents/{documentId}
```

** /docs/references/database/get-document.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| collectionId | string | **Required** Collection unique ID |  |
| documentId | string | **Required** Document unique ID |  |

## Update Document

```http request
PATCH https://appwrite.io/v1/database/{collectionId}/documents/{documentId}
```

** /docs/references/database/update-document.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| collectionId | string | **Required** Collection unique ID |  |
| documentId | string | **Required** Document unique ID |  |
| data | string | Document data as JSON string |  |
| read | array | An array of strings with read permissions. [Learn more about permissions and roles](/docs/permissions). | [] |
| write | array | An array of strings with write permissions. [Learn more about permissions and roles](/docs/permissions). | [] |

## Delete Document

```http request
DELETE https://appwrite.io/v1/database/{collectionId}/documents/{documentId}
```

** /docs/references/database/delete-document.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| collectionId | string | **Required** Collection unique ID |  |
| documentId | string | **Required** Document unique ID |  |

