# Scope Flow (Unauth / Guest)

This document summarizes how Appwrite computes and validates the **API “scope”** for each HTTP request (and what it does *not* do for database permissions).

## 1. Endpoint “scope” gate (HTTP)

The per-request scope evaluation happens in `app/controllers/shared/api.php`.

### 1.1 Role selection
When the request has no authenticated user/session/API key, `$user->isEmpty()` is `true`, so Appwrite selects the **guests** role:

```php
$role = $user->isEmpty()
    ? Role::guests()->toString()
    : Role::users()->toString();
```

### 1.2 Default scopes for the role
Appwrite loads the default scope list from `app/config/roles.php`:

```php
$scopes = $roles[$role]['scopes'];
```

For `guests`, `documents.write` is included by default.

### 1.3 Validate the route’s declared scope
Each endpoint declares the required scope using `->label('scope', ...)`.
The request is allowed to proceed only if the endpoint scope is present in the role’s scope list:

```php
$allowed = (array) $route->getLabel('scope', 'none');
if (empty(array_intersect($allowed, $scopes))) {
    throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE, ...);
}
```

## 2. “Guest” userId: endpoint scope does not imply a DB user identity

The “guests” role is used for the HTTP endpoint scope gate.
Database permission decisions later depend on the *authorization roles* and the presence of a `userId`.

When there is no authenticated user, Appwrite may still create a “guest” `Document` for auditing purposes, but with an **empty** `'$id'`:

```php
$user = new User([
  '$id' => '',
  'type' => ACTIVITY_TYPE_GUEST,
  ...
]);
```

In the DocumentsDB `createDocument` handler, default per-user `$permissions` are only added when `!empty($user->getId())`:

```php
if (!empty($user->getId()) && !$isPrivilegedUser) {
    // add default 'read/update/delete' permissions for the current user
}
```

So even though the endpoint scope gate may succeed for guests, the database layer still requires that the **collection/database permissions** permit the create operation for the guest/authorization context.

## 3. After the scope gate: database create authorization

For `documentsdb` document creation, `src/Appwrite/Platform/Modules/Databases/Http/Databases/Collections/Documents/Create.php` checks:

1. The target database/collection exists and is enabled (unless API key/privileged).
2. For each document, the authorization validator must accept the operation:
   - `create` permission is validated against `$collection->getPermissionsByType(Database::PERMISSION_CREATE)`
   - `update` is validated against collection/document rules (and may depend on `documentSecurity`)

If the collection does not grant `create` to a role present in the request’s authorization context (e.g. `guests` / `any` / other configured roles), the request fails with `USER_UNAUTHORIZED`.

## 4. Bulk create extra restriction (unauth)

For bulk creates, there is an additional guard in the same DocumentsDB create handler:

```php
if ($isBulk && !$isAPIKey && !$isPrivilegedUser) {
    throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE);
}
```

So bulk create is further restricted beyond the endpoint scope gate.

## Where to look (quick pointers)

- HTTP scope gate: `app/controllers/shared/api.php`
- Default role->scope mapping: `app/config/roles.php`
- DocumentsDB create + DB permission checks: `src/Appwrite/Platform/Modules/Databases/Http/Databases/Collections/Documents/Create.php`

