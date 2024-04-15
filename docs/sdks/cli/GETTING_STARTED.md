## Getting Started 

Before you can use the CLI, you need to login to your Appwrite account. 

```sh
$ appwrite login

? Enter your email test@test.com
? Enter your password ********
✓ Success 
```
This will also prompt you to enter your Appwrite endpoint ( default: http://localhost/v1 ) 

* ### Initialising your project
Once logged in, the CLI needs to be initialised before you can use it with your Appwrite project. You can do this with the `appwrite init project` command. 

```sh
$ appwrite init project
```

The following prompt will guide you through the setup process. The `init` command also creates an `appwrite.json` file representing your Appwrite project.

The `appwrite.json` file does a lot of things. 
* Provides context to the CLI
* Keeps track of all your cloud functions
* Keeps track of all your project's collections
* Helps you deploy your Appwrite project to production and more..

You can also fetch all the collections in your current project using
```sh
appwrite init collection
```

* ### Creating and deploying cloud functions

The CLI makes it extremely easy to create and deploy Appwrite's cloud functions. Initialise your new function using

```
$ appwrite init function
? What would you like to name your function? My Awesome Function
? What runtime would you like to use? Node.js (node-15.5)
✓ Success 
```

This will create a new function `My Awesome Function` in your current Appwrite project and also create a template function for you to get started.

```sh
$ tree My\ Awesome\ Function 

My Awesome Function
├── README.md
├── index.js
├── package-lock.json
└── package.json

0 directories, 4 files
```

You can now deploy this function using 

```sh
$ appwrite deploy function

? Which functions would you like to deploy? My Awesome Function (61d1a4c81dfcd95bc834)
ℹ Info Deploying function My Awesome Function ( 61d1a4c81dfcd95bc834 )
✓ Success Deployed My Awesome Function ( 61d1a4c81dfcd95bc834 )
```

Your function has now been deployed on your Appwrite server! As soon as the build process is finished, you can start executing the function.

* ### Deploying Collections

Similarly, you can deploy all your collections to your Appwrite server using 

```sh
appwrite deploy collection
```

> ### Note
> By default, requests to domains with self signed SSL certificates (or no certificates) are disabled. If you trust the domain, you can bypass the certificate validation using
```sh
$ appwrite client --selfSigned true
```

## Usage 

The Appwrite CLI follows the following general syntax.
```sh
$ appwrite [COMMAND] --[OPTIONS]
```

A few sample commands to get you started 

```sh
$ appwrite users create --userId "unique()" --email hello@appwrite.io --password very_strong_password
$ appwrite users list 
```

To create a document you can use the following command 
```sh
$ appwrite database createDocument --collectionId <ID> --documentId 'unique()' --data '{ "Name": "Iron Man" }' --permissions 'read("any")' 'read("team:abc")'
```

### Some Gotchas
- `data` must be a valid JSON string where each key and value are enclosed in double quotes `"` like the example above.
- Some arguments like the `read` and `write` permissions are expected to be arrays. In the Appwrite CLI, array values are passed in using space as a separator like in the example above.


To get information about the different services available, you can use 
```sh
$ appwrite -h
```

To get information about a particular service and the commands available in a service you can use 
```sh
$ appwrite users // or
$ appwrite users --help // or
$ appwrite users help // or
$ appwrite accounts
```

To get information about a particular command and the parameters it accepts, you can use

```sh
$ appwrite users list --help
$ appwrite account get --help 
```

At any point, you can view or reset the CLI configuration using the `client` service.

```
$ appwrite client --debug
// This will display your endpoint, projectID, API key and so on.
$ appwrite client --reset
```

## CI mode

The Appwrite CLI can also work in a CI environment. The initialisation of the CLI works a bit differently in CI. In CI, you set your `endpoint`, `projectId` and `API Key` using 

```sh
appwrite client --endpoint http://localhost/v1 --projectId <PROJECT_ID> --key <API KEY>
```