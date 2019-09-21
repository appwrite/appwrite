<p align="center">
    <a href="https://appwrite.io" target="_blank"><img width="260" height="39" src="https://appwrite.io/images/github-logo.png" alt="Appwrite Logo"></a>
    <br />
    <br />
    <b>Simple Backend Server for your [Vue / Angular / React / iOS / Android / Flutter / *ANY*] Frontend App</b>
    <br />
    <br />
</p>

[![Docker Pulls](https://img.shields.io/docker/pulls/appwrite/appwrite.svg)](https://hub.docker.com/r/appwrite/appwrite)
[![Chat With Us](https://img.shields.io/gitter/room/appwrite/community.svg)](https://gitter.im/appwrite/community?utm_source=share-link&utm_medium=link&utm_campaign=share-link)
[![Build Status](https://travis-ci.org/appwrite/appwrite.svg?branch=master)](https://travis-ci.org/appwrite/appwrite)
[![Follow  Appwrite on StackShare](https://img.stackshare.io/misc/follow-on-stackshare-badge.svg)](https://stackshare.io/appwrite)

---

Appwrite is a simple self-hosted backend server for web and mobile developers with a shiny dashboard and a very easy to use REST API.

Appwrite API services aims to make developers life a lot easier by hiding the complexity of common and repetitve software development tasks.

Using Appwrite you can easily manage user authentication with multiple sign-in methods, database for storing and querying user and teams data, storage and file management, image manipulation and cropping, scheduled cron tasks and many other features to help you get more results in faster times and with a lot less code.

[https://appwrite.io](https://appwrite.io)

![Appwrite](public/images/github.png)

## Installation

Appwrite backend server is designed to run in a container environment. Running your server is as easy as running one command from your terminal. You can run Appwrite on your localhost using docker-compose or on any other container orchestration tool like Kubernetes, Docker Swarm or Rancher.

The easiest way to start running your Appwrite server is by running our docker compose file:

```bash
mkdir appwrite-ce && \
cd appwrite-ce && \
curl -o docker-compose.yml https://appwrite.io/docker-compose.yml?port=80 && \
docker-compose up -d --remove-orphans
```

Once the Docker installation completes, go to http://localhost to access the Appwrite console from your browser. Please notice that on non-linux native hosts the server might take a few minutes to start after installation completes.

For advanced, production and custon installation check out our Docker [enviornemnt variables](/docs/EnviornementVariables.md) docs.

For complete API documentation, visit [https://appwrite.io/docs](https://appwrite.io/docs)

### Changing Port Number

In case your port 80 is already taken, change the port number in the command above. Make sure to set the correct endpoint in your selected SDK, including your new port number.

## Getting Started

Getting started with Appwrite is as easy as creating a new project, choosing your platform and integrating its SDK in your code. You can easily get started with your platform of choice by reading one of our getting started tutorials.

* [Getting Started for Web](https://appwrite.io/docs/getting-started-for-web)
* Getting Started for Android (soon...)
* Getting Started for iOS (soon...)
* [Getting Started for Server](https://appwrite.io/docs/getting-started-for-server)

### Services

* [**Auth**](https://appwrite.io/docs/auth) - Manage user authentication using multiple signin methods and account recovery.
* [**Account**](https://appwrite.io/docs/account) - Manage current user account. Track and manage the user sessions, devices and security audit log.
* [**Users**](https://appwrite.io/docs/users) - Manage and list all project users when in admin mode.
* [**Teams**](https://appwrite.io/docs/teams) - Manage and group users in teams. Manage memberships, invites and users roles within a team.
* [**Database**](https://appwrite.io/docs/database) - Manage database collections and document. Read, create, update and delete documents and filter lists of documents collections using advanced filter with graph like capabilities.
* [**Storage**](https://appwrite.io/docs/storage) - Manage storage files. Read, create, delete and preview files. Manipulate your files preview to fit your app perfectly. All files are scanned by ClamAV and stored in a secure and encrypted way.
* [**Locale**](https://appwrite.io/docs/locale) - Track users location, and manage your app locale based data.
* [**Avatars**](https://appwrite.io/docs/avatars) - Manage your users avatars, countries flags, browser icons, credit card symbols and generate QR codes.

### SDKs

Currently we are supporting a few SDK libraries and we are constantly working on adding new ones.

Below is a list of currently supported platforms and languages. If you wish to help us add support to your platform of choice you can go over to our [SDK Generator](https://github.com/appwrite/sdk-generator) project and view our contribution guide.

* ✅ [JS](https://github.com/appwrite/sdk-for-js) (Maintained by the Appwrite Team)
* ✅ [PHP](https://github.com/appwrite/sdk-for-php) (Maintained by the Appwrite Team)
* ✅ [NodeJS](https://github.com/appwrite/sdk-for-node) (Maintained by the Appwrite Team)
* ✅ [Ruby](https://github.com/appwrite/sdk-for-ruby) - **Work in progress** (Maintained by the Appwrite Team)
* ✅ [Python](https://github.com/appwrite/sdk-for-python) - **Work in progress** (Maintained by the Appwrite Team)
* ✳️ Looking for more SDKs? - Help us by contributing a pull request to our [SDK Generator](https://github.com/appwrite/sdk-generator)!

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and approved by a core developer before being merged. This is to ensure proper review of all the code.

We really ❤️ pull requests! If you wish to help, you can learn more about how you can contribute to this project in the [contribution guide](CONTRIBUTING.md).

## Security

For security issues, please email [security@appwrite.io](mailto:security@appwrite.io) instead of posting a public issue in GitHub.
