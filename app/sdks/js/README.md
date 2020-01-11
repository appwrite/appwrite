# Appwrite SDK for JavaScript

![License](https://img.shields.io/github/license/appwrite/sdk-for-js.svg?v=1)
![Version](https://img.shields.io/badge/api%20version-0.4.0-blue.svg?v=1)

**This SDK is compatible with Appwrite server version 0.4.0. For older versions, please check previous releases.**

Appwrite backend as a service cuts up to 70% of the time and costs required for building a modern application. We abstract and simplify common development tasks behind a REST APIs, to help you develop your app in a fast and secure way. For full API documentation and tutorials go to [https://appwrite.io/docs](https://appwrite.io/docs)

![Appwrite](https://appwrite.io/images/github.png)

## Installation

### NPM

To install via [NPM](https://www.npmjs.com/):

```bash
npm install appwrite --save
```

If you're using a bundler (like [Browserify](http://browserify.org/) or [webpack](https://webpack.js.org/)), you can import the Appwrite module when you need it:

```js
import * as Appwrite from "appwrite";
```

### CDN

To install with a CDN (content delivery network) add the following scripts to the bottom of your <body> tag, but before you use any Appwrite services:

```html
<script src="https://cdn.jsdelivr.net/npm/appwrite@1.0.28"></script>
```

## Getting Started

Initialise the Appwrite SDK in your code, and setup your API credentials:

```js

// Init your JS SDK
var appwrite = new Appwrite();

appwrite
    .setEndpoint('http://localhost/v1') // Set only when using self-hosted solution
    .setProject('455x34dfkj') // Your Appwrite Project UID
;

```


## License

Please see the [BSD-3-Clause license](https://raw.githubusercontent.com/appwrite/appwrite/master/LICENSE) file for more information.