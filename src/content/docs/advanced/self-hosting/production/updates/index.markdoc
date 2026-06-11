---
layout: article
title: Updates and migrations
description: Keep your self-hosted Appwrite instance up-to-date. Learn how to perform updates, manage versions, and ensure your self-hosted Appwrite stays current.
---

To upgrade your Appwrite server from an older version, you should use the Appwrite migration tool *after you have installed the new version*. The migration tool will adjust your Appwrite data to the new version's structure to make sure your Appwrite data is compatible with any internal changes.

You can upgrade to a newer patch version without running the migration unless the [release notes](https://github.com/appwrite/appwrite/releases) indicate a migration is required. For example, you can upgrade from [`1.6.0`](https://github.com/appwrite/appwrite/releases/tag/1.6.0) to [`1.6.1`](https://github.com/appwrite/appwrite/releases/tag/1.6.1) without running the migrate command, but upgrading from `1.6.0` to `1.6.2` or later will require the migrate command because [`1.6.2`](https://github.com/appwrite/appwrite/releases/tag/1.6.2) requires a migration.

If you're trying to migrate to a newer minor version, you should upgrade to each minor version's latest patch. For example, if you're upgrading from `1.5.1` to `1.7.4` you should upgrade to:

1. `1.5.11`
1. `1.6.2`
1. `1.7.4`

Before upgrading, be sure to:

1. [back up your server](/docs/advanced/self-hosting/production/backups) data before running the migration
1. review the [changelog](https://github.com/appwrite/appwrite/releases) for any breaking changes
1. test the migration process on a non-production instance to make sure your application is working well

# Installing the next version {% #install-next-version %}

The first step is to install the latest version of Appwrite. Head to the directory where you ran your previous Appwrite install command.

```text
parent_directory <= you run the command in this directory
└── appwrite
    └── docker-compose.yml
```

The parent directory is where you will find the appwrite directory, inside which there are `docker-compose.yml` and `.env` files.

{% info title="Parent directory naming" %}
Your Appwrite installation's parent directory name is expected to be `appwrite`. Changing the directory name will result in mismatched Docker project names.
{% /info %}

{% info title="Choose an image tag" %}
Replace `<APPWRITE_VERSION>` below with the specific Appwrite image tag you intend to run (for example, `1.7.4`). Avoid using `latest` in production.
{% /info %}

## Unix

```sh
docker run -it --rm \
    --publish 20080:20080 \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="upgrade" \
    appwrite/appwrite:<APPWRITE_VERSION>
```

## CMD

```cmd
docker run -it --rm ^
    --publish 20080:20080 ^
    --volume //var/run/docker.sock:/var/run/docker.sock ^
    --volume "%cd%"/appwrite:/usr/src/code/appwrite:rw ^
    --entrypoint="upgrade" ^
    appwrite/appwrite:<APPWRITE_VERSION>
```

## PowerShell

```powershell
docker run -it --rm `
    --publish 20080:20080 `
    --volume /var/run/docker.sock:/var/run/docker.sock `
    --volume ${pwd}/appwrite:/usr/src/code/appwrite:rw `
    --entrypoint="upgrade" `
    appwrite/appwrite:<APPWRITE_VERSION>
```

This will pull the `docker-compose.yml` for the selected version/tag and perform the upgrade steps.
Once the setup completes, verify that you have the latest version of Appwrite.

```sh
docker ps | grep appwrite/appwrite
```

Verify that the `STATUS` doesn't have any errors and all the `appwrite/appwrite` containers have the same version.

# Running the Migration {% #running-the-migration %}

We can now start the migration. Navigate to the `appwrite` directory where your `docker-compose.yml` is present and run the following command.

```sh
cd appwrite/
docker compose exec appwrite migrate
```

The data migration can take longer depending on the amount of data your Appwrite instance contains. The Appwrite migration command uses multi-threading to speed up the process, meaning that adding more CPU cores can help speed up the process.

Once the migration process has been completed successfully, you're all set to use the latest version of Appwrite!

