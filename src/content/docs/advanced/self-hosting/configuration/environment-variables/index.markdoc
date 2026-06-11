---
layout: article
title: Environment variables
description: Customize the behavior of your self-hosted Appwrite instance to your unique needs. Customize SMTP, SMS, functions, S3 adaptor, database, and other behaiors.
---


Appwrite environment variables allow you to edit your server setup configuration and customize it. You can easily change the environment variables by changing them when running Appwrite using Docker CLI or Docker Compose.

Updating your Appwrite environment variables requires you to edit your Appwrite `.env` file. Your Docker files should be located inside the "appwrite" folder at the location where you first run the Appwrite installation script. It's recommended to use the `.env` file as a central point for updating your Appwrite configuration rather than changing them directly in your `docker-compose.yml` file.

After editing your `docker-compose.yml` or `.env` files, you will need to recreate your Appwrite stack by running the following compose command in your terminal:
```bash
docker compose up -d
```

You can verify if the changes have been successfully applied by running this command:
```bash
docker compose exec appwrite vars
```

# General {% #general %}
| Name | Description |
|------|-------------|
| `_APP_ENV` | Set your server running environment. By default, the var is set to 'development'. When deploying to production, change it to: 'production'. |
| `_APP_LOCALE` | Set your Appwrite's locale. By default, the locale is set to 'en'. |
| `_APP_OPTIONS_ABUSE` | Allows you to disable abuse checks and API rate limiting. By default, set to 'enabled'. To cancel the abuse checking, set to 'disabled'. It is not recommended to disable this check-in a production environment. |
| `_APP_OPTIONS_ROUTER_FORCE_HTTPS` | **version >= 1.7.0** Allows you to force HTTPS connection to function and site domains. This feature redirects any HTTP call to HTTPS and adds the `Strict-Transport-Security` header to all HTTP responses. By default, set to `enabled`. To disable, set to `disabled`. This feature will work only when your ports are set to default 80 and 443. |
| `_APP_OPTIONS_FORCE_HTTPS` | Deprecated since 1.7.0. Allows you to force HTTPS connection to your API. This feature redirects any HTTP call to HTTPS and adds the `Strict-Transport-Security` header to all HTTP responses. By default, set to `enabled`. To disable, set to `disabled`. This feature will work only when your ports are set to default 80 and 443. |
| `_APP_OPTIONS_FUNCTIONS_FORCE_HTTPS` | Deprecated since 1.7.0. Allows you to force HTTPS connection to function domains. This feature redirects any HTTP call to HTTPS and adds the `Strict-Transport-Security` header to all HTTP responses. By default, set to `enabled`. To disable, set to `disabled`. This feature will work only when your ports are set to default 80 and 443. |
| `_APP_OPENSSL_KEY_V1` | This is your server private secret key that is used to encrypt all sensitive data on your server. Appwrite server encrypts all secret data on your server like webhooks, HTTP passwords, user sessions, and storage files. The var is not set by default, if you wish to take advantage of Appwrite encryption capabilities you should change it and make sure to **keep it a secret and have a backup for it**. |
| `_APP_DOMAIN` | Your Appwrite domain address. When setting a public suffix domain, Appwrite will attempt to issue a valid SSL certificate automatically. When used with a dev domain, Appwrite will assign a self-signed SSL certificate. The default value is 'localhost'. |
| `_APP_DOMAIN_TARGET` | Deprecated since 1.7.0. A DNS A record hostname to serve as a CNAME target for your Appwrite custom domains. You can use the same value as used for the Appwrite `_APP_DOMAIN` variable. The default value is 'localhost'. |
| `_APP_DOMAIN_TARGET_CNAME` | **version >= 1.7.0** A domain that can be used as DNS CNAME record to point to instance of Appwrite server. The default value is 'localhost'. |
| `_APP_DOMAIN_TARGET_A` | **version >= 1.7.0** An IPV4 that can be used as DNS A record to point to instance of Appwrite server. The default value is '127.0.0.1'. |
| `_APP_DOMAIN_TARGET_AAAA` | **version >= 1.7.0** An IPv6 that can be used as DNS AAAA record to point to instance of Appwrite server. The default value is '::1'. |
| `_APP_CONSOLE_WHITELIST_ROOT`| This option allows you to disable the creation of new users on the Appwrite console. When enabled only 1 user will be able to use the registration form. New users can be added by inviting them to your project. By default this option is enabled. |
| `_APP_CONSOLE_WHITELIST_EMAILS` | This option allows you to limit creation of new users on the Appwrite console. This option is very useful for small teams or sole developers. To enable it, pass a list of allowed email addresses separated by a comma. |
| `_APP_CONSOLE_WHITELIST_IPS` | This last option allows you to limit creation of users in Appwrite console for users sharing the same set of IP addresses. This option is very useful for team working with a VPN service or a company IP. To enable/activate this option, pass a list of allowed IP addresses separated by a comma. |
| `_APP_SYSTEM_EMAIL_NAME`| This is the sender name value that will appear on email messages sent to developers from the Appwrite console. The default value is: 'Appwrite'. You can use url encoded strings for spaces and special chars. |
| `_APP_SYSTEM_EMAIL_ADDRESS`  | This is the sender email address that will appear on email messages sent to developers from the Appwrite console. The default value is 'team@appwrite.io'. You should choose an email address that is allowed to be used from your SMTP server to avoid the server email ending in the users' SPAM folders. |
| `_APP_SYSTEM_RESPONSE_FORMAT` | Use this environment variable to set the default Appwrite HTTP response format to support an older version of Appwrite. This option is useful to overcome breaking changes between versions. You can also use the `X-Appwrite-Response-Format` HTTP request header to overwrite the response for a specific request. This variable accepts any valid Appwrite version. To use the current version format, leave the value of the variable empty. |
| `_APP_SYSTEM_SECURITY_EMAIL_ADDRESS` | This is the email address used to issue SSL certificates for custom domains or the user agent in your webhooks payload. |
| `_APP_USAGE_STATS` | This variable allows you to disable the table and displaying of usage stats. This value is set to 'enabled' by default, to disable the usage stats set the value to 'disabled'. When disabled, it's recommended to turn off the Worker Usage container to reduce resource usage. |
| `_APP_LOGGING_PROVIDER` |  Deprecated since 1.6.0, use `_APP_LOGGING_CONFIG` with DSN value instead. This variable allows you to enable logging errors to 3rd party providers. This value is empty by default, set the value to one of `sentry`, `raygun`, `appSignal`, `logOwl` to enable the logger. |
| `_APP_LOGGING_CONFIG`  | This variable allows you to enable logging errors to third party providers. This value is empty by default, set a DSN value to one of the following `sentry://PROJECT_ID:SENTRY_API_KEY@SENTRY_HOST/`, , `logowl://SERVICE_TICKET@SERIVCE_HOST/` `raygun://RAYGUN_API_KEY/`, `appSignal://API_KEY/` to enable the logger. For versions prior `1.5.6` you can use the old syntax. Old syntax: If using Sentry, this should be `SENTRY_API_KEY;SENTRY_APP_ID`. If using Raygun, this should be Raygun API key. If using AppSignal, this should be AppSignal API key. If using LogOwl, this should be LogOwl Service Ticket. |
| `_APP_USAGE_AGGREGATION_INTERVAL` | **(version >= 1.1.0)** Interval value containing the number of seconds that the Appwrite usage process should wait before aggregating stats and syncing it to Database from TimeSeries data. The default value is 30 seconds. Reintroduced in 1.1.0. |
| `_APP_USAGE_TIMESERIES_INTERVAL` | **(version >= 1.0.0)** Deprecated since 1.1.0 uses `_APP_USAGE_AGGREGATION_INTERVAL` instead. |
| `_APP_USAGE_DATABASE_INTERVAL` |  **(version >= 1.0.0)** Deprecated since 1.1.0 uses `_APP_USAGE_AGGREGATION_INTERVAL` instead. |
| `_APP_WORKER_PER_CORE` |  **(version >= 0.13.0)** Internal Worker per core for the API, Realtime and Executor containers. Can be configured to optimize performance. |

# Redis {% #redis %}
Appwrite uses a Redis server for managing cache, queues and scheduled tasks. The Redis env vars are used to allow Appwrite server to connect to the Redis container.

| Name                     | Description                                                                                           |
|--------------------------|-------------------------------------------------------------------------------------------------------|
| `_APP_REDIS_HOST`          | Redis server hostname address. Default value is: `redis`.                                            |
| `_APP_REDIS_PORT`          | Redis server TCP port. Default value is: `6379`.                                                      |
| `_APP_REDIS_USER`          | Redis server user. This is an optional variable. Default value is an empty string.   |
| `_APP_REDIS_PASS`          | Redis server password. This is an optional variable. Default value is an empty string.|

# Database {% #database %}
Appwrite supports MariaDB and MongoDB as database backends. The database is selected during [installation](/docs/advanced/self-hosting/installation). See [Databases](/docs/advanced/self-hosting/configuration/databases) for more details.

| Name                   | Description                                                                                      |
|------------------------|--------------------------------------------------------------------------------------------------|
| `_APP_DB_ADAPTER`      | Database adapter type. Default value is: `mongodb`. Possible values: `mongodb`, `mariadb`.       |
| `_APP_DB_HOST`         | Database server host name address. Default value is: `mongodb` (or `mariadb` for MariaDB).       |
| `_APP_DB_PORT`         | Database server TCP port. Default value is: `27017` (or `3306` for MariaDB).                     |
| `_APP_DB_SCHEMA`       | Database name. Default value is: `appwrite`.                                                     |
| `_APP_DB_USER`         | Database server user name. Default value is: `user`.                                             |
| `_APP_DB_PASS`         | Database server user password. Default value is: `password`.                                     |
| `_APP_DB_ROOT_PASS`    | Database server root password. Default value is: `rootsecretpassword`.                           |

# SMTP {% #smtp %}

Appwrite is using an SMTP server for emailing your projects users and server admins. The SMTP env vars are used to allow Appwrite server to connect to the SMTP container.

If running in production, it might be easier to use a 3rd party SMTP server as it might be a little more difficult to set up a production SMTP server that will not send all your emails into your user's **spam folder**.

| Name                  | Description                                                                                                                                                      |
|-----------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `_APP_SMTP_HOST`      | SMTP server host name address. Use an empty string to disable all mail sending from the server. The default value for this variable is an empty string.         |
| `_APP_SMTP_PORT`      | SMTP server TCP port. Empty by default.                                                                                                                          |
| `_APP_SMTP_SECURE`    | SMTP secure connection protocol. Empty by default, change to 'tls' if running on a secure connection.                                                            |
| `_APP_SMTP_USERNAME`  | SMTP server user name. Empty by default.                                                                                                                         |
| `_APP_SMTP_PASSWORD`  | SMTP server user password. Empty by default.                                                                                                                     |

# Phone {% #phone %}

| Name                                        | Description                                                                                                                                                                                                                         |
|---------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `_APP_SMS_PROVIDER`  | **version >= 0.15.0** Provider used for delivering SMS for Phone authentication. Use the following format: `sms://<USER>:<SECRET>@<PROVIDER>`. Ensure `<USER>` and `<SECRET>` are URL encoded if they contain any non-alphanumeric characters. Available providers are twilio, text-magic, telesign, msg91, and vonage. |
| `_APP_SMS_FROM`  | **version >= 0.15.0** Phone number used for sending out messages. Must start with a leading '+' and maximum of 15 digits without spaces (+123456789).                                                                                                      |

# Storage {% #storage %}

| Name                                        | Description                                                                                                                                                                                                                         |
|---------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `_APP_STORAGE_LIMIT`                        | **version >= 0.7.0** Maximum file size allowed for file upload. The default value is 30MB. You should pass your size limit value in bytes.                                                                                           |
| `_APP_STORAGE_PREVIEW_LIMIT`                | **version >= 0.13.4** Maximum file size allowed for file image preview. The default value is 20MB. You should pass your size limit value in bytes.                                                                                  |
| `_APP_STORAGE_ANTIVIRUS`                    | This variable allows you to disable the internal anti-virus scans. This value is set to 'disabled' by default, to enable the scans set the value to 'enabled'. Before enabling, you must add the ClamAV service and depend on it on main Appwrite service. |
| `_APP_STORAGE_ANTIVIRUS_HOST`               | **version >= 0.7.0** ClamAV server host name address. Default value is: 'clamav'.                                                                                                                                                   |
| `_APP_STORAGE_ANTIVIRUS_PORT`               | **version >= 0.7.0** ClamAV server TCP port. Default value is: '3310'.                                                                                                                                                            |
| `_APP_STORAGE_DEVICE`                      | **version >= 0.13.0** Select default storage device. The default value is 'local'. List of supported adapters are 'local', 's3', 'dospaces', 'backblaze', 'linode' and 'wasabi'.                                                   |
| `_APP_STORAGE_S3_ACCESS_KEY`                | **version >= 0.13.0** AWS S3 storage access key. Required when the storage adapter is set to S3. You can get your access key from your AWS console.                                                                                |
| `_APP_STORAGE_S3_SECRET`                    | **version >= 0.13.0** AWS S3 storage secret key. Required when the storage adapter is set to S3. You can get your secret key from your AWS console.                                                                               |
| `_APP_STORAGE_S3_REGION`                    | **version >= 0.13.0** AWS S3 storage region. Required when storage adapter is set to S3. You can find your region info for your bucket from AWS console.                                                                          |
| `_APP_STORAGE_S3_BUCKET`                    | **version >= 0.13.0** AWS S3 storage bucket. Required when storage adapter is set to S3 and using path-style requests (where the bucket is in the path). You can create buckets in your AWS console. If using virtual-hosted-style paths where the bucket is in the endpoint URL, this should be empty.                         |
| `_APP_STORAGE_S3_ENDPOINT`                  | **version >= 1.7.0** Override the S3 endpoint to use an S3-compatible provider. This should just be the host (without 'https://'). If using virtual-hosted-style paths where the bucket is included in the endpoint (e.g., `bucket-name.s3.amazonaws.com`), `_APP_STORAGE_S3_BUCKET` should be empty. For path-style requests, the endpoint should not include the bucket name and `_APP_STORAGE_S3_BUCKET` should be set.      |
| `_APP_STORAGE_DO_SPACES_ACCESS_KEY`         | **version >= 0.13.0** DigitalOcean spaces access key. Required when the storage adapter is set to DOSpaces. You can get your access key from your DigitalOcean console.                                                            |
| `_APP_STORAGE_DO_SPACES_SECRET`             | **version >= 0.13.0** DigitalOcean spaces secret key. Required when the storage adapter is set to DOSpaces. You can get your secret key from your DigitalOcean console.                                                           |
| `_APP_STORAGE_DO_SPACES_REGION`             | **version >= 0.13.0** DigitalOcean spaces region. Required when storage adapter is set to DOSpaces. You can find your region info for your space from DigitalOcean console.                                                       |
| `_APP_STORAGE_DO_SPACES_BUCKET`             | **version >= 0.13.0** DigitalOcean spaces bucket. Required when storage adapter is set to DOSpaces. You can create spaces in your DigitalOcean console.                                                                            |
| `_APP_STORAGE_BACKBLAZE_ACCESS_KEY`         | **version >= 0.14.2** Backblaze access key. Required when the storage adapter is set to Backblaze. Your Backblaze keyID will be your access key. You can get your keyID from your Backblaze console.                             |
| `_APP_STORAGE_BACKBLAZE_SECRET`             | **version >= 0.14.2** Backblaze secret key. Required when the storage adapter is set to Backblaze. Your Backblaze applicationKey will be your secret key. You can get your applicationKey from your Backblaze console.             |
| `_APP_STORAGE_BACKBLAZE_REGION`             | **version >= 0.14.2** Backblaze region. Required when storage adapter is set to Backblaze. You can find your region info from your Backblaze console.                                                                             |
| `_APP_STORAGE_BACKBLAZE_BUCKET`             | **version >= 0.14.2** Backblaze bucket. Required when storage adapter is set to Backblaze. You can create your bucket from your Backblaze console.                                                                                |
| `_APP_STORAGE_LINODE_ACCESS_KEY`            | **version >= 0.14.2** Linode object storage access key. Required when the storage adapter is set to Linode. You can get your access key from your Linode console.                                                                  |
| `_APP_STORAGE_LINODE_SECRET`                | **version >= 0.14.2** Linode object storage secret key. Required when the storage adapter is set to Linode

# Compute (Functions and Sites) {% #compute %}
| **Name**                      | **Description** |
|-------------------------------|-----------------|
| `_APP_DOMAIN_FUNCTIONS` | A domain to use for function preview URLs. The default value is 'functions.localhost'. Setting to empty turns off function preview URLs. |
| `_APP_DOMAIN_SITES` | **version >= 1.7.0** The domain to use for site preview URLs. The default value is 'sites.localhost'. Setting to empty turns off site URLs. |
| `_APP_COMPUTE_SIZE_LIMIT`     | **version >= 1.7.0** The maximum size of a function and site deployments in bytes. The default value is 30MB. |
| `_APP_FUNCTIONS_SIZE_LIMIT`   | Deprecated since 1.7.0. The maximum size deployment in bytes. The default value is 30MB. |
| `_APP_FUNCTIONS_TIMEOUT`      | **version >= 0.7.0** The maximum number of seconds allowed as a timeout value when creating a new function. The default value is 900 seconds. This is the global limit, timeout for individual functions are configured in the function's settings or in appwrite.config.json. |
| `_APP_COMPUTE_BUILD_TIMEOUT`  | **version >= 1.7.0** The maximum number of seconds allowed as a timeout value when building a new function or site. The default value is 900 seconds. This is the global limit, timeout for individual functions and sites are configured in the function's or site's settings or in appwrite.config.json. |
| `_APP_FUNCTIONS_BUILD_TIMEOUT`| Deprecated since 1.7.0.  The maximum number of seconds allowed as a timeout value when building a new function. The default value is 900 seconds. |
| `_APP_FUNCTIONS_CONTAINERS`   | **version >= 0.7.0** Deprecated since 1.2.0. Runtimes now timeout by inactivity using `_APP_FUNCTIONS_INACTIVE_THRESHOLD`. |
| `_APP_COMPUTE_CPUS`           | **version >= 0.7.0** The maximum number of CPU core a single cloud function or site is allowed to use. Please note that setting a value higher than available cores will result in a function error, which might result in an error. The default value is empty. When it's empty, CPU limit will be disabled. |
| `_APP_FUNCTIONS_CPUS`         | **version >= 0.7.0** Deprecated since 1.7.0. The maximum number of CPU core a single cloud function is allowed to use. Please note that setting a value higher than available cores will result in a function or site error, which might result in an error. The default value is empty. When it's empty, CPU limit will be disabled. |
| `_APP_COMPUTE_MEMORY`         | **version >= 1.7.0** The maximum amount of memory a single function or site is allowed to use in megabytes. The default value is  empty. When it's empty, memory limit will be disabled. |
| `_APP_FUNCTIONS_MEMORY`       | **version >= 0.7.0** Deprecated since 1.7.0. The maximum amount of memory a single cloud function is allowed to use in megabytes. The default value is empty. When it's empty, memory limit will be disabled. |
| `_APP_FUNCTIONS_MEMORY_SWAP`  | **version >= 0.7.0** Deprecated since 1.2.0. High use of swap memory is not recommended to preserve harddrive health. |
| `_APP_FUNCTIONS_RUNTIMES`     | **version >= 0.8.0** This option allows you to enable or disable runtime environments for cloud functions. Disable unused runtimes to save disk space. To enable cloud function runtimes, pass a list of enabled environments separated by a comma. [Learn more about runtimes](/docs/products/functions/runtimes).|
| `_APP_EXECUTOR_SECRET`        | **version >= 0.13.0** The secret key used by Appwrite to communicate with the function executor. Make sure to change this! |
| `_APP_EXECUTOR_HOST`          | **version >= 0.13.0** The host used by Appwrite to communicate with the function executor! |
| `_APP_EXECUTOR_RUNTIME_NETWORK`| **version >= 0.13.0** Deprecated with 0.14.0, use `OPEN_RUNTIMES_NETWORK` instead! |
| `_APP_FUNCTIONS_ENVS`         | **version >= 0.7.0** Deprecated with 0.8.0, use `_APP_FUNCTIONS_RUNTIMES` instead! |
| `_APP_COMPUTE_INACTIVE_THRESHOLD`  | **version >= 1.7.0** The minimum time a function or site must be inactive before it can be shut down and cleaned up. This feature is intended to clean up unused containers. Containers may remain active for longer than the interval before being shut down, as Appwrite only cleans up unused containers every hour. If no value is provided, the default is 60 seconds. |
| `_APP_FUNCTIONS_INACTIVE_THRESHOLD`| **version >= 0.13.0** Deprecated since 1.7.0. The minimum time a function must be inactive before it can be shut down and cleaned up. This feature is intended to clean up unused containers. Containers may remain active for longer than the interval before being shut down, as Appwrite only cleans up unused containers every hour. If no value is provided, the default is 60 seconds. |
| `DOCKERHUB_PULL_USERNAME`     | **version >= 0.10.0** Deprecated with 1.2.0, use `_APP_DOCKER_HUB_USERNAME` instead! |
| `DOCKERHUB_PULL_PASSWORD`     | **version >= 0.10.0** Deprecated with 1.2.0, use `_APP_DOCKER_HUB_PASSWORD` instead! |
| `DOCKERHUB_PULL_EMAIL`        | **version >= 0.10.0** Deprecated since 1.2.0. Email is no longer needed. |
| `OPEN_RUNTIMES_NETWORK`       | **version >= 0.13.0** Deprecated with 1.2.0, use `_APP_FUNCTIONS_RUNTIMES_NETWORK` instead! |
| `_APP_COMPUTE_RUNTIMES_NETWORK`  | **version >= 1.7.0** The docker network used for communication between the executor and runtimes for sites and functions. |
| `_APP_FUNCTIONS_RUNTIMES_NETWORK`| **version >= 1.2.0** Deprecated since 1.7.0. The docker network used for communication between the executor and runtimes. |
| `_APP_DOCKER_HUB_USERNAME`    | **version >= 1.2.0** The username for hub.docker.com. This variable is used to pull images from hub.docker.com. |
| `_APP_DOCKER_HUB_PASSWORD`    | **version >= 1.2.0** The password for hub.docker.com. This variable is used to pull images from hub.docker.com. |
| `_APP_COMPUTE_MAINTENANCE_INTERVAL`  | **version >= 1.7.0** Interval value containing the number of seconds that the executor should wait before checking for inactive runtimes of functions and sites. The default value is 3600 seconds (1 hour). |
| `_APP_FUNCTIONS_MAINTENANCE_INTERVAL`| **version >= 1.4.0** Deprecated since 1.7.0. Interval value containing the number of seconds that the executor should wait before checking for inactive runtimes. The default value is 3600 seconds (1 hour). |
| `_APP_SITES_TIMEOUT` | **version >= 1.7.0** The maximum number of seconds allowed as a timeout value when creating a new site. The default value is 900 seconds. This is the global limit, timeout for individual functions are configured in the sites's settings or in appwrite.config.json. |
| `_APP_SITES_RUNTIMES` | **version >= 1.7.0** This option allows you to enable or disable runtime environments for Sites. Disable unused runtimes to save disk space. To enable cloud site runtimes, pass a list of enabled environments separated by a comma. [Learn more about runtimes](/docs/advanced/self-hosting/sites#sites) |

# VCS (Version Control System) {% #vcs %}

| **Name**                        | **Description**                                                                                                 |
|---------------------------------|-----------------------------------------------------------------------------------------------------------------|
| `_APP_VCS_GITHUB_APP_NAME`      | **version >= 1.4.0** - Name of your GitHub app. This value should be set to your GitHub application's URL.      |
| `_APP_VCS_GITHUB_PRIVATE_KEY`   | **version >= 1.4.0** - GitHub app RSA private key. You can generate private keys from GitHub application settings. |
| `_APP_VCS_GITHUB_APP_ID`        | **version >= 1.4.0** - GitHub application ID. You can find it in your GitHub application details.               |
| `_APP_VCS_GITHUB_CLIENT_ID`     | **version >= 1.4.0** - GitHub client ID. You can find it in your GitHub application details.                    |
| `_APP_VCS_GITHUB_CLIENT_SECRET` | **version >= 1.4.0** - GitHub client secret. You can generate secrets in your GitHub application settings.    |
| `_APP_VCS_GITHUB_WEBHOOK_SECRET`| **version >= 1.4.0** - GitHub webhook secret. You can configure it in your GitHub application settings under webhook section. |

# Maintenance {% #maintenance %}
| **Name**                                        | **Description**                                                                                                                                                                    |
|-------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `_APP_MAINTENANCE_INTERVAL`                     | **version >= 0.7.0** - Interval value containing the number of seconds that the Appwrite maintenance process should wait before executing system cleanups and optimizations. The default value is `86400` seconds (1 day). |
| `_APP_MAINTENANCE_RETENTION_CACHE`              | **version >= 1.0.0** - The maximum duration (in seconds) upto which to retain cached files. The default value is `2592000` seconds (30 days).                                           |
| `_APP_MAINTENANCE_RETENTION_EXECUTION`          | **version >= 0.7.0** - The maximum duration (in seconds) upto which to retain execution logs. The default value is `1209600` seconds (14 days).                                        |
| `_APP_MAINTENANCE_RETENTION_AUDIT`              | **version >= 0.7.0** - The maximum duration (in seconds) upto which to retain audit logs. The default value is `1209600` seconds (14 days).                                           |
| `_APP_MAINTENANCE_RETENTION_ABUSE`              | **version >= 0.7.0** - The maximum duration (in seconds) upto which to retain abuse logs. The default value is `86400` seconds (1 day).                                               |
| `_APP_MAINTENANCE_RETENTION_USAGE_HOURLY`       | The maximum duration (in seconds) upto which to retain hourly usage metrics. The default value is `8640000` seconds (100 days).                                                      |
| `_APP_MAINTENANCE_RETENTION_SCHEDULES`          | Schedules deletion interval (in seconds).                                                                                                                     |

# GraphQL {% #graphql %}

| **Name**                           | **Description**                                                                                                                                                     |
|------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `_APP_GRAPHQL_MAX_BATCH_SIZE`      | **version >= 1.2.0** - Maximum number of batched queries per request. The default value is 10.                                                                      |
| `_APP_GRAPHQL_MAX_COMPLEXITY`      | **version >= 1.2.0** - Maximum complexity of a GraphQL query. One field adds one to query complexity. Lists multiply the complexity by the number of items requested. The default value is 250. |
| `_APP_GRAPHQL_MAX_DEPTH`           | **version >= 1.2.0** - Maximum depth of a GraphQL query. One nested field level adds one to query depth. The default value is 3.                                    |

# Migrations {% #migrations %}
| **Name**                                        | **Description**                                                                                         |
|-------------------------------------------------|---------------------------------------------------------------------------------------------------------|
| `_APP_MIGRATIONS_FIREBASE_CLIENT_ID`            | **version >= 1.4.0** - Google OAuth client ID. You can find it in your GCP application settings.        |
| `_APP_MIGRATIONS_FIREBASE_CLIENT_SECRET`        | **version >= 1.4.0** - Google OAuth client secret. You can generate secrets in your GCP application settings. |

# Assistant {% #assistant %}
| **Name**                               | **Description**                                                         |
|----------------------------------------|-------------------------------------------------------------------------|
| `_APP_ASSISTANT_OPENAI_API_KEY`        | **version >= 1.4.0** - OpenAI API key. You can find it in your OpenAI application settings. |