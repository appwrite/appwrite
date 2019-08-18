# Contributing

We would love for you to contribute to Appwrite and help make it even better than it is today! As a contributor, here are the guidelines we would like you to follow:

## Code of Conduct

Help us keep Appwrite open and inclusive. Please read and follow our [Code of Conduct](/CODE_OF_CONDACT.md).

## Technology Stack

To start helping us to improve Appwrite server, prior knowledge of Appwrite technology stack can help you with getting started.

Appwrite stack is combined from a variety of open-source technologies and tools. Appwrite backend API is written primarily with PHP version 7 and above on top of the Utopia PHP framework. Appwrite frontend is built with tools like gulp, less and litespeed.js. We use Docker as the container technology to package the Appwrite server for easy integration on cloud, on-premise or local hosts.

### Other Technologies

* Redis - for managing cache and in-memory data (currently, we do not use Redis for persistent data)
* MariaDB - for database storage and queries
* InfluxDB - for managing stats and time-series based data
* Statsd - for sending data over UDP protocol (using telegraf)
* ClamAV - for validating and scanning storage files
* Imagemagick - for manipulating and managing image media files.
* Webp - for better compression of images on supporting clients
* SMTP - for sending email messages and alerts
* Resque - for managing data queues and scheduled tasks over a Redis server

## Package Managers

Appwrite is using a package manager for managing code dependencies for both backend and frontend development. We try our best to avoid creating any unnecessary and any new dependency to the project is subjected to a lead developer review and approval.

Many of Appwrite internal modules are also used as dependencies to allow other Appwrite's projects to reuse them and as a way to contribute them back to the community.

Appwrite uses PHPs Composer for managing dependencies on the server-side and JS NPM for managing dependencies on the frontend side.

## Scalability

## Architecture

## Security & Privacy

## Setup

To set up a working development environment just clone the project git repository and install the backend and frontend dependencies using the proper package manager and create run the docker-compose stack.

```bash
git clone git@github.com:appwrite/appwrite.git

cd appwrite

composer update --ignore-platform-reqs --optimize-autoloader --no-dev --no-plugins --no-scripts

npm install

docker-compose up -d
```

After finishing the installation process, you can start writing and editing code. To compile new CSS and JS distribution files, use 'less' and 'build' tasks using gulp as a task manager.

## Build

To build a new version of the Appwrite server all you need to do is run the build.sh file like this:

```bash
bash ./build.sh 1.0.0
```

Before running the command make sure you have proper write permissions to Appwrite docker hub team.