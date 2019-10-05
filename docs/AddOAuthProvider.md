# Adding a New OAuth Provider

This document is part of the Appwrite contributors' guide. Before you continue reading this document make sure you have read the [code of conduct](../CODE_OF_CONDUCT.md) and the [contributing guide](../CONTRIBUTING.md).

## Getting Started

### Agenda

OAuth providers help users to log in easily to apps and websites without the need to provide passwords or any other type of credentials. Appwrite goal is to have support from as many **major** OAuth providers as possible.

As of the writing of these lines, we do not accept any minor OAuth providers. For us to accept some smaller and potentially unlimited number of OAuth providers, some product design and software architecture changes must be applied first.

### List Your new Provider

The first step to follow in adding a new OAuth provider is to add it to the list in providers config file array, located at:

```
./app/config/providers.php
```

Make sure to fill all data needed and that your provider array key name is in camelCase format and has no spaces or special characters.

### Add Provider Logo

Add a logo image to your new provider in this path: `./public/images/oauth`. Your logo should be a png 100×100px file with the name of your provider (all lowercase). Please make sure to leave about 30px padding around the logo to be consistent with other logos.

### Add Provider Class

Once you have finished setting up all the metadata for the new provider, you need to start coding.

Create a new class that extends the basic OAuth provider abstract class in this location:

```bash
./src/Auth/OAuth/ProviderName
```

Note that the class name should start with a capital letter as PHP FIG standards suggest.

Once a new class is created, you can start to implement your new provider's login flow. The best way to do this right is to have a look at another provider's implementation and try to follow the same standards.

Please mention in your documentation what resources or API docs you used to implement the provider's OAuth protocol.

### Test Your Provider

After you finished adding your new provider to Appwrite you should be able to see it in your Appwrite console. Navigate to 'Project > Users > Providers' and check your new provider's settings form.

Add credentials and check both a successful and a failed login (where the user rejects integration on provider page).

If everything goes well, just send us the pull request and be ready to respond to any feedback which can arise during our code review.
