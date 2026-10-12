---
layout: article
title: REST
description: Discover the Appwrite REST API for building robust and scalable applications. Access detailed documentation on REST endpoints, authentication, and data management.
---

Appwrite supports multiple protocols for accessing the server, including [REST](/docs/apis/rest), [GraphQL](/docs/apis/graphql), and [Realtime](/docs/apis/realtime). The REST API allows you to access your Appwrite server through HTTP requests without needing an SDK. Each endpoint in the API represents a specific operation on a specific resource.

# Headers {% #headers %}

Appwrite's REST APIs expect certain headers to be included with each request:

{% table %}

- Header
-
- Description

---

- X-Appwrite-Project: [PROJECT-ID]
- required
- The ID of your Appwrite project

---

- Content-Type: application/json
- required
- Content type of the HTTP request. Typically set to `application/json`.

---

- X-Appwrite-Key: [API-KEY]
- optional
- API key used for server authentication. Your API key is a secret, **do not** use it in client applications.

---

- X-Appwrite-JWT: [TOKEN]
- optional
- Token used for JWT authentication, tokens can be generated using the [Create JWT](/docs/products/auth/jwt) method.

---

- X-Appwrite-Response-Format: [VERSION-NUMBER]
- optional
- Version number used for backward compatibility. The response will be formatted to be compatible with the provided version number. This helps Appwrite SDKs keep backward compatibility with Appwrite server API version.

---

- X-Fallback-Cookies: [FALLBACK-COOKIES]
- optional
- Fallback cookies used in scenarios where browsers do not allow third-party cookies. Often used when there is no Custom Domain set for your Appwrite API.

---

- X-Appwrite-Impersonate-User-Id: [USER-ID]
- optional
- Resolves the effective user for an already authenticated impersonator request by Appwrite user ID. Only works when the authenticated user has impersonation enabled.

---

- X-Appwrite-Impersonate-User-Email: [EMAIL]
- optional
- Resolves the effective user for an already authenticated impersonator request by email address. Only works when the authenticated user has impersonation enabled.

---

- X-Appwrite-Impersonate-User-Phone: [PHONE]
- optional
- Resolves the effective user for an already authenticated impersonator request by phone number. Only works when the authenticated user has impersonation enabled.

{% /table %}

# Authentication {% #authentication %}

Appwrite supports multiple authentication methods, including account sessions, API keys, and JWTs. The authentication method you use depends on your use case. Below are examples showing how you can authenticate using the REST API.

## Client integrations {% #client-integration %}

You can create account sessions with POST requests to the Account API. Sessions are persisted using secured cookies. You can learn more about session persistence in the Authentication Guide.

The example below shows creating an account session with the Create Account Session with Email endpoint.

```json
POST /v1/account/sessions/email HTTP/1.1
Content-Type: application/json
X-Appwrite-Project: <PROJECT_ID>

{
  "email": "example@email.com",
  "password": "password"
}
```

You can find the cookies used to persist the new session in the response headers.

```json
Set-Cookie: a_session_61e71ec784ab035f7259_legacy=eyJ0...aSJ9; expires=Tue, 19-Dec-2023 21:26:51 GMT; path=/; domain=.cloud.appwrite.io; secure; httponly
Set-Cookie: a_session_61e71ec784ab035f7259=eyJ0...aSJ9; expires=Tue, 19-Dec-2023 21:26:51 GMT; path=/; domain=.cloud.appwrite.io; secure; httponly; samesite=None
```

These cookies are used in subsequent requests to authenticate the user.

```json
GET /v1/account HTTP/1.1
Cookie: a_session_61e71ec784ab035f7259_legacy=eyJ0...aSJ9; a_session_61e71ec784ab035f7259=eyJ0...aSJ9
Content-Type: application/json
X-Appwrite-Project: <PROJECT_ID>
```

## Server integrations {% #server-integrations %}

Server integrations use API keys to authenticate and are typically used for backend applications.

Server APIs are authenticated with API keys instead of account sessions. Simply pass an [API key](/docs/partners/project/api-keys) in the `X-Appwrite-key: [API-KEY]` header with the appropriate scopes.

```json
GET /v1/tablesdb/{databaseId}/tables/{tableId}/rows HTTP/1.1
Content-Type: application/json
X-Appwrite-Project: <PROJECT_ID>
X-Appwrite-Key: [API_KEY]
```

## JWT {% #jwt %}

JWT authentication is frequently used by server applications to act on behalf of a user. Users generate tokens using the [Create JWT](/docs/references/cloud/client-web/account#createJWT) endpoint. When issuing requests authenticated with a JWT, Appwrite will treat the request like it is from the authenticated user.

```json
GET /v1/account HTTP/1.1
Content-Type: application/json
X-Appwrite-Project: <PROJECT_ID>
X-Appwrite-JWT: [TOKEN]
```

## Impersonation headers {% #impersonation-headers %}

When a request is already authenticated as a user with impersonation enabled, you can add one of Appwrite's impersonation headers to resolve a different effective user for that request.

Use exactly one of these headers:

- `X-Appwrite-Impersonate-User-Id`
- `X-Appwrite-Impersonate-User-Email`
- `X-Appwrite-Impersonate-User-Phone`

These headers are ignored for plain API key requests. They are only honored when the request already belongs to a signed-in user who has been marked as an impersonator in the Appwrite Console or through the Users API.

If you are using an Appwrite SDK instead of raw REST calls, use the corresponding client setters rather than manually attaching these headers.

Learn more in the [user impersonation docs](/docs/products/auth/impersonation).

# Files {% #files %}

Appwrite implements resumable, chunked uploads for files larger than 5MB. Each chunk is up to **5MB**, which reduces memory footprint and increases resilience when handling large files. [Appwrite SDKs](/docs/sdks) split uploads, attach the headers below, and on runtimes with native concurrency they may also **send multiple chunk requests in parallel** for higher throughput, while your high-level upload code stays unchanged. You can still implement chunked uploads with the REST API directly.

Upload endpoints in Appwrite, such as [Create File](/docs/references/cloud/client-web/storage#createFile) and [Create Deployment](/docs/references/cloud/server-nodejs/functions#createDeployment), are different from other endpoints. These endpoints take multipart form data instead of JSON data. To implement chunked uploads over REST, send **one HTTP request per chunk** using the headers in the tables below. The **first** request establishes the file; every later chunk must repeat the same file **id** in `X-Appwrite-ID` and set `Content-Range` to the byte span carried in that request. After the first response, you may **issue the remaining chunk requests sequentially or in parallel** (for example from a thread pool or async tasks), as long as each byte of the file is uploaded **exactly once** and each part stays within the maximum chunk size. For Storage-specific guidance (including SDK behavior), see [Upload and download](/docs/products/storage/upload-download#large-files).

{% table %}

- Header
-
- Description

---

- X-Appwrite-Project: [PROJECT-ID]
- required
- The ID of your Appwrite project

---

- Content-Type: multipart/form-data; boundary=[FORM-BOUNDARY]
- required
- Contains the content type of the HTTP request and provides a [boundary](https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/POST) that is used to parse the form data.

---

- Content-Range: bytes [BYTE-RANGE]
- required
- Contains information about which bytes are being transmitted in this chunk, with the format `[FIRST-BYTE]-[LAST-BYTE]/[TOTAL-BYTES]`.

---

- X-Appwrite-ID: [FILE-ID]
- required
- Contains ID of the file this chunk belongs to.

---

- X-Appwrite-Key: [API-KEY]
- optional
- API key used for server authentication. Your API key is a secret, **do not** use it in client applications.
  
{% /table %}

The multipart form data is structured as follows:

{% table %}

- Key
-
- Value
- File Name
- Description

---

- fileId
- optional
- [FILE-ID]
- N/A
- Contains the file ID of the new file. Only used by file chunks following the first chunk uploaded.

---

- file
- required
- [CHUNK-DATA]
- [FILE-NAME]
- Contains file chunk data.

---

- permissions
- required
- [PERMISSION ARRAY]
- N/A
- Contains an array of permission strings about who can access the new file.
  
{% /table %}

While cURL and fetch are great tools to explore other REST endpoints, it's impractical to use for chunked file uploads because you need to split files into chunks.

The multipart form data posted to file upload endpoints have the following format:

```json
POST /v1/storage/buckets/default/files HTTP/1.1
Content-Type: multipart/form-data; boundary=----WebKitFormBoundarye0m6iNBQNHlzTpVM
X-Appwrite-Project: demo-project
Content-Range: bytes 10485760-12582912/12582912
X-Appwrite-ID: 6369b0bc1dcf4ff59051

------WebKitFormBoundarye0m6iNBQNHlzTpVM
Content-Disposition: form-data; name="fileId"

unique()
------WebKitFormBoundarye0m6iNBQNHlzTpVM
Content-Disposition: form-data; name="file"; filename="file.txt"
Content-Type: application/octet-stream

[CHUNKED-DATA]
------WebKitFormBoundarye0m6iNBQNHlzTpVM
Content-Disposition: form-data; name="permissions[]"

read("user:627a958ded6424a98a9f")
------WebKitFormBoundarye0m6iNBQNHlzTpVM--
```

# Images {% #images %}

Some use cases do not allow custom headers, such as embedding images from Appwrite in HTML. In these cases, you can provide the Appwrite project ID using the query parameter project.

```HTML
<img src="[ENDPOINT]/v1/storage/buckets/[BUCKET_ID]/files/[FILE_ID]/preview?project=<PROJECT_ID>"/>
```

# Permissions {% #permissions %}

Appwrite SDKs have helpers to generate permission string formats, but when using Appwrite without SDKs, you'd need to create the strings yourself.

{% table %}

- Query method
- API string

---

- `Permission.read()`
- `read("<PERMISSION_ROLE>")`

---

- `Permission.create()`
- `read("<PERMISSION_ROLE>")`

---

- `Permission.update()`
- `update("<PERMISSION_ROLE>")`

---

- `Permission.delete()`
- `delete("<PERMISSION_ROLE>")`

---

- `Permission.write()`
- `write("<PERMISSION_ROLE>")`
  
{% /table %}

## Roles {% #roles %}

Appwrite SDKs have helpers to generate roles string formats, but when using Appwrite without SDKs, you'd need to create the strings yourself.

{% table %}

- Role method
- API string

---

- `Role.any()`
- `any`

---

- `Role.guests()`
- `guests`

---

- `Role.users()`
- `users`

---

- `Role.users([STATUS])`
- `users/[STATUS]`

---

- `Role.user([USER_ID])`
- `user:[USER_ID]`

---

- `Role.user([USER_ID], [STATUS])`
- `user:[USER_ID]/[STATUS]`

---

- `Role.team([TEAM_ID])`
- `team:[TEAM_ID]`

---

- `Role.team([TEAM_ID], [ROLE])`
- `team:[TEAM_ID]/[ROLE]`

---

- `Role.member([MEMBERSHIP_ID])`
- `member:[MEMBERSHIP_ID]`
  
{% /table %}

# Unique ID {% #unique-id %}

Appwrite's SDKs have a helper `ID.unique()` to generate unique IDs. When using Appwrite without an SDK, pass the string `"unique()"` into the ID parameter.

# Queries {% #queries %}

Appwrite's SDKs provide a `Query` class to generate JSON query strings.
When using Appwrite without an SDK, you can template your own JSON strings.
You can discover the query methods available in the [Queries page.](/docs/products/databases/queries)

## Query string format {% #queries-string-format %}

Appwrite Queries are escaped JSON strings, which look like this.

```json
"{\"method\":\"equal\",\"column\":\"name\",\"values\":[\"John\"]}"
```

Query strings are passed to Appwrite using the `queries` parameter.
You can attach multiple query strings by including the array parameter multiple times in the query string: `queries[]="..."&queries[]="..."`

For example, the unescaped query string might look like this.

```text
?queries[0]={"method":"equal","column":"name","values":["John"]}&queries[1]={"method":"limit","values":[6]}
```

The JSON has a general format like this.

```json
{
  "method": "<QUERY_METHOD>",
  "column": "<ATTRIBUTE>",
  "values": [
    <VALUE1>,
    <VALUE2>,
    ...
  ]
}
```

{% info title="Best practice" %}
When using greater than, greater than or equal to, less than, or less than or equal to, it is not recommended to pass in multiple values.
While the API will accept multiple values and return results with **or logic**, it's best practice to pass in only one value for performance reasons.
{% /info %}

For example, to query for all rows with the name "John" or "Jane", the query string would look like this.

```json
{
    "method": "equal",
    "column": "name",
    "values": ["John", "Jane"]
}
```

Here are some more examples of the JSON query format.
When in doubt, you can use the Appwrite SDKs to generate the query strings for you.

```json
{
  "method": "isNull",
  "column": "name"
}
{
  "method": "select",
  "values": ["name", "age", "email"]
}
{
  "method": "between",
  "column": "age",
  "values": [18, 30]
}
{
  "method": "cursorAfter",
  "values": ["rowId"]
}
```

## Query nesting {% #query-nesting %}

Some Appwrite query methods, like `and` and `or`, allow you to nest queries.
When using Appwrite without an SDK, you can template your own JSON strings.

In these cases, `column` is empty and `values` is an array of queries.

```json
{
    "method": "and",
    "values": [
        {
            "method": "equal",
            "column": "name",
            "values": ["John"]
        },
        {
            "method": "between",
            "column": "age",
            "values": [20, 30]
        }
    ]
}
```

# Rate limits {% #rate-limits %}

Appwrite's REST APIs are protected by the same rate limit policies, just like when using an SDK. Each API has a different rate limit, which is documented in the References section of each service in the Appwrite documentation.

[Learn more about Rate Limits](/docs/advanced/security/rate-limits).

# Specifications {% #specifications %}

Appwrite provides a full REST API specification in the OpenAPI 3 and Swagger 2 formats every release. These can be accessed through Appwrite's GitHub repository and rendered using a variety of parsers and tools.

[Find the REST API specification for your Appwrite version](https://github.com/appwrite/appwrite/tree/master/app/config/specs).
