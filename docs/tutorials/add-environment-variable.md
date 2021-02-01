# Introducing new Environment Variable

This document is part of the Appwrite contributors' guide. Before you continue reading this document make sure you have read the [Code of Conduct](https://github.com/appwrite/appwrite/blob/master/CODE_OF_CONDUCT.md) and the [Contributing Guide](https://github.com/appwrite/appwrite/blob/master/CONTRIBUTING.md).

## Getting Started

### Agenda
Adding new features may require various configurations options to be set by the users. And for such options we use environment variables in Appwrite.

This tutorial will cover, how to properly add new environment variable in Appwrite.

### Naming environment varialbe
The environment variables in Appwrite is prefixed with `_APP_`. Then if it belongs to specific cateogry, the category name is appended as `_APP_REDIS` for redis category. Finally the properly describing name is given to the variable as `_APP_REDIS_HOST` for redis host.

### Describe new environment variable
First of all we add the new environment variable to `app/config/variables.php` under specific category. If none of the categories fit, add it in general category. Copy the existing variables description to create new one so that you will not miss any required fields.

### Add to .env and Dockerfile
If newly introduced environment variable has a default value, add it to the `.env` and `Dockerfile` along with other environment variables.

### Add to docker compose file and template
Add the new environment variables to the `docker-compose.yml` and `app/views/install/compose.phtml` for each docker services that require access to those environment variables.

With these steps, your environment variable is properly added and can be accessed inside Appwrite code or any other containers where it is passed. You can access and use those variables to implement the features you are trying to implement.

If everything goes well, commit and initiate a PR and wait for the Appwrite team's approval.

Whooho! you have successfully added new environment variable to Appwrite.