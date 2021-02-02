# Adding a New OAuth2 Provider

This document is part of the Appwrite contributors' guide. Before you continue reading this document make sure you have read the [Code of Conduct](https://github.com/appwrite/appwrite/blob/master/CODE_OF_CONDUCT.md) and the [Contributing Guide](https://github.com/appwrite/appwrite/blob/master/CONTRIBUTING.md).

## Getting Started

### Agenda

OAuth2 providers help users to log in to the apps and websites without the need to provide passwords or any other type of credentials. Appwrite's goal is to have support from as many **major** OAuth2 providers as possible.

As of the writing of these lines, we do not accept any minor OAuth2 providers. For us to accept some smaller and potentially unlimited number of OAuth2 providers, some product design and software architecture changes must be applied first.

### List Your new Provider

The first step in adding a new OAuth2 provider is to add it to the list in providers config file array, located at:

```
./app/config/providers.php
```

Make sure to fill all data needed and that your provider array key name:

- is in camelCase format 
- has no spaces or special characters.

### Add Provider Logo

Add a logo image to your new provider in this path: `./public/images/oauth2`. Your logo should be a png 100×100px file with the name of your provider (all lowercase). Please make sure to leave about 30px padding around the logo to be consistent with other logos.

### Add Provider Class

Once you have finished setting up all the metadata for the new provider, you need to start coding.

Create a new class that extends the basic OAuth2 provider abstract class in this location:

```bash
./src/Auth/OAuth/ProviderName
```

Note that the class name should start with a capital letter as PHP FIG standards suggest.

Once a new class is created, you can start to implement your new provider's login flow. The best way to do this correctly is to have a look at another provider's implementation and try to follow the same standards.

Please mention in your documentation what resources or API docs you used to implement the provider's OAuth2 protocol.

### Test Your Provider

After you finished adding your new provider to Appwrite you should be able to see it in your Appwrite console. Navigate to 'Project > Users > Providers' and check your new provider's settings form.

Add credentials and check both a successful and a failed login (where the user denies integration on provider page).

You can test your OAuth2 provider by trying to login using the [OAuth2 method](https://appwrite.io/docs/client/account#createOAuth2Session) when integrating the Appwrite Web SDK in a demo app.

Pass your new adapter name as the provider parameter. If login is successful, you will be redirected to your success URL parameter. Otherwise, you will be redirected to your failure URL.

If everything goes well, submit a pull request and be ready to respond to any feedback which can arise during our code review.
