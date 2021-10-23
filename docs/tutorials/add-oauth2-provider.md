# Adding a new OAuth2 provider 🛡

This document is part of the Appwrite contributors' guide. Before you continue reading this document, make sure you have read the [Code of Conduct](https://github.com/appwrite/appwrite/blob/master/CODE_OF_CONDUCT.md) and the [Contributing Guide](https://github.com/appwrite/appwrite/blob/master/CONTRIBUTING.md).

## Getting started

OAuth2 providers help users log in to the apps and websites without providing passwords or any other type of credentials. Appwrite's goal is to have support from as many **major** OAuth2 providers as possible.

As of the writing of these lines, we do not accept any minor OAuth2 providers. Some product design and software architecture changes must be applied first to obtain some smaller and potentially unlimited number of OAuth2 providers.

## 1. Prerequisites

It's straightforward to contribute to an open-source project, but when using GitHub, there are a few steps we need to follow. This section will take you step-by-step through preparing your local version of Appwrite, where you can make any changes without affecting Appwrite right away.

> If you are experienced with GitHub or have made a pull request before, you can skip to [Implement new provider](#2-implement-new-provider).

###  1.1 Fork the Appwrite repository

Before making any changes, you will need to fork Appwrite's repository to clean branches on the official repo. To do that, visit the [Appwrite Github repository](https://github.com/appwrite/appwrite) and click on the fork button.

![Fork button](images/fork.png)

This will redirect you from `github.com/appwrite/appwrite` to `github.com/YOUR_USERNAME/appwrite,` meaning all changes you do are only done inside your repository. Once you are there, click the highlighted `Code` button, copy the URL and clone the repository to your computer using the `git clone command:

```shell
$ git clone COPIED_URL
```

> To fork a repository, you will need a basic understanding of CLI and git-CLI binaries installed. If you are a beginner, we recommend you to use `Github Desktop.` It is a clean and straightforward visual Git client.

Finally, you will need to create a `feat-XXX-YYY-oauth` branch based on the `master` branch and switch to it. The `XXX` should represent the issue ID and `YYY` the OAuth provider name.

## 2. Implement new provider

### 2.1 List your new provider

The first step in adding a new OAuth2 provider is to add it to the list of providers located at:

```
app/config/providers.php
```

Make sure to fill in all data needed and that your provider array key name:

- is in [`camelCase`](https://en.wikipedia.org/wiki/Camel_case) format 
- has no spaces or special characters

>  Please make sure to keep the list of providers in `providers.php` in the alphabetical order A-Z.

### 2.2 Add Provider Logo

Add a logo image to your new provider in this path: `public/images/users.` Your logo should be a png 100×100px file with the name of your provider (all lowercase). Please make sure to leave about 30px padding around the logo to be consistent with other logos.

### 2.3 Add Provider Class

Once you have finished setting up all the metadata for the new provider, you need to start coding.

Create a new file `XXX.php` where `XXX` is the name of the OAuth provider in [`PascalCase`](https://stackoverflow.com/a/41769355/7659504) in this location
```bash
src/Appwrite/Auth/OAuth2/XXX.php
```

Inside this file, create a new class that extends the essential OAuth2 provider abstract class. Note that the class name should start with a capital letter, as PHP FIG standards suggest.

Once a new class is created, you can start to implement your new provider's login flow. We have prepared a starting point for the Oauth provider class below, but you should also consider looking at other providers' implementation and following the same standards.

```php
<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// [DOCS FROM OAUTH PROVIDER]

class [PROVIDER NAME] extends OAuth2
{
    /**
     * @var string
     */
    private $endpoint = '[ENDPOINT API URL]';
    
    /**
     * @return string
     */
    public function getName(): string
    {
        return '[PROVIDER NAME]';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $url = $this->endpoint . '[LOGIN_URL_STUFF]';
        return $url;
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code): string
    {
        // TODO: Fire request to oauth API to generate access_token
        $accessToken = "[FETCHED ACCESS TOKEN]";
        
        return $accessToken;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserID(string $accessToken): string
    {
        // TODO: Fetch user from oauth API and select the user ID
        $userId = "[FETCHED USER ID]";
        
        return $userId;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        // TODO: Fetch user from oauth API and select the user's email
        $userEmail = "[FETCHED USER EMAIL]";
        
        return $userEmail;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        // TODO: Fetch user from oauth API and select the username
        $username = "[FETCHED USERNAME]";
        
        return $username;
    }
}
```

> If you copy this template, make sure to replace all placeholders wrapped like `[THIS]` and implement everything marked as `TODO:.`

Please mention what resources or API docs you used to implement the provider's OAuth2 protocol in your documentation.

## 3. Test your provider

After you finish adding your new provider to Appwrite, you should be able to see it in your Appwrite console. Navigate to 'Project > Users > Providers' and check your new provider's settings form.

> To start Appwrite console from the source code, you can run `docker-compose up -d'.

Add credentials and check a successful and a failed login (where the user denies integration on the provider page).

You can test your OAuth2 provider by logging in using the [OAuth2 method](https://appwrite.io/docs/client/account#accountCreateOAuth2Session) when integrating the Appwrite Web SDK in a demo app.

Pass your new adapter name as the provider parameter. If login is successful, you will be redirected to your success URL parameter. Otherwise, you will be redirected to your failure URL.

If everything goes well, raise a pull request and respond to any feedback that can arise during our code review.

## 4. Raise a pull request

First of all, commit the changes with the message `Added XXX OAuth2 Provider` and push it. This will publish a new branch to your forked version of Appwrite. If you visit it at `github.com/YOUR_USERNAME/appwrite,` you will see a new alert saying you are ready to submit a pull request. Follow the steps GitHub provides, and at the end, you will have your pull request submitted.

## 🤕 Stuck?
If you need any help with the contribution, feel free to head over to [our discord channel](https://appwrite.io/discord), and we'll be happy to help you out.
