<p align="center">
    <a href="https://appwrite.io" target="_blank"><img width="260" height="39" src="https://appwrite.io/images/github-logo.png" alt="Appwrite Logo"></a>
    <br />
    <br />
    <b>Simple Backend Server for your [Vue / Angular / React / iOS / Android / Flutter / *ANY OTHER*] Frontend App</b>
    <br />
    <br />
</p>

[![Docker Pulls](https://img.shields.io/docker/pulls/appwrite/appwrite.svg)](https://hub.docker.com/r/appwrite/appwrite)
[![Discord](https://img.shields.io/discord/564160730845151244)](https://discord.gg/GSeTUeA)
[![Build Status](https://travis-ci.org/appwrite/appwrite.svg?branch=master)](https://travis-ci.org/appwrite/appwrite)
[![Follow  Appwrite on StackShare](https://img.stackshare.io/misc/follow-on-stackshare-badge.svg)](https://stackshare.io/appwrite)
[![Follow new releases](https://app.releasly.co/assets/badges/badge-blue.svg)](https://app.releasly.co/sites/appwrite/appwrite?utm_source=github_badge)
[![License](https://img.shields.io/badge/License-BSD%203--Clause-blue.svg)](https://opensource.org/licenses/BSD-3-Clause)


---

Appwrite is a simple self-hosted backend server for web and mobile developers with a shiny dashboard and a very easy-to-use REST API.

Appwrite API services aim to make developer's life a lot easier by hiding the complexity of common and repetitive software development tasks.

Using Appwrite, you can easily manage user authentication with multiple sign-in methods, a database for storing and querying user and team data, storage and file management, image manipulation and cropping, schedule cron tasks and many other features to help you get more results in faster times and with a lot less code.

[https://appwrite.io](https://appwrite.io)

![Appwrite](public/images/github.png)

Table of Contents:

- [Installation](#installation)
  - [Changing Port Number](#changing-port-number)
- [Getting Started](#getting-started)
  - [Services](#services)
  - [SDKs](#sdks)
- [Security](#security)
- [Follow Us](#follow-us)
- [Contributing](#contributing)
- [Credits](#credits)
      
## Installation

Appwrite backend server is designed to run in a container environment. Running your server is as easy as running one command from your terminal. You can either run Appwrite on your localhost using docker-compose or on any other container orchestration tool like Kubernetes, Docker Swarm or Rancher.

The easiest way to start running your Appwrite server is by running our docker-compose file:

```bash
mkdir appwrite-ce && \
cd appwrite-ce && \
curl -o docker-compose.yml https://appwrite.io/docker-compose.yml?version=0.3.0 && \
docker-compose up -d --remove-orphans
```


Once the Docker installation completes, go to http://localhost to access the Appwrite console from your browser. Please notice that on non-linux native hosts, the server might take a few minutes to start after installation completes.


For advanced production and custom installation, check out our Docker [environment variables](docs/tutorials/environment-variables.md) docs.

### Changing Port Number

In case your port 80 is already taken, change the port number in the command above. Make sure to set the correct endpoint in your selected SDK, including your new port number.

## Getting Started

Getting started with Appwrite is as easy as creating a new project, choosing your platform and integrating its SDK in your code. You can easily get started with your platform of choice by reading one of our Getting Started tutorials.

* [Getting Started for Web](https://appwrite.io/docs/getting-started-for-web)
* [Getting Started for Server](https://appwrite.io/docs/getting-started-for-server)
* Getting Started for Android (soon...)
* Getting Started for iOS (soon...)

### Services

* [**Auth**](https://appwrite.io/docs/auth) - Manage user authentication using multiple sign-in methods and account recovery.
* [**Account**](https://appwrite.io/docs/account) - Manage current user account. Track and manage the user sessions, devices, and security audit log.
* [**Users**](https://appwrite.io/docs/users) - Manage and list all project users when in admin mode.
* [**Teams**](https://appwrite.io/docs/teams) - Manage and group users in teams. Manage memberships, invites and user roles within a team.
* [**Database**](https://appwrite.io/docs/database) - Manage database collections and documents. Read, create, update and delete documents and filter lists of documents collections using an advanced filter with graph-like capabilities.
* [**Storage**](https://appwrite.io/docs/storage) - Manage storage files. Read, create, delete and preview files. Manipulate the preview of your files to fit your app perfectly. All files are scanned by ClamAV and stored in a secure and encrypted way.
* [**Locale**](https://appwrite.io/docs/locale) - Track user's location, and manage your app locale-based data.
* [**Avatars**](https://appwrite.io/docs/avatars) - Manage your users' avatars, countries' flags, browser icons, credit card symbols and generate QR codes.

For the complete API documentation, visit [https://appwrite.io/docs](https://appwrite.io/docs). For more tutorials, news and announcements check out our [blog](https://medium.com/appwrite-io).

### SDKs

Currently, we support only a few SDK libraries and are constantly working on including new ones.

Below is a list of currently supported platforms and languages. If you wish to help us add support to your platform of choice, you can go over to our [SDK Generator](https://github.com/appwrite/sdk-generator) project and view our contribution guide.

* ✅ [JS](https://github.com/appwrite/sdk-for-js) (Maintained by the Appwrite Team)
* ✅ [PHP](https://github.com/appwrite/sdk-for-php) (Maintained by the Appwrite Team)
* ✅ [NodeJS](https://github.com/appwrite/sdk-for-node) (Maintained by the Appwrite Team)
* ✅ [Ruby](https://github.com/appwrite/sdk-for-ruby) - **Work in progress** (Maintained by the Appwrite Team)
* ✅ [Python](https://github.com/appwrite/sdk-for-python) - **Work in progress** (Maintained by the Appwrite Team)
* ✳️ Looking for more SDKs? - Help us by contributing a pull request to our [SDK Generator](https://github.com/appwrite/sdk-generator)!

## Security

For security issues, kindly email us [security@appwrite.io](mailto:security@appwrite.io) instead of posting a public issue in GitHub.

## Follow Us

Join our growing community around the world! Follow us on [Twitter](https://twitter.com/appwrite_io), [Facebook Page](https://www.facebook.com/appwrite.io), [Facebook Group](https://www.facebook.com/groups/appwrite.developers/) or join our live [Gitter community](https://gitter.im/appwrite/community) for more help, ideas, and discussions.

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and approved by a core developer before being merged. This is to ensure proper review of all the code.

We truly ❤️ pull requests! If you wish to help, you can learn more about how you can contribute to this project in the [contribution guide](CONTRIBUTING.md).

## License

This repository is available under the [BSD 3-Clause License](./LICENSE).

## Credits

Created by [Eldad Fux](https://twitter.com/eldadfux) with the amazing help of our **amazing contributors:**

* 🇺🇸 Justin Dorfman ([GitHub](https://github.com/jdorfman), [Twitter](https://twitter.com/jdorfman))
* 🇺🇳 0xflotus ([GitHub](https://github.com/0xflotus))
* 🇳🇴 Petter Charles Redfern ([GitHub](https://github.com/Chaaarles))
* 🇧🇷 Jessé Souza ([GitHub](https://github.com/jessescn))
* 🇪🇸 Esther Álvarez Feijoo ([GitHub](https://github.com/EstherAF))
* 🇮🇳 Christy Jacob ([GitHub](https://github.com/christyjacob4), [LinkedIn](https://www.linkedin.com/in/christyjacob4/))
* 🇺🇦 Dmitriy Danilov ([GitHub](https://github.com/daniloff200), [Twitter](https://twitter.com/daniloff200))
* 🇮🇩 Zeet ([GitHub](https://github.com/Kiy4h))
* 🇫🇮 Pessi Päivärinne ([GitHub](https://github.com/pessip))
* 🇺🇳 Sergi Vos ([GitHub](https://github.com/sergivb01), [Twitter](https://twitter.com/sergivb01), [LinkedIn](https://www.linkedin.com/in/sergivb01/))
* 🇮🇱 Tomer Cohen ([GitHub](https://github.com/tomer), [Twitter](https://twitter.com/tomer))
* 🇮🇹 Tatiana ([GitHub](https://github.com/tacoelho))
* 🇬🇷 Panagiotis Skarlas ([GitHub](https://github.com/1qk1), [Twitter](https://twitter.com/qktweets))
* 🇷🇺 Alexey Pyltsyn ([GitHub](https://github.com/lex111))
* 🇩🇪 Robin C ([GitHub](https://github.com/Taminoful))
* 🇮🇳 Parikshit Hooda ([GitHub](https://github.com/Parikshit-Hooda))
* 🇫🇷 Corentin Le Fur ([GitHub](https://github.com/crtlf))
* 🇬🇧 Callum Lowry ([GitHub](https://github.com/Girxffe))
* 🇷🇴 Bogdan Covrig ([GitHub](https://github.com/BogDAAAMN), [Twitter](https://twitter.com/BogdanCovrig))
* 🇳🇱 Kolkies ([GitHub](https://github.com/kolkies.dev))
* 🇹🇷 Mustafa Dur ([GitHub](https://github.com/tosbaha))
* 🇵🇹 Luís Pinto ([GitHub](https://github.com/LiTO773))
* 🇮🇳 Saksham Saxena ([GitHub](https://github.com/sakkshm))
* 🇮🇱 Dima Vishnevetsky ([GitHub](https://github.com/dimshik100), [Twitter](https://twitter.com/dimshik100))
* 🇭🇺 lacexd ([GitHub](https://github.com/lacexd))
