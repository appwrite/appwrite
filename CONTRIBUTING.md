# Contributing

We would ❤️ for you to contribute to Appwrite and help make it  better! As a contributor, here are the guidelines we would like you to follow:

## Code of Conduct

Help us keep Appwrite open and inclusive. Please read and follow our [Code of Conduct](/CODE_OF_CONDUCT.md).

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

## Coding Standards

Appwrite is following the PHP-FIG standards. Currently, we are using both PSR-0 and PSR-4 for coding standards and autoloading standards. Soon we will also review the project for support with PSR-12 (Extended Coding Style).

We use prettier for our JS coding standards and for auto-formatting our code.

## Scalability, Speed and Performance

Appwrite is built to scale. Please keep in mind that the Appwrite stack can run in different environments and different scales.

We wish Appwrite will be as easy to set up and in a single, local host and easy to grow to a large environment with thousands and even hundreds of instances.

When contributing code please take into account the following considerations:

* Response Time
* Throughput
* Requests per Seconds
* Network Usage
* Memory Usage
* Browser Rendering
* Background Jobs
* Task Execution Time

## Architecture

Appwrite current structure is a combination of both Monolithic and Microservice architectures, but our final goal, as we grow is to be using only microservices.

### The Monolithic Part

Appwrite main API container is designed as a monolithic app. This is a decision we made to allow us to develop the project faster while still being a very small team.

Although the Appwrite API is a monolithic app, it has a very clear separation of concern as each internal service or worker is separated by it its container which will allow us as we grow to start breaking services for better maintenance and scalability.

### The Microservice Part

Each container in Appwrite is a microservice by its own. Each service is an independent process which can scale without regard to any of the other services.

Currently, all of the Appwrite microservices are intended to communicate using TCP protocol over a private network. You should be aware to not expose any of the services to the public-facing network, besides the public port 80 and 443 who by default are used to expose the Appwrite HTTP API.

## Security & Privacy

Security and privacy are extremely important to Appwrite, developers and users alike. Make sure to follow the best industry standards and practices. To help you make sure you are doing as best as possible we have set up our security checklist for pull requests and contributors. Please make sure to follow the list before sending a pull request.

## Dependencies

Please avoid introducing new dependencies to Appwrite without consulting the team. New dependencies can be very helpful but also introduce new security and privacy issues, complexity and impact total docker image size.

Adding a new dependency should have vital value on the product with minimum possible risk.

## Introducing New Features

We whould 💖 you to contribute to Appwrite, but we whould also like to make sure Appwrite is as great as possible and loyal to its vision and mission statement 🙏.

For us to find the right balance, please open an issue explaining your ideas before intoducing a new pull requests.

This will allow the Appwrite community to have sufficient discussion about the new feature value and how it fits in the product roadmap and vision.

This is also important for the Appwrite lead developers to be able to give technical input and different emphasises regarding the feature design and architecture.

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

## Tutorials

From time to time our team will add tutorials that will help contributers find there way in the Appwrite source code. Below is a list of currently available tutorials:

* [Adding Support for a New OAuth Provider](./docs/AddOAuthProvider.md)