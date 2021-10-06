# Creating a new functions runtime

This document is part of the Appwrite contributors' guide. Before you continue reading this document make sure you have read the [Code of Conduct](https://github.com/appwrite/appwrite/blob/master/CODE_OF_CONDUCT.md) and the [Contributing Guide](https://github.com/appwrite/appwrite/blob/master/CONTRIBUTING.md).

## Getting started
Function Runtimes allow you to execute code written for any language as apart of the Appwrite Stack as serverless functions! Appwrite's Goal is to support as many function runtimes as possible.

## 1. Prerequisites
In order for a function runtime to work two prerequisites **must** be met due to the way that Appwrite's Runtime Execution Model works. Theses are as followed:

 - [ ] The Language in question must be able to run a web server that can serve json and text.
 - [ ] The Runtime must be able to be packaged into a docker container
 
 Note: Both Compiled and Interpreted languages work with Appwrite's execution model but both are written in slightly different ways.

It's really easy to contribute to an open source project, but when using GitHub, there are a few steps we need to follow. This section will take you step-by-step through the process of preparing your own local version of Appwrite, where you can make any changes without affecting Appwrite right away.
> If you are experienced with GitHub or have made a pull request before, you can skip to [Implement new runtime](https://github.com/appwrite/appwrite/blob/master/docs/tutorials/add-runtime.md#2-implement-new-runtime).

### 1.1 Fork the Appwrite repository

Before making any changes, you will need to fork Appwrite's repository to keep branches on the official repo clean. To do that, visit [Appwrite's Runtime repository](https://github.com/appwrite/php-runtimes) and click on the fork button.

[![Fork button](https://github.com/appwrite/appwrite/raw/master/docs/tutorials/images/fork.png)](https://github.com/appwrite/appwrite/blob/master/docs/tutorials/images/fork.png)

This will redirect you from `github.com/appwrite/php-runtimes` to `github.com/YOUR_USERNAME/php-runtimes`, meaning all changes you do are only done inside your repository. Once you are there, click the highlighted `Code` button, copy the URL and clone the repository to your computer using `git clone` command:
```bash
$ git clone COPIED_URL
```

> To fork a repository, you will need a basic understanding of CLI and git-cli binaries installed. If you are a beginner, we recommend you to use `Github Desktop`. It is a really clean and simple visual Git client.

Finally, you will need to create a `feat-XXX-YYY-runtime` branch based on the `master` branch and switch to it. The `XXX` should represent the issue ID and `YYY` the runtime name.

## 2. Implement new runtime

### 2.1 Preparing the files for your new runtime
The first step to writing a new runtime is to create a folder within `/runtimes` with the name of the runtime and the version separated by a dash. For instance if I was to write a Rust Runtime with the version 1.55 the folder name would be: `rust-1.55`

Within that folder you will need to create a few basic files that all Appwrite runtimes require:
```
Dockerfile - Dockerfile that explains how the container will be built.
README.md - A readme file explaining the runtime and any special notes for the runtime. A good example of this is the PHP 8.0 one.
```

### 2.2 Differences between compiled and interpreted runtimes
Runtimes within Appwrite are created differently depending on if they are compiled or interpreted. This is due to the fundamental differences between the two ways of running the code.

Interpreted languages have both a `build.sh` file and a `launch.sh` file.
The `build.sh` file for a interpreted runtime is normally used for installing any dependencies for both the server itself and the user's code then to copy it to the `/usr/code` folder which is packaged up and can be used later for running the server.
The build script is always executed during the build stage of tag deployment.

The `launch.sh` file for a interpreted runtime should extract the `/tmp/code.tar.gz` file that contains both the user's code and the dependencies. This tarball was created by Appwrite from the `/usr/code` folder and should install the dependencies that were pre-installed by the build stage and move them into the relevant locations for that runtime. It will then run the server ready for execution.

---
Compiled Languages only have a `build.sh` file.
The `build.sh` script for a compiled runtime is used to move the user's source code and rename it into source files for the runtime (The `APPWRITE_ENTRYPOINT_NAME` environment variable can help with this) it will also build the code and move it into the `/usr/code` folder. Compiled runtime executables **must** be called `runtime` in order for the ubuntu or alpine images to detect and run them.

#### Note:
`/tmp/code.tar.gz` is always created from the `/usr/code` folder in the build stage. If you need any files for either compiled or interpreted runtimes you should place them there and extract them from the `/tmp/code.tar.gz` during the `launch.sh` script to get the files you need.

### 2.3 Writing the runtime
Internally the runtime can be anything you like as long as it follows the standards set by the other runtimes.

The best way to go about writing a runtime is like so:
Initialize a web server which runs on port 3000 and uses any IP Address (0.0.0.0) and on each `POST` request do the following:
1. Check that the `x-internal-challenge` header matches the `APPWRITE_INTERNAL_RUNTIME_KEY` environment variable. If not return an error with a `401` status code and a `unauthorized` error message.
2. Decode the executor's JSON POST request. This normally looks like so:
```json
{
 "path": "/usr/code", // Disregard for Compiled Languages
 "file": "index.js", // Disregard for Compiled Languages
 "env": {
		 "hello":"world!"
	},
 "payload":"An Example Payload",
 "timeout": 10
}
```
For a compiled language you can disregard the `path` and `file` attribute if you like, 

`timeout` is also an optional parameter to deal with, if you can handle it please do. Otherwise it doesn't matter since the connection will simply be dropped by the executor.

You must create two classes for users to use within their scripts. A `Request` Class and a `Response` class
The `Request` class must store `env`, `payload` and `headers` and pass them to the user's function. 
Request always goes before response in the user's function parameters.
The `Response` class must have two functions. 
- A `send(string)` function which will return text to the request
- and a `json(object)` function which will return JSON to the request setting the appropriate headers

For a interpreted language use the `path` and `file` parameters to find the file and require it.
Please make sure to add appropriate checks to make sure the imported file is actually a function that you can execute.

5. Finally execute the function and handle whatever response the user's code returns. Try to wrap the function into a `try catch` statement to handle any errors the user's function encounters and return then cleanly to the executor with the error schema.

### 2.4 The Error Schema
All errors that occur during the execution of a user's function **MUST** be returned using this JSON Object otherwise Appwrite will be unable to parse them for the user.
```json
{
	"code": 500, // (Int) Use 404 if function not found or use 401 if the x-internal-challenge check failed.
	"message": "Error: Tried to divide by 0 \n /usr/code/index.js:80:7", // (String) Try to return a stacktrace and detailed error message if possible. This is shown to the user.
}
```

### 2.5 Writing your Dockerfile
The Dockerfile is very important as it's the environment you are creating to run build the runtime and also run the code if you are writing an Interpreted Runtime (Compiled runtimes will use an alpine or ubuntu image)

The first thing you need to do is find a docker image to base your runtime off, You can find these at [Docker Hub](https://hub.docker.com). If possible try to use verified official builds of the language you are creating a runtime for.

Next in your Dockerfile at the start add the docker image you want to base it off at the top like so:
```bash
FROM Dart:2.12 # Dart used as an example.
```
This will download and require the image when you build your runtime and allow you to use the toolset of the language you are building a runtime for.

Next copy your source code and set the work directory for the image like so:
```
WORKDIR /usr/local/src
COPY . /usr/local/src
```

Next you want to make sure you are adding execute permissions to any scripts you may run, the main ones are `build.sh` and `launch.sh`. You can run commands in Dockerfile's using the `RUN` prefix like so:
```
RUN chmod +x ./build.sh
RUN chmod +x ./launch.sh
```
Note: Do not chmod a `launch.sh` file if you don't have one.

If needed use the `RUN` commands to install any dependencies you require for the build stage.

Finally you'll add a `CMD` command. For a interpreted language this should be:
```
CMD ["/usr/local/src/launch.sh"]
```
Since this will use your launch script when the runtime starts.

For a compiled language this must be:
```
CMD ["tail", "-f", "/dev/null"]
```
so the build steps can be run.

## 3. Building your docker image and adding it to the list
With your runtime successfully created you can now move on to building your docker image and adding it to the script files used for generating all of the image files.

Open up the `/runtimes/buildLocalOnly.sh` script first and add your runtime to it. The following is an example with dart version 2.12
```
echo 'Dart 2.12...'
docker build -t dart-runtime:2.12 ./runtimes/dart-2.12
```
Next open up the `/runtimes/build.sh` script and also add your runtime to it. This one is slightly different as this is the one that will be used for cross platform compiles and deploying it to docker hub. The following is an example also with dart version 2.12:
```
echo  'Dart 2.12...'
docker buildx build --platform linux/amd64,linux/arm/v6,linux/arm/v7,linux/arm64,linux/386 -t dart-runtime:2.12 ./runtimes/dart-2.12/ --push
```

## 4. Adding the runtime to the runtimes list
In `src/Runtimes/Runtimes` create a new entry in the `__construct()` method in the Runtimes class like so:
```
$dart = new Runtime('dart', 'Dart');
$dart->addVersion('2.12', 'dart-runtime:2.12', 'appwrite-ubuntu:20.04', [System::X86, System::ARM]);
$this->runtimes['dart'] = $dart;
```
This is an example of what you would do for a compiled language such as dart.

The first line is creating a new language entry, The first parameter is the internal name and the second one is the external one which is what the user will see in Appwrite.

The second line adds a new version to the language entry, I'll break down the parameters:
```
1: Version - The version of the runtime you are creating.
2: Build Image - The image used to build the code
3: Run Image - The image used to run the code. 
For interpreted languages this is normally the same as the Build Image, but for compiled languages this can be either "appwrite-alpine:3.13.6" or "appwrite-ubuntu:20.04"
We recommend using Alpine when possible and using ubuntu if the runtime doesn't work on alpine.
4: Platforms Supported - These are the architectures this runtime is available to.
```
The third line simply adds the new runtime to the main list.

## 5. Adding tests

