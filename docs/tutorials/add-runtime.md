# Creating a new functions runtime 🏃

This document is part of the Appwrite contributors' guide. Before you continue reading this document make sure you have read the [Code of Conduct](https://github.com/appwrite/.github/blob/main/CODE_OF_CONDUCT.md) and the [Contributing Guide](https://github.com/appwrite/appwrite/blob/master/CONTRIBUTING.md).

## Getting started

Function Runtimes allow you to execute code written in any language and form the basis of Appwrite's Cloud Functions! Appwrite's goal is to support as many function runtimes as possible.

## 1. Prerequisites

For a function runtime to work, two prerequisites **must** be met due to the way Appwrite's Runtime Execution Model works:

- [ ] The Language in question must be able to run a web server that can serve JSON and text.
- [ ] The Runtime must be able to be packaged into a Docker container

Note: Both Compiled and Interpreted languages work with Appwrite's execution model but are written in slightly different ways.

It's really easy to contribute to an open-source project, but when using GitHub, there are a few steps we need to follow. This section will take you step-by-step through the process of preparing your local version of Appwrite, where you can make any changes without affecting Appwrite right away.

> If you are experienced with GitHub or have made a pull request before, you can skip to [Implement new runtime](https://github.com/appwrite/appwrite/blob/master/docs/tutorials/add-runtime.md#2-implement-new-runtime).

### 1.1 Fork the Appwrite repository

Before making any changes, you will need to fork Appwrite's repository to keep branches on the official repo clean. To do that, visit [Appwrite's Runtime repository](https://github.com/appwrite/runtimes) and click on the fork button.

[![Fork button](https://github.com/appwrite/appwrite/raw/master/docs/tutorials/images/fork.png)](https://github.com/appwrite/appwrite/blob/master/docs/tutorials/images/fork.png)

This will redirect you from `github.com/appwrite/runtimes` to `github.com/YOUR_USERNAME/runtimes`, meaning all changes you do are only done inside your repository. Once you are there, click the highlighted `Code` button, copy the URL and clone the repository to your computer using the `git clone` command:

```bash
$ git clone COPIED_URL
```

> To fork a repository, you will need a basic understanding of CLI and git-cli binaries installed. If you are a beginner, we recommend you to use `Github Desktop`. It is a clean and simple visual Git client.

Finally, you will need to create a `feat-XXX-YYY-runtime` branch based on the `refactor` branch and switch to it. The `XXX` should represent the issue ID and `YYY` the runtime name.

## 2. Implement new runtime

### 2.1 Preparing the files for your new runtime

The first step to writing a new runtime is to create a folder within `/runtimes` with the name of the runtime and the version separated by a dash. For instance, if I was to write a Rust Runtime with version 1.55 the folder name would be: `rust-1.55`

Within that folder you will need to create a few basic files that all Appwrite runtimes require:

```
Dockerfile - Dockerfile that explains how the container will be built.
README.md - A readme file explaining the runtime and any special notes for the runtime. A good example of this is the PHP 8.0 runtime.
```

### 2.2 Differences between compiled and interpreted runtimes

Runtimes within Appwrite are created differently depending on whether they are compiled or interpreted. This is due to the fundamental differences between the two ways of running the code.

Interpreted languages have both a `build.sh` file and a `launch.sh` file.
The `build.sh` file for an interpreted runtime is normally used for installing any dependencies for both the server itself and the user's code and then to copy it to the `/usr/code` folder which is then packaged and can be used later for running the server.
The build script is always executed during the build stage of tag deployment.

The `launch.sh` file for an interpreted runtime should extract the `/tmp/code.tar.gz` file that contains both the user's code and the dependencies. This tarball was created by Appwrite from the `/usr/code` folder and should install the dependencies that were pre-installed by the build stage and move them into the relevant locations for that runtime. It will then run the server ready for execution.

---

Compiled Languages only have a `build.sh` file.
The `build.sh` script for a compiled runtime is used to move the user's source code and rename it into source files for the runtime (The `ENTRYPOINT_NAME` environment variable can help with this) it will also build the code and move it into the `/usr/code` folder. Compiled runtime executables **must** be called `runtime` for the ubuntu or alpine images to detect and run them.

#### Note:

`/tmp/code.tar.gz` is always created from the `/usr/code` folder in the build stage. If you need any files for either compiled or interpreted runtimes you should place them there and extract them from the `/tmp/code.tar.gz` during the `launch.sh` script to get the files you need.

### 2.3 Writing the runtime

Internally the runtime can be anything you like as long as it follows the standards set by the other runtimes.

The best way to go about writing a runtime is like so:
Initialize a web server that runs on port 3000 and uses any IP Address (0.0.0.0) and on each `POST` request do the following:
1. Check that the `x-internal-challenge` header matches the `INTERNAL_RUNTIME_KEY` environment variable. If not, return an error with a `401` status code and an `unauthorized` error message.
2. Decode the executor's JSON POST request. This normally looks like so:

```json
{
  "path": "/usr/code",
  "file": "index.js",
  "env": {
    "hello": "world!"
  },
  "payload": "An Example Payload",
  "timeout": 10
}
```

For a compiled language you can disregard the `path` and `file` attribute if you like,

`timeout` is also an optional parameter to deal with, if you can handle it please do. Otherwise, it doesn't matter since the connection will simply be dropped by the executor.

You must create two classes for users to use within their scripts. A `Request` Class and a `Response` class
The `Request` class must store `env`, `payload` and `headers` and pass them to the user's function.
The Request always goes before the response in the user's function parameters.
The `Response` class must have two functions.

- A `send(string)` function which will return text to the request
- and a `json(object)` function which will return JSON to the request setting the appropriate headers

For interpreted languages use the `path` and `file` parameters to find the file and require it.
Please make sure to add appropriate checks to make sure the imported file is a function that you can execute.

5. Finally execute the function and handle whatever response the user's code returns. Try to wrap the function into a `try catch` statement to handle any errors the user's function encounters and return them cleanly to the executor with the error schema.

### 2.4 The Error Schema

All errors that occur during the execution of a user's function **MUST** be returned using this JSON Object otherwise Appwrite will be unable to parse them for the user.

```json
{
    "code": 500, // (Int) Use 404 if function not found or use 401 if the x-internal-challenge check fails.
    "message": "Error: Tried to divide by 0 \n /usr/code/index.js:80:7", // (String) Try to return a stacktrace and detailed error message if possible. This is shown to the user.
}
```

### 2.5 Writing your Dockerfile

The Dockerfile is very important as it's the environment you are creating to run build the runtime and also run the code if you are writing an Interpreted Runtime (Compiled runtimes will use an alpine or ubuntu image)

The first thing you need to do is find a docker image to base your runtime off, You can find these at [Docker Hub](https://hub.docker.com). If possible try to use verified official builds of the language you are creating a runtime for.

Next in your Dockerfile at the start add the docker image you want to base it off at the top like so:

```bash
FROM Dart:2.12 # Dart is used as an example.
```

This will download and require the image when you build your runtime and allow you to use the toolset of the language you are building a runtime for.

Create a user and group for the runtime, this user will be used to both build and run the code:

```bash
RUN groupadd -g 2000 appwrite \
&& useradd -m -u 2001 -g appwrite appwrite
```

then create the folders you will use in your build step:

```bash
RUN mkdir -p /usr/local/src/
RUN mkdir -p /usr/code
RUN mkdir -p /usr/workspace
RUN mkdir -p /usr/builtCode
```

Next copy your source code and set the working directory for the image like so:

```
WORKDIR /usr/local/src
COPY . /usr/local/src
```

Next, you want to make sure you are adding execute permissions to any scripts you may run, the main ones are `build.sh` and `launch.sh`. You can run commands in Dockerfile using the `RUN` prefix like so:
```
RUN chmod +x ./build.sh
RUN chmod +x ./launch.sh
```

Note: Do not chmod a `launch.sh` file if you don't have one.

If needed use the `RUN` commands to install any dependencies you require for the build stage.

Next set the permissions for the user you created so your build and run step will have access to them:

```
RUN ["chown", "-R", "appwrite:appwrite", "/usr/local/src"]
RUN ["chown", "-R", "appwrite:appwrite", "/usr/code"]
RUN ["chown", "-R", "appwrite:appwrite", "/usr/workspace"]
RUN ["chown", "-R", "appwrite:appwrite", "/usr/builtCode"]
```

Finally, you'll add a `CMD` command. For an interpreted language this should be:

```
CMD ["/usr/local/src/launch.sh"]
```

Since this will use your launch script when the runtime starts.

For a compiled language this must be:

```
CMD ["tail", "-f", "/dev/null"]
```

so the build steps can be run.

## 3. Building your Docker image and adding it to the list

With your runtime successfully created you can now move on to building your docker image and adding it to the script files used for generating all of the image files.

Open up the `/runtimes/buildLocalOnly.sh` script first and add your runtime to it. The following is an example with dart version 2.12

```
echo 'Dart 2.12...'
docker build -t dart-runtime:2.12 ./runtimes/dart-2.12
```

Next, open up the `/runtimes/build.sh` script and also add your runtime to it. This one is slightly different as this is the one that will be used for cross-platform compiles and deploying it to Docker hub. The following is an example also with dart version 2.12:

```
echo  'Dart 2.12...'
docker buildx build --platform linux/amd64,linux/arm64 -t dart-runtime:2.12 ./runtimes/dart-2.12/ --push
```

## 4. Adding the runtime to the runtimes list

In `src/Runtimes/Runtimes` create a new entry in the `__construct()` method in the Runtimes class like so:

```
$dart = new Runtime('dart', 'Dart');
$dart->addVersion('2.12', 'dart-runtime:2.12', 'appwrite-ubuntu:20.04', [System::X86, System::ARM]);
$this->runtimes['dart'] = $dart;
```

This is an example of what you would do for a compiled language such as dart.

The first line is creating a new language entry. The first parameter is the internal name and the second one is the external one which is what the user will see in Appwrite.

The second line adds a new version to the language entry, I'll break down the parameters:

```
1: Version - The version of the runtime you are creating.
2: Build Image - The image used to build the code
3: Run Image - The image used to run the code.
For interpreted languages, this is normally the same as the Build Image, but for compiled languages, this can be either "appwrite-alpine:3.13.6" or "appwrite-ubuntu:20.04"
We recommend using Alpine when possible and using Ubuntu if the runtime doesn't work on Alpine.
4: Platforms Supported - These are the architectures this runtime is available to.
```

The third line simply adds the new runtime to the main list.

## 5. Adding tests

### 5.1 Writing your test execution script

Adding tests for your runtime is simple, go into the `/tests/resources` folder and create a folder for the language you are creating then within the folder create a source code file for the language you are writing a runtime for as if you were creating a user function for your runtime. Within this user function you are writing all you need to do is return some JSON with the following schema:

```json
{
    "normal": "Hello World!",
    "env1": request.env['ENV1'], // ENV1 from the request environment variable
    "payload": request.payload, // Payload from the request
}
```

### 5.2 Creating the test packaging script for your runtime

With your test execution written you can move on to writing the script used to package your test execution script into a tarball for later use by the test system. Move into `/test/resources` again and notice how we have shell scripts for all runtimes we have made tests for.

Next create a shell script yourself with your language name. As an example, the shell script name for dart would be `package-dart.sh`

Within this newly created script copy-paste this script and replace all the `LANGUAGE_NAME` parts with your language's name

```
echo  'LANGUAGE_NAME Packaging...'
rm $(pwd)/tests/resources/LANGUAGE_NAME.tar.gz
tar -zcvf $(pwd)/tests/resources/LANGUAGE_NAME.tar.gz -C $(pwd)/tests/resources/LANGUAGE_NAME .
```

Then save this file. Then `cd` into the root of the `runtimes` project in a terminal. Run the following command replacing the `LANGUAGE_NAME` with your language's name:

```
chmod +x ./tests/resources/package-LANGUAGE_NAME.sh && ./tests/resources/package-LANGUAGE_NAME.sh
```

This command adds execution permissions to your script and executes it.

NOTE: If you ever want to repackage your script you can simply run: `./tests/resources/package-LANGUAGE_NAME.sh` in the root of the `runtimes` project since you don't have to change permissions more than once.

### 5.3 Adding your runtime to the main testing script

Now you have created your test execution script and have packaged it up for your runtime to execute you can now add it to the main testing script. Open up the `./tests/Runtimes/RuntimesTest.php` file and find the part where we are defining `$this->tests`.

Once you have found this, Add your own entry into this array like so:

```php
'LANGUAGE_NAME-VERSION'  =>  [
    'code'  =>  $functionsDir .  ' /LANGUAGE_NAME.tar.gz',
    'entrypoint'  =>  'Test file', // Replace with the name of the test file you wrote in ./tests/resources/LANGUAGE_NAME
    'timeout'  =>  15,
    'runtime'  =>  'LANGUAGE_NAME-VERSION',
    'tarname'  =>  'LANGUAGE_NAME-VERSION.tar.gz', // Note: If your version has a point in it replace it with a dash instead for this value.
],
```

Make sure to replace all instances of `LANGUAGE_NAME` with your language's name and `VERSION` with your runtime's version.

Once you have done this and saved it, it is finally time to move onto one of the final steps.

### 5.4 Running the tests.

Running the tests is easy, simply run `docker compose up` in the root of the `runtimes` folder. This will launch a Docker container with the test script and start running through all the runtimes making sure to test them thoroughly.

If all tests pass then congratulations! You can now go ahead and file a PR against the `runtimes` repo making sure to target the `refactor` branch, make sure you're ready to respond to any feedback which can arise during our code review.

## 6. Raise a pull request

First of all, commit the changes with the message `Added XXX Runtime` and push it. This will publish a new branch to your forked version of Appwrite. If you visit it at `github.com/YOUR_USERNAME/runtimes`, you will see a new alert saying you are ready to submit a pull request. Follow the steps GitHub provides, and at the end, you will have your pull request submitted.

## ![face_with_head_bandage](https://github.githubassets.com/images/icons/emoji/unicode/1f915.png) Stuck ?

If you need any help with the contribution, feel free to head over to [our Discord channel](https://appwrite.io/discord) and we'll be happy to help you out.
