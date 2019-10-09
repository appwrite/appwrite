# Storage Service

## List Files

```http request
GET https://appwrite.io/v1/storage/files
```

** /docs/references/storage/list-files.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| search | string | Search term to filter your list results. |  |
| limit | integer | Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request. | 25 |
| offset | integer | Results offset. The default value is 0. Use this param to manage pagination. | 0 |
| orderType | string | Order result by ASC or DESC order. | ASC |

## Create File

```http request
POST https://appwrite.io/v1/storage/files
```

** /docs/references/storage/create-file.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| files | file | Binary Files. |  |
| read | array | An array of strings with read permissions. [Learn more about permissions and roles](/docs/permissions). | [] |
| write | array | An array of strings with write permissions. [Learn more about permissions and roles](/docs/permissions). | [] |
| folderId | string | Folder to associate files with. |  |

## Get File

```http request
GET https://appwrite.io/v1/storage/files/{fileId}
```

** /docs/references/storage/get-file.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| fileId | string | **Required** File unique ID. |  |

## Update File

```http request
PUT https://appwrite.io/v1/storage/files/{fileId}
```

** /docs/references/storage/update-file.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| fileId | string | **Required** File unique ID. |  |
| read | array | An array of strings with read permissions. [Learn more about permissions and roles](/docs/permissions). | [] |
| write | array | An array of strings with write permissions. [Learn more about permissions and roles](/docs/permissions). | [] |
| folderId | string | Folder to associate files with. |  |

## Delete File

```http request
DELETE https://appwrite.io/v1/storage/files/{fileId}
```

** /docs/references/storage/delete-file.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| fileId | string | **Required** File unique ID. |  |

## Get File for Download

```http request
GET https://appwrite.io/v1/storage/files/{fileId}/download
```

** /docs/references/storage/get-file-download.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| fileId | string | **Required** File unique ID. |  |

## Get File Preview

```http request
GET https://appwrite.io/v1/storage/files/{fileId}/preview
```

** /docs/references/storage/get-file-preview.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| fileId | string | **Required** File unique ID |  |
| width | integer | Resize preview image width, Pass an integer between 0 to 4000 | 0 |
| height | integer | Resize preview image height, Pass an integer between 0 to 4000 | 0 |
| quality | integer | Preview image quality. Pass an integer between 0 to 100. Defaults to 100 | 100 |
| background | string | Preview image background color. Only works with transparent images (png). Use a valid HEX color, no # is needed for prefix. |  |
| output | string | Output format type (jpeg, jpg, png, gif and webp) |  |

## Get File for View

```http request
GET https://appwrite.io/v1/storage/files/{fileId}/view
```

** /docs/references/storage/get-file-view.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| fileId | string | **Required** File unique ID. |  |
| as | string | Choose a file format to convert your file to. Currently you can only convert word and pdf files to pdf or txt. This option is currently experimental only, use at your own risk. |  |

