# Apps API specifications

Reference extracted from `@appwrite.io/console` v16.0.0 and `@appwrite.io/specs` (latest console OpenAPI).

All paths are relative to the API endpoint (`{endpoint}/v1/...`). Console-scoped calls use the console client session; project-scoped calls require `X-Appwrite-Project` and a session or API key.

Parameter descriptions come from the Console SDK type definitions. Path placeholders such as `{databaseId}` are substituted in the URL, not passed in the JSON body unless listed below.

<a id="appsservice"></a>

SDK accessors: `sdk.forConsole.apps` (organization / console) and `sdk.forProject(projectId).apps` (project OAuth2 server apps)

Base path prefix: `/v1/apps`

| SDK method | HTTP | Path | Returns |
| --- | --- | --- | --- |
| [`create`](#apps-create) | POST | `/v1/apps` | `Promise<Models.App>` |
| [`createInstallationToken`](#apps-createinstallationtoken) | POST | `/v1/apps/{appId}/installations/{installationId}/tokens` | `Promise<Models.Oauth2Token>` |
| [`createKey`](#apps-createkey) | POST | `/v1/apps/{appId}/keys` | `Promise<Models.AppKey>` |
| [`createSecret`](#apps-createsecret) | POST | `/v1/apps/{appId}/secrets` | `Promise<Models.AppSecretPlaintext>` |
| [`delete`](#apps-delete) | DELETE | `/v1/apps/{appId}` | `Promise<{}>` |
| [`deleteInstallation`](#apps-deleteinstallation) | DELETE | `/v1/apps/{appId}/installations/{installationId}` | `Promise<{}>` |
| [`deleteKey`](#apps-deletekey) | DELETE | `/v1/apps/{appId}/keys/{keyId}` | `Promise<{}>` |
| [`deleteSecret`](#apps-deletesecret) | DELETE | `/v1/apps/{appId}/secrets/{secretId}` | `Promise<{}>` |
| [`deleteTokens`](#apps-deletetokens) | DELETE | `/v1/apps/{appId}/tokens` | `Promise<{}>` |
| [`get`](#apps-get) | GET | `/v1/apps/{appId}` | `Promise<Models.App>` |
| [`getInstallation`](#apps-getinstallation) | GET | `/v1/apps/{appId}/installations/{installationId}` | `Promise<Models.AppInstallation>` |
| [`getKey`](#apps-getkey) | GET | `/v1/apps/{appId}/keys/{keyId}` | `Promise<Models.AppKey>` |
| [`getSecret`](#apps-getsecret) | GET | `/v1/apps/{appId}/secrets/{secretId}` | `Promise<Models.AppSecret>` |
| [`list`](#apps-list) | GET | `/v1/apps` | `Promise<Models.AppsList>` |
| [`listInstallations`](#apps-listinstallations) | GET | `/v1/apps/{appId}/installations` | `Promise<Models.AppInstallationList>` |
| [`listInstallationScopes`](#apps-listinstallationscopes) | GET | `/v1/apps/scopes/installations` | `Promise<Models.AppScopeList>` |
| [`listKeys`](#apps-listkeys) | GET | `/v1/apps/{appId}/keys` | `Promise<Models.AppKeyList>` |
| [`listOAuth2Scopes`](#apps-listoauth2scopes) | GET | `/v1/apps/scopes/oauth2` | `Promise<Models.AppScopeList>` |
| [`listSecrets`](#apps-listsecrets) | GET | `/v1/apps/{appId}/secrets` | `Promise<Models.AppSecretList>` |
| [`update`](#apps-update) | PUT | `/v1/apps/{appId}` | `Promise<Models.App>` |
| [`updateLabels`](#apps-updatelabels) | PUT | `/v1/apps/{appId}/labels` | `Promise<Models.App>` |
| [`updateTeam`](#apps-updateteam) | PATCH | `/v1/apps/{appId}/team` | `Promise<Models.App>` |

## Method details

<a id="apps-root-resource"></a>

### Apps

REST resource: `/v1/apps`

<a id="apps-create"></a>

#### `create`

Create a new application.

- **HTTP:** `POST`
- **Path:** `/v1/apps`
- **Returns:** `Promise<Models.App>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `appId` | `string` | Yes | Application ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char. Max length is 36 chars. |
| `name` | `string` | Yes | Application name. |
| `redirectUris` | `string[]` | Yes | Redirect URIs. Each must be an https URL, an http loopback URL (localhost, 127.0.0.1, [::1]), or a private-use scheme URI (e.g. com.example.app:/oauth), and must not contain a fragment. |
| `description` | `string` | No | Application description shown to users during OAuth2 consent. |
| `clientUri` | `string` | No | Application homepage URL shown to users during OAuth2 consent. |
| `logoUri` | `string` | No | Application logo URL shown to users during OAuth2 consent. |
| `privacyPolicyUrl` | `string` | No | Application privacy policy URL shown to users during OAuth2 consent. |
| `termsUrl` | `string` | No | Application terms of service URL shown to users during OAuth2 consent. |
| `contacts` | `string[]` | No | Application support or security contact emails. Maximum of 100 contacts are allowed. |
| `tagline` | `string` | No | Application tagline shown to users during OAuth2 consent. |
| `tags` | `string[]` | No | Application tags shown to users during OAuth2 consent. Maximum of 100 tags are allowed, each up to 64 characters long. |
| `images` | `string[]` | No | Application image URLs shown to users during OAuth2 consent. Maximum of 100 images are allowed. |
| `supportUrl` | `string` | No | Application support URL shown to users during OAuth2 consent. |
| `dataDeletionUrl` | `string` | No | Application data deletion URL shown to users during OAuth2 consent. |
| `postLogoutRedirectUris` | `string[]` | No | Post-logout redirect URIs for OpenID Connect RP-Initiated Logout. Each must be an https URL, an http loopback URL, or a private-use scheme URI, and must not contain a fragment. After ending the user session, the logout endpoint only redirects to URIs in this list. |
| `enabled` | `boolean` | No | Is application enabled? |
| `type` | `string` | No | OAuth2 client type. Use `public` for SPAs, mobile, and native apps that cannot keep a `client_secret` — PKCE is then required at the token endpoint. Use `confidential` for server-side clients that present a `client_secret`. Defaults to `confidential`. |
| `deviceFlow` | `boolean` | No | Allow this client to use the OAuth2 Device Authorization Grant (RFC 8628) for input-constrained devices such as TVs and CLIs. Defaults to false. |
| `teamId` | `string` | No | Team unique ID. |

**SDK signature**

```typescript
sdk.forConsole.apps.create({
  appId: string;
  name: string;
  redirectUris: string[];
  description?: string;
  clientUri?: string;
  logoUri?: string;
  privacyPolicyUrl?: string;
  termsUrl?: string;
  contacts?: string[];
  tagline?: string;
  tags?: string[];
  images?: string[];
  supportUrl?: string;
  dataDeletionUrl?: string;
  postLogoutRedirectUris?: string[];
  enabled?: boolean;
  type?: string;
  deviceFlow?: boolean;
  teamId?: string;
})

sdk.forProject(projectId).apps.create({
  appId: string;
  name: string;
  redirectUris: string[];
  description?: string;
  clientUri?: string;
  logoUri?: string;
  privacyPolicyUrl?: string;
  termsUrl?: string;
  contacts?: string[];
  tagline?: string;
  tags?: string[];
  images?: string[];
  supportUrl?: string;
  dataDeletionUrl?: string;
  postLogoutRedirectUris?: string[];
  enabled?: boolean;
  type?: string;
  deviceFlow?: boolean;
  teamId?: string;
})
```

<a id="apps-list"></a>

#### `list`

List applications.

- **HTTP:** `GET`
- **Path:** `/v1/apps`
- **Returns:** `Promise<Models.AppsList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. |
| `total` | `boolean` | No | When set to false, the total count returned will be 0 and will not be calculated. |

**SDK signature**

```typescript
sdk.forConsole.apps.list({
  queries?: string[];
  total?: boolean;
})

sdk.forProject(projectId).apps.list({
  queries?: string[];
  total?: boolean;
})
```

<a id="apps-scopes-resource"></a>

### Scopes

REST resource: `/v1/apps/scopes/…`

<a id="apps-listinstallationscopes"></a>

#### `listInstallationScopes`

List scopes an application can request when installed on a team.

- **HTTP:** `GET`
- **Path:** `/v1/apps/scopes/installations`
- **Returns:** `Promise<Models.AppScopeList>`

**Parameters**

_No request parameters._

**SDK signature**

```typescript
sdk.forConsole.apps.listInstallationScopes()

sdk.forProject(projectId).apps.listInstallationScopes()
```

<a id="apps-listoauth2scopes"></a>

#### `listOAuth2Scopes`

List scopes an application can request during the OAuth2 flow.

- **HTTP:** `GET`
- **Path:** `/v1/apps/scopes/oauth2`
- **Returns:** `Promise<Models.AppScopeList>`

**Parameters**

_No request parameters._

**SDK signature**

```typescript
sdk.forConsole.apps.listOAuth2Scopes()

sdk.forProject(projectId).apps.listOAuth2Scopes()
```

<a id="apps-appid-resource"></a>

### App Id

REST resource: `/v1/apps/{appId}/…`

<a id="apps-delete"></a>

#### `delete`

Delete an application by its unique ID.

- **HTTP:** `DELETE`
- **Path:** `/v1/apps/{appId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `appId` | `string` | Yes | Application unique ID. |

**SDK signature**

```typescript
sdk.forConsole.apps.delete({
  appId: string;
})

sdk.forProject(projectId).apps.delete({
  appId: string;
})
```

<a id="apps-get"></a>

#### `get`

Get an application by its unique ID.

- **HTTP:** `GET`
- **Path:** `/v1/apps/{appId}`
- **Returns:** `Promise<Models.App>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `appId` | `string` | Yes | Application unique ID. |

**SDK signature**

```typescript
sdk.forConsole.apps.get({
  appId: string;
})

sdk.forProject(projectId).apps.get({
  appId: string;
})
```

<a id="apps-update"></a>

#### `update`

Update an application by its unique ID.

- **HTTP:** `PUT`
- **Path:** `/v1/apps/{appId}`
- **Returns:** `Promise<Models.App>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `appId` | `string` | Yes | Application unique ID. |
| `name` | `string` | Yes | Application name. |
| `description` | `string` | No | Application description shown to users during OAuth2 consent. |
| `clientUri` | `string` | No | Application homepage URL shown to users during OAuth2 consent. |
| `logoUri` | `string` | No | Application logo URL shown to users during OAuth2 consent. |
| `privacyPolicyUrl` | `string` | No | Application privacy policy URL shown to users during OAuth2 consent. |
| `termsUrl` | `string` | No | Application terms of service URL shown to users during OAuth2 consent. |
| `contacts` | `string[]` | No | Application support or security contact emails. Maximum of 100 contacts are allowed. |
| `tagline` | `string` | No | Application tagline shown to users during OAuth2 consent. |
| `tags` | `string[]` | No | Application tags shown to users during OAuth2 consent. Maximum of 100 tags are allowed, each up to 64 characters long. |
| `images` | `string[]` | No | Application image URLs shown to users during OAuth2 consent. Maximum of 100 images are allowed. |
| `supportUrl` | `string` | No | Application support URL shown to users during OAuth2 consent. |
| `dataDeletionUrl` | `string` | No | Application data deletion URL shown to users during OAuth2 consent. |
| `enabled` | `boolean` | No | Is application enabled? |
| `redirectUris` | `string[]` | No | Redirect URIs. Each must be an https URL, an http loopback URL (localhost, 127.0.0.1, [::1]), or a private-use scheme URI (e.g. com.example.app:/oauth), and must not contain a fragment. |
| `postLogoutRedirectUris` | `string[]` | No | Post-logout redirect URIs for OpenID Connect RP-Initiated Logout. Each must be an https URL, an http loopback URL, or a private-use scheme URI, and must not contain a fragment. After ending the user session, the logout endpoint only redirects to URIs in this list. |
| `type` | `string` | No | OAuth2 client type. Use `public` for SPAs, mobile, and native apps that cannot keep a `client_secret` — PKCE is then required at the token endpoint. Use `confidential` for server-side clients that present a `client_secret`. Defaults to `confidential`. |
| `deviceFlow` | `boolean` | No | Allow this client to use the OAuth2 Device Authorization Grant (RFC 8628) for input-constrained devices such as TVs and CLIs. Defaults to false. |
| `installationScopes` | `string[]` | No | Scopes the application requests when installed on a team. Only scopes allowed by the project's OAuth2 server installation scopes configuration are accepted; use the list installation scopes endpoint to discover available values. Maximum of 100 scopes are allowed. |
| `installationRedirectUrl` | `string` | No | URL users are redirected to after creating or updating an installation of this application. Must be an https URL, an http loopback URL (localhost, 127.0.0.1, [::1]), or a private-use scheme URI, and must not contain a fragment. Leave empty for no redirect. |

**SDK signature**

```typescript
sdk.forConsole.apps.update({
  appId: string;
  name: string;
  description?: string;
  clientUri?: string;
  logoUri?: string;
  privacyPolicyUrl?: string;
  termsUrl?: string;
  contacts?: string[];
  tagline?: string;
  tags?: string[];
  images?: string[];
  supportUrl?: string;
  dataDeletionUrl?: string;
  enabled?: boolean;
  redirectUris?: string[];
  postLogoutRedirectUris?: string[];
  type?: string;
  deviceFlow?: boolean;
  installationScopes?: string[];
  installationRedirectUrl?: string;
})

sdk.forProject(projectId).apps.update({
  appId: string;
  name: string;
  description?: string;
  clientUri?: string;
  logoUri?: string;
  privacyPolicyUrl?: string;
  termsUrl?: string;
  contacts?: string[];
  tagline?: string;
  tags?: string[];
  images?: string[];
  supportUrl?: string;
  dataDeletionUrl?: string;
  enabled?: boolean;
  redirectUris?: string[];
  postLogoutRedirectUris?: string[];
  type?: string;
  deviceFlow?: boolean;
  installationScopes?: string[];
  installationRedirectUrl?: string;
})
```

<a id="apps-installations-resource"></a>

### Installations

REST resource: `/v1/apps/{appId}/…`

<a id="apps-createinstallationtoken"></a>

#### `createInstallationToken`

Create a token for an installation of an application. Requires an app key sent in the `X-Appwrite-Key` header alongside the `X-Appwrite-App` header, or a caller with update access to the app. The returned token carries the scopes and authorization details granted to the installation, and can be used as an `Authorization: Bearer` header everywhere OAuth2 access tokens are accepted. Multiple tokens can be active for the same installation at once; each token stays valid until it expires or the installation is updated or deleted.

- **HTTP:** `POST`
- **Path:** `/v1/apps/{appId}/installations/{installationId}/tokens`
- **Returns:** `Promise<Models.Oauth2Token>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `appId` | `string` | Yes | Application unique ID. |
| `installationId` | `string` | Yes | Installation unique ID. |

**SDK signature**

```typescript
sdk.forConsole.apps.createInstallationToken({
  appId: string;
  installationId: string;
})

sdk.forProject(projectId).apps.createInstallationToken({
  appId: string;
  installationId: string;
})
```

<a id="apps-deleteinstallation"></a>

#### `deleteInstallation`

Delete an installation of an application by its unique ID. Requires a caller with update access to the app. Previously issued installation access tokens are revoked.

- **HTTP:** `DELETE`
- **Path:** `/v1/apps/{appId}/installations/{installationId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `appId` | `string` | Yes | Application unique ID. |
| `installationId` | `string` | Yes | Installation unique ID. |

**SDK signature**

```typescript
sdk.forConsole.apps.deleteInstallation({
  appId: string;
  installationId: string;
})

sdk.forProject(projectId).apps.deleteInstallation({
  appId: string;
  installationId: string;
})
```

<a id="apps-getinstallation"></a>

#### `getInstallation`

Get an installation of an application by its unique ID. Requires an app key sent in the `X-Appwrite-Key` header alongside the `X-Appwrite-App` header, or a caller with update access to the app.

- **HTTP:** `GET`
- **Path:** `/v1/apps/{appId}/installations/{installationId}`
- **Returns:** `Promise<Models.AppInstallation>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `appId` | `string` | Yes | Application unique ID. |
| `installationId` | `string` | Yes | Installation unique ID. |

**SDK signature**

```typescript
sdk.forConsole.apps.getInstallation({
  appId: string;
  installationId: string;
})

sdk.forProject(projectId).apps.getInstallation({
  appId: string;
  installationId: string;
})
```

<a id="apps-listinstallations"></a>

#### `listInstallations`

List installations of an application. Requires an app key sent in the `X-Appwrite-Key` header alongside the `X-Appwrite-App` header, or a caller with update access to the app.

- **HTTP:** `GET`
- **Path:** `/v1/apps/{appId}/installations`
- **Returns:** `Promise<Models.AppInstallationList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `appId` | `string` | Yes | Application unique ID. |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. |
| `total` | `boolean` | No | When set to false, the total count returned will be 0 and will not be calculated. |

**SDK signature**

```typescript
sdk.forConsole.apps.listInstallations({
  appId: string;
  queries?: string[];
  total?: boolean;
})

sdk.forProject(projectId).apps.listInstallations({
  appId: string;
  queries?: string[];
  total?: boolean;
})
```

<a id="apps-keys-resource"></a>

### Keys

REST resource: `/v1/apps/{appId}/…`

<a id="apps-createkey"></a>

#### `createKey`

Create a new app key for an application. App keys carry no scopes; send one in the `X-Appwrite-Key` header alongside the `X-Appwrite-App` header to list the application's installations and create installation access tokens.

- **HTTP:** `POST`
- **Path:** `/v1/apps/{appId}/keys`
- **Returns:** `Promise<Models.AppKey>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `appId` | `string` | Yes | Application unique ID. |

**SDK signature**

```typescript
sdk.forConsole.apps.createKey({
  appId: string;
})

sdk.forProject(projectId).apps.createKey({
  appId: string;
})
```

<a id="apps-deletekey"></a>

#### `deleteKey`

Delete an app key by its unique ID.

- **HTTP:** `DELETE`
- **Path:** `/v1/apps/{appId}/keys/{keyId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `appId` | `string` | Yes | Application unique ID. |
| `keyId` | `string` | Yes | App key unique ID. |

**SDK signature**

```typescript
sdk.forConsole.apps.deleteKey({
  appId: string;
  keyId: string;
})

sdk.forProject(projectId).apps.deleteKey({
  appId: string;
  keyId: string;
})
```

<a id="apps-getkey"></a>

#### `getKey`

Get an app key by its unique ID.

- **HTTP:** `GET`
- **Path:** `/v1/apps/{appId}/keys/{keyId}`
- **Returns:** `Promise<Models.AppKey>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `appId` | `string` | Yes | Application unique ID. |
| `keyId` | `string` | Yes | App key unique ID. |

**SDK signature**

```typescript
sdk.forConsole.apps.getKey({
  appId: string;
  keyId: string;
})

sdk.forProject(projectId).apps.getKey({
  appId: string;
  keyId: string;
})
```

<a id="apps-listkeys"></a>

#### `listKeys`

List app keys for an application.

- **HTTP:** `GET`
- **Path:** `/v1/apps/{appId}/keys`
- **Returns:** `Promise<Models.AppKeyList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `appId` | `string` | Yes | Application unique ID. |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. |
| `total` | `boolean` | No | When set to false, the total count returned will be 0 and will not be calculated. |

**SDK signature**

```typescript
sdk.forConsole.apps.listKeys({
  appId: string;
  queries?: string[];
  total?: boolean;
})

sdk.forProject(projectId).apps.listKeys({
  appId: string;
  queries?: string[];
  total?: boolean;
})
```

<a id="apps-labels-resource"></a>

### Labels

REST resource: `/v1/apps/{appId}/…`

<a id="apps-updatelabels"></a>

#### `updateLabels`

Update the labels of an application. Labels are read-only for clients; only a server SDK using a project API key can set them. Replaces the previous labels.

- **HTTP:** `PUT`
- **Path:** `/v1/apps/{appId}/labels`
- **Returns:** `Promise<Models.App>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `appId` | `string` | Yes | Application unique ID. |
| `labels` | `string[]` | Yes | Array of application labels. Replaces the previous labels. Maximum of 1000 labels are allowed, each up to 36 alphanumeric characters long. Reserved labels are rejected. |

**SDK signature**

```typescript
sdk.forConsole.apps.updateLabels({
  appId: string;
  labels: string[];
})

sdk.forProject(projectId).apps.updateLabels({
  appId: string;
  labels: string[];
})
```

<a id="apps-secrets-resource"></a>

### Secrets

REST resource: `/v1/apps/{appId}/…`

<a id="apps-createsecret"></a>

#### `createSecret`

Create a new client secret for an application.

- **HTTP:** `POST`
- **Path:** `/v1/apps/{appId}/secrets`
- **Returns:** `Promise<Models.AppSecretPlaintext>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `appId` | `string` | Yes | Application unique ID. |

**SDK signature**

```typescript
sdk.forConsole.apps.createSecret({
  appId: string;
})

sdk.forProject(projectId).apps.createSecret({
  appId: string;
})
```

<a id="apps-deletesecret"></a>

#### `deleteSecret`

Delete an application client secret by its unique ID.

- **HTTP:** `DELETE`
- **Path:** `/v1/apps/{appId}/secrets/{secretId}`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `appId` | `string` | Yes | Application unique ID. |
| `secretId` | `string` | Yes | Secret unique ID. |

**SDK signature**

```typescript
sdk.forConsole.apps.deleteSecret({
  appId: string;
  secretId: string;
})

sdk.forProject(projectId).apps.deleteSecret({
  appId: string;
  secretId: string;
})
```

<a id="apps-getsecret"></a>

#### `getSecret`

Get an application client secret by its unique ID.

- **HTTP:** `GET`
- **Path:** `/v1/apps/{appId}/secrets/{secretId}`
- **Returns:** `Promise<Models.AppSecret>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `appId` | `string` | Yes | Application unique ID. |
| `secretId` | `string` | Yes | Secret unique ID. |

**SDK signature**

```typescript
sdk.forConsole.apps.getSecret({
  appId: string;
  secretId: string;
})

sdk.forProject(projectId).apps.getSecret({
  appId: string;
  secretId: string;
})
```

<a id="apps-listsecrets"></a>

#### `listSecrets`

List client secrets for an application.

- **HTTP:** `GET`
- **Path:** `/v1/apps/{appId}/secrets`
- **Returns:** `Promise<Models.AppSecretList>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `appId` | `string` | Yes | Application unique ID. |
| `queries` | `string[]` | No | Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of 100 queries are allowed, each 4096 characters long. |
| `total` | `boolean` | No | When set to false, the total count returned will be 0 and will not be calculated. |

**SDK signature**

```typescript
sdk.forConsole.apps.listSecrets({
  appId: string;
  queries?: string[];
  total?: boolean;
})

sdk.forProject(projectId).apps.listSecrets({
  appId: string;
  queries?: string[];
  total?: boolean;
})
```

<a id="apps-team-resource"></a>

### Team

REST resource: `/v1/apps/{appId}/…`

<a id="apps-updateteam"></a>

#### `updateTeam`

Transfer an application to another team by its unique ID.

- **HTTP:** `PATCH`
- **Path:** `/v1/apps/{appId}/team`
- **Returns:** `Promise<Models.App>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `appId` | `string` | Yes | Application unique ID. |
| `teamId` | `string` | Yes | Team ID of the team to transfer application to. |

**SDK signature**

```typescript
sdk.forConsole.apps.updateTeam({
  appId: string;
  teamId: string;
})

sdk.forProject(projectId).apps.updateTeam({
  appId: string;
  teamId: string;
})
```

<a id="apps-tokens-resource"></a>

### Tokens

REST resource: `/v1/apps/{appId}/…`

<a id="apps-deletetokens"></a>

#### `deleteTokens`

Revoke all tokens for an application by its unique ID.

- **HTTP:** `DELETE`
- **Path:** `/v1/apps/{appId}/tokens`
- **Returns:** `Promise<{}>`

**Parameters**

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `appId` | `string` | Yes | Application unique ID. |

**SDK signature**

```typescript
sdk.forConsole.apps.deleteTokens({
  appId: string;
})

sdk.forProject(projectId).apps.deleteTokens({
  appId: string;
})
```
