# Modules AGENTS.md

> Before reading this file, also read Appwrite's base [AGENTS.md](../../../AGENTS.md).

Modules are the building blocks of the Appwrite platform. They handle a specific domain: HTTP endpoints, optional background workers, and (rarely) CLI tasks. Each module lives under `src/Appwrite/Platform/Modules`.

Generally each service is its own module, with some exceptions. Put related code that achieves one goal under one roof. Register every new module in `src/Appwrite/Platform/Appwrite.php`.

## Structure and naming

Directory names are PascalCase; prefer one word (`Users`, `Databases`, `Storage`). Avoid shorthands unless they are standardized (`JWT`, `SMTP`).

A module consists of:

- `Module.php` -- registers the module's services from `Services/`
- `Http/` -- HTTP endpoints
- `Services/` -- register classes: `Http.php`, and optionally `Workers.php` / `Tasks.php`
- `Workers/` -- optional module-specific workers
- `Tasks/` -- optional module-specific CLI tasks (most CLI tasks live in `src/Appwrite/Platform/Tasks/` instead)

### HTTP directory structure

1. Directly under `Http/` there should only be directories for services (and hooks, see 2). A single-service module may use one directory named after the service, e.g. `Modules/Account/Http/Account`. A multi-service module uses one directory per service, e.g. `Modules/Databases/Http/Databases` and `Modules/Databases/Http/TablesDB`.

2. Hooks live in `Http/Hooks/{Init,Shutdown,Error}/`. Example: `Modules/Functions/Http/Hooks/Init/Authentication.php`.

3. Inside a service's `Http/` tree, file names can only be `Get.php`, `Create.php`, `Update.php`, `Delete.php`, or `XList.php` (`List` is reserved). Never any other action file. To "block" a user, update a property: `Users/Status/Update.php` → `PATCH /v1/users/:userId/status`.

4. Nest resources and properties as directories. Top-level resources in the same module are **siblings**, not nested under the parent resource folder. Template deployments live at `Modules/Functions/Http/Deployments/Template/Create.php` (`Deployments/` is a sibling of `Functions/`; `template` is a property).

### Sample module directory structure

```
src/Appwrite/Platform/Modules/Functions
├── Module.php
├── Workers
│   └── Builds.php
├── Http
│   ├── Functions
│   │   ├── Create.php
│   │   ├── XList.php
│   │   ├── Update.php
│   │   ├── Delete.php
│   │   └── Get.php
│   └── Deployments
│       ├── XList.php
│       ├── Delete.php
│       ├── Get.php
│       └── Template
│           └── Create.php
└── Services
    ├── Http.php
    └── Workers.php
```
