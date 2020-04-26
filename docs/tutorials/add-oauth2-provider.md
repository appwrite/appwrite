# Adding a New OAuth2 Provider

This document is part of the Appwrite contributor guide. Make sure you read [Code of Conduct](../CODE_OF_CONDUCT.md) and the [Contributing Guide](../CONTRIBUTING.md) before making a pull request.

## Getting Started

### Agenda

OAuth2 providers help users to log into apps and websites without providing passwords or any other type of credentials. Appwrite supports **major** OAuth2 providers and some less common providers, where possible. The current architecture limits the number of OAuth2 providers that can be accepted.

### Adding a new provider

Add a new OAuth2 provider to the list in providers config file array located in `./app/config/providers.php`.

Fill all required data fields. The provider array key name must conform to this format:

- camelCase format 
- no spaces or special characters ??? `better say what exactly is allowed. E.g. ASCII a-z,A-Z,-,_unicode?`

Valid examples: `exampleOne`, `exampleTwo`, `example_three`. Invalid examples: `ExampleOne`, `example two`.

### Adding provider logo

Place the logo image of the new provider in this folder: `./public/images/oauth2`. Your logo should be a *.png* 100×100px file with the same name as the provider name, but in lowercase. Leave 30px padding around the logo for consistency with other logos.

### Adding provider class

Create a new class that extends the basic OAuth2 provider abstract class in this location:

```bash
./src/Auth/OAuth/ProviderName
```

Note that the class name should start with a capital letter as PHP FIG standards suggest.

Once the new class is created, you can start implementing your new provider's login flow. The best way to do this correctly is to use an existing implementation of another provider as a template.

Include references to resources and API docs used to implement the provider's OAuth2 protocol in your documentation.

### Testing your provider

You should be able to see the new provider in your Appwrite console after you finished adding the provider to Appwrite. Navigate to `Project > Users > Providers` and check the new provider's settings form.

Add credentials and check both a successful and a failed login flows(where the user rejects integration on provider page).

You can test the new OAuth2 provider by using this OAuth2 method (https://appwrite.io/docs/account#createOAuth2Session) from Appwrite JS SDK in the demo app.

Pass your new adapter name as the provider parameter. If the login is successful, you will be redirected to "success URL" parameter. Otherwise, you will be redirected to "Failure URL".

We welcome pull requests for new OAuth2 providers, but may need more information about the implementation and testing after the initial code review.
