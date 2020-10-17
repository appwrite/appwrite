Appwrite Logo

A complete backend solution for your [Flutter / Vue / Angular / React / iOS / Android / *ANY OTHER*] app

Hacktoberfest Discord Docker Pulls Travis CI Twitter Account Follow Appwrite on StackShare

Appwrite is an end-to-end backend server for Web, Mobile, Native, or Backend apps packaged as a set of Docker microservices. Appwrite abstracts the complexity and repetitiveness required to build a modern backend API from scratch and allows you to build secure apps faster.

Using Appwrite, you can easily integrate your app with user authentication & multiple sign-in methods, a database for storing and querying users and team data, storage and file management, image manipulation, schedule CRON tasks, and more services.

Find out more at: https://appwrite.io

Appwrite

Table of Contents:

Installation
Unix
Windows
ios 
Gitshell
CMD
PowerShell
Upgrade from an Older Version
Getting Started
Services
SDKs like Flutter
Client
Server
Contributing
Security
Follow Us
License
Installation
Appwrite backend server is designed to run in a container environment. Running your server is as easy as running one command from your terminal. You can either run Appwrite on your localhost using docker-compose or on any other container orchestration tool like Kubernetes, Docker Swarm, or Rancher.

The easiest way to start running your Appwrite server is by running our docker-compose file. Before running the installation command make sure you have Docker installed on your machine:

Unix
docker run -it --rm \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/install/appwrite:rw \
    -e version=0.6.2 \
    appwrite/install
Windows
CMD
docker run -it --rm ^
    --volume //var/run/docker.sock:/var/run/docker.sock ^
    --volume "%cd%"/appwrite:/install/appwrite:rw ^
    -e version=0.6.2 ^
    appwrite/install
PowerShell
docker run -it --rm ,
    --volume /var/run/docker.sock:/var/run/docker.sock ,
    --volume ${pwd}/appwrite:/install/appwrite:rw ,
    -e version=0.6.2 ,
    appwrite/install
Once the Docker installation completes, go to http://localhost to access the Appwrite console from your browser. Please note that on non-linux native hosts, the server might take a few minutes to start after installation completes.

For advanced production and custom installation, check out our Docker environment variables docs. You can also use our public docker-compose.yml file to manually set up and environment.

Upgrade from an Older Version
If you are upgrading your Appwrite server from an older version, you should use the Appwrite migration tool once your setup is completed. For more information regarding this, check out the Installation Docs.

Getting Started
Getting started with Appwrite is as easy as creating a new project, choosing your platform, and integrating its SDK in your code. You can easily get started with your platform of choice by reading one of our Getting Started tutorials.

Getting Started for Web
Getting Started for Flutter
Getting Started for Server
Getting Started for Android (Coming soon...)
Getting Started for iOS (Coming soon...)
Services
Account - Manage current user authentication and account. Track and manage the user sessions, devices, sign-in methods, and security logs.
Users - Manage and list all project users when in admin mode.
Teams - Manage and group users in teams. Manage memberships, invites, and user roles within a team.
Database - Manage database collections and documents. Read, create, update, and delete documents and filter lists of documents collections using an advanced filter with graph-like capabilities.
Storage - Manage storage files. Read, create, delete, and preview files. Manipulate the preview of your files to fit your app perfectly. All files are scanned by ClamAV and stored in a secure and encrypted way.
Locale - Track your user's location, and manage your app locale-based data.
Avatars - Manage your users' avatars, countries' flags, browser icons, credit card symbols, and generate QR codes.
For the complete API documentation, visit https://appwrite.io/docs. For more tutorials, news and announcements check out our blog and Discord Server.

SDKs
Below is a list of currently supported platforms and languages. If you wish to help us add support to your platform of choice, you can go over to our SDK Generator project and view our contribution guide.

Client
✅ Web (Maintained by the Appwrite Team)
✅ Flutter (Maintained by the Appwrite Team)
Server
✅ NodeJS (Maintained by the Appwrite Team)
✅ PHP (Maintained by the Appwrite Team)
✅ Deno - Beta (Maintained by the Appwrite Team)
✅ Ruby - Beta (Maintained by the Appwrite Team)
✅ Python - Beta (Maintained by the Appwrite Team)
✅ Go Work in progress (Maintained by the Appwrite Team)
✅ Dart Work in progress (Maintained by the Appwrite Team)
Looking for more SDKs? - Help us by contributing a pull request to our SDK Generator!

Contributing
All code contributions - including those of people having commit access - must go through a pull request and approved by a core developer before being merged. This is to ensure proper review of all the code.

We truly ❤️ pull requests! If you wish to help, you can learn more about how you can contribute to this project in the contribution guide.

Security
For security issues, kindly email us at security@appwrite.io instead of posting a public issue in GitHub.

Follow Us
Join our growing community around the world! Follow us on Twitter, Facebook Page, Facebook Group or join our live Discord server for more help, ideas, and discussions.

License
This repository is available under the BSD 3-Clause License.
