# Modules AGENTS.md

> Before reading this file, also read Appwrite's base [AGENTS.md](../../../AGENTS.md).

Modules are the building blocks of the Appwrite platform. They are responsible for handling specific tasks and providing APIs for other modules to use. Each module should have its own directory within the `src/Appwrite/Platform` directory.

Generally-speaking, each service is its own module, but there are some exceptions. The goal is to always put related code that achieves a specific goal under one roof.

## Structure and Naming Conventions

When adding a module, always add a new directory under `src/Appwrite/Platform`. The directory name should be PascalCase, and if possible, use only one word. For example, `User`, `Database`, `Storage`, etc. Avoid using shorthands, unless they are standardized, such as `DB`, `JWT`, or `SMTP`.

A module consists of:

- `Module.php` - Simple register class registering all module's services (from `Services` directory)
- `Workers` directory - Contains behaviour for module-specific workers
- `Tasks` directory - Contains behaviour for module-specific CLI tasks
- `Http` directory - Contains HTTP endpoints for the module
- `Services` directory - Contains register classes for all relevant types of services

Inside module, the `Services` directory can contain:

- `Http.php` - Register HTTP endpoints and hooks from `Http` directory
- `Workers.php` - Register workers from `Workers` directory
- `Tasks.php` - Register CLI tasks from `Tasks` directory

> After implementing a module, make sure to register it in `src/Appwrite/Platform/Appwrite.php`.

### HTTP directory structure

Inside module's `Http` directory, there are multiple rules to follow:

1. Directly in `Http` directory, there should only be directories for services (and hooks, check point number 2). If a module is a single service, it's okay to only have one directory, with the same name as the service, for example `src/Appwrite/Platform/Account/Http/Account.php`. An example with multiple services is `src/Appwrite/Platform/Databases/Http/Databases` and `src/Appwrite/Platform/Databases/Http/TablesDB`.

2. Hooks should live in `Hooks` directory, under `Init`, `Shutdown`, or `Error` directories, inside `Http` directory. For example, an init hook to prevent unauthorized access might live in `src/Appwrite/Platform/Functions/Http/Hooks/Init/Authentication.php`.

3. Inside `Http` directories for services, file names can only be `Get.php`, `Update.php`, `Create.php`, `Delete.php` or `XList.php`. We call it `XList`, because `List` is a reserved keyword and PHP would not like that. Never use any other words! Let's say you want a method to be `blockUser`, tempting to add `Users/Block.php`, instead, think of the resource and property it affects. Better naming would be `Users/Status/Update.php` (update user's status). Doing so also nicely reflects in the HTTP endpoint, `PATCH /v1/users/:userId/status`.

4. It's allowed to nest directories in `Http` service directories. For example, if you want to create a new deployment for a function based on a template, an endpoint might live in `src/Appwrite/Platform/Functions/Http/Functions/Deployments/Template/Create.php`.

### Sample module directory structure

```
src/Appwrite/Platform/Functions
├── Module.php
├── Workers
│   └── Builds.php
├── Tasks
│   └── Block.php
├── Http
│   └── Functions
│       ├── Create.php
│       ├── XList.php
│       ├── Update.php
│       ├── Delete.php
│       ├── Get.php
│       └── Deployments
│           ├── XList.php
│           ├── Delete.php
│           ├── Get.php
│           └── Template
│               └── Create.php
└── Services
    ├── Http.php
    ├── Workers.php
    └── Tasks.php
```