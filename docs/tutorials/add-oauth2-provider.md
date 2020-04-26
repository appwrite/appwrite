# Adding a New OAuth2 Provider

OAuth2 providers help users to log into apps and websites without providing passwords or any other type of credentials. Appwrite supports major OAuth2 providers and some less common providers, where possible.

### Adding a new provider

Add a new OAuth2 provider to the list of providers in the config file array located in `./app/config/providers.php`.

```php
Code snippet ...
```

Fill all required data fields. The provider array key name must conform to this format:

- camelCase format 
- no spaces or special characters 
> better say what exactly is allowed. E.g. ASCII a-z,A-Z,-,_unicode?

The name can be tested with this Regex: `[a-z]+[a-zA-Z0-9]*`.

Valid name examples: `exampleOne`, `exampleTwo`. Invalid examples: `ExampleOne`, `example two`, `example_three`.

> REVIEW AND EDIT THIS SECTION!!!

### Adding provider logo

Place the logo image of the new provider in this folder: `./public/images/oauth2`. Your logo should be a *.png* 100×100px file with the same name as the provider name specified in the configuration array, but in lowercase. Leave 30px padding around the logo for visual consistency with other providers.

### Adding provider class

Create a new class that extends the basic OAuth2 provider abstract class in this location:

```bash
./src/Auth/OAuth/ProviderName
```

Note that the class name should start with a capital letter as per [PHP FIG standard requirements](https://www.php-fig.org/bylaws/psr-naming-conventions/).

We recommend implementing the login flow for a new provider by using an existing implementation of another provider as a template. Include references to resources and API docs used to implement the provider's OAuth2 protocol as comments inside the implementation and in the accompanying documentation.

### Testing your provider

You should be able to see the new provider in your Appwrite console after adding the provider configuration and its implementation class under `Project > Users > Providers`. Fill in the new provider settings form to start testing.

Test both, successful and failed login flows. 
> I don't get this one at all >>> (where the user rejects integration on provider page).

You can test the new OAuth2 provider inside your test app by using this OAuth2 method: https://appwrite.io/docs/account#createOAuth2Session from Appwrite JS SDK.

Pass your new adapter name as the provider parameter. If the login is successful, you should be redirected to the URL specified in "success URL" parameter. Otherwise, you will be redirected to "failure URL".

### Submitting a pull request

We welcome pull requests for new OAuth2 providers, but may need more information about the implementation and testing after the initial code review. Unfortunately, the current architecture limits the number of OAuth2 providers that can be accepted.

Make sure you read our [Code of Conduct](../CODE_OF_CONDUCT.md) and [Contributor Guide](../CONTRIBUTING.md) before submitting a pull request.
