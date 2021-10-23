# Introducing new Environment Variable

This document is part of the Appwrite contributors' guide. Before you continue reading this document, make sure you have read the [Code of Conduct](https://github.com/appwrite/appwrite/blob/master/CODE_OF_CONDUCT.md) and the [Contributing Guide](https://github.com/appwrite/appwrite/blob/master/CONTRIBUTING.md).

## Getting Started

### Agenda
Adding new features may require various configurations options to be set by the users. And for such possibilities, we use environment variables in Appwrite.

This tutorial will cover how to add a new environment variable in Appwrite correctly.

### Naming environment varialbe
The environment variables in Appwrite are prefixed with `_APP_.` If it belongs to a specific category, the category name is appended as `_APP_REDIS` for the Redis category. The available types are General, Redis, MariaDB, InfluxDB, StatsD, SMTP, Storage, and Functions. Finally, a correctly described name is given to the variable. For example, `_APP_REDIS_HOST` is an environment variable for the Redis connection host. You can find more information on available categories and existing environment variables in the [environment variables doc](https://appwrite.io/docs/environment-variables).

### Describe new environment variable
First of all, we added the new environment variable to `app/config/variables.php` in the designated category. If none of the types fit, add it to the General category. Copy the description of the existing variables to create a new one to avoid missing any required fields.

This information is also used to generate the website documentation at https://appwrite.io/docs/environment-variables, so please use good descriptions that clearly define the purpose and other required info about the environment variable that you are adding.

### Add to .env and Dockerfile
If the newly introduced environment variable has a default value, add it to the `.env` and `Dockerfile` and other environment variables. `.env` file uses settings for Appwrite development environment.

### Add to docker-compose file and template
Add the new environment variables to the `docker-compose.yml,` and `app/views/install/compose.phtml` for each docker service that requires access to those environment variables.

The App uses the `docker-compose.yml` file write maintainers during development, whereas the `app/views/install/compose.phtml` file is used by the App write setup script.

With these steps, your environment variable is added correctly and can be accessed inside Appwrite code and any other containers where it is passed. You can access and use those variables to implement the features you are trying to achieve.

If everything went well, commit and initiate a PR and wait for the App write team's approval.

Woohoo! you have successfully added a new environment variable to Appwrite. 🎉
