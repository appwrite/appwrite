---
layout: post
title: Understanding OAuth and OpenID Connect
description: How OAuth and OpenID Connect work and why they are beneficial for application security.
date: 2024-03-07
cover: /images/blog/oauth-openid.avif
timeToRead: 6
author: aditya-oberai
category: authentication, security
faqs:
  - question: "What is the difference between OAuth and OpenID Connect?"
    answer: "OAuth 2.0 is an authorization framework that lets an app access resources on another service on behalf of a user. OpenID Connect (OIDC) is an identity layer built on top of OAuth 2.0 that adds authentication, returning an ID token with verified user information. Use OAuth when you need access to APIs, and OIDC when you need to know who the user is."
  - question: "Why should I use PKCE with OAuth?"
    answer: "PKCE (Proof Key for Code Exchange) protects the authorization code flow from interception attacks, especially in mobile and single page apps that cannot safely store a client secret. It is now considered a best practice for any OAuth client, including confidential ones."
  - question: "What is an ID token in OpenID Connect?"
    answer: "An ID token is a signed JWT that contains claims about the authenticated user, such as their subject ID, email, and the issuer. Your app validates the signature and claims to confirm the user's identity instead of making a separate API call."
  - question: "Does Appwrite support OAuth and OpenID Connect?"
    answer: "Yes. [Appwrite Authentication](/docs/products/auth) supports over 30 OAuth 2.0 providers out of the box, plus a generic OIDC adapter for any standards compliant identity provider. You configure the client ID, secret, and endpoints, then trigger the flow with a few SDK calls."
  - question: "How do I add a custom OpenID Connect provider to Appwrite?"
    answer: "Use the generic OIDC adapter in Appwrite Authentication. You provide the client ID, client secret, well known endpoint, authorization endpoint, token endpoint, and user info endpoint, and Appwrite handles the redirects and token exchange for you."
  - question: "Is OAuth secure enough for production apps?"
    answer: "Yes, when implemented correctly. Always use HTTPS, validate redirect URIs, use the state parameter to prevent CSRF, use PKCE for public clients, validate ID tokens, and request the minimum scopes you need. Skipping these steps is what makes OAuth implementations insecure."
---

When it comes to web security, **OAuth** and **OpenID Connect (OIDC)** have revolutionized how we manage and secure user authentication and authorization. OAuth, primarily a protocol for authorization, enables third-party access to user data without exposing their credentials. On the other hand, OIDC, built on top of OAuth 2.0, extends this protocol by adding user authentication. These technologies provide a secure, efficient way for users to log in and share information across different services without compromising their privacy or security.

# What is OAuth?

OAuth is an open standard for access delegation commonly used as a way for internet users to grant websites or applications access to their information on other websites without giving them the actual user passwords. It's particularly useful for providing controlled access to resources hosted by a third-party service. OAuth 2.0, the latest version, simplifies the earlier protocol, offering improved security and interoperability.

## The OAuth 2.0 authorization flow

1. **User authorization request**: The process begins when a user tries to access a resource or service that requires them to log in via a third-party service (like logging into a website using their Google account). The website (client) redirects the user to log in to the third-party service (authorization server).
2. **User login**: The user logs in to the third-party service. This step involves user authentication by the third-party service, but this authentication process is not part of OAuth; it's handled entirely by the third-party service.
3. **Grant access**: After successful login, the third-party service asks the user if they want to grant the requesting website access to their information. If the user consents, the third-party service (authorization server) redirects the user back to the website with an authorization code.
4. **Access token request**: The website (now having the authorization code) makes a separate request to the third-party service, exchanging the authorization code for an access token.
5. **Access token response**: The third-party service responds with an access token (and sometimes a refresh token).
6. **Resource access**: The website uses this access token to make requests to the third-party service’s resource server to access the user’s information. The resource server validates the access token and returns the requested resource.

## Use cases of OAuth 2.0

OAuth 2.0 is widely used for secure, delegated access across various applications. Some of its use cases include:

- **Social media logins**: Allowing users to log in to websites and apps using their social media accounts.
- **Third-party application access**: Enabling apps to access services like email or calendars on behalf of the user.
- **Single Sign-On (SSO)**: Facilitating one-login access to multiple enterprise applications.
- **Payment gateway access**: Letting third-party vendors access transaction details or initiate payments via services like PayPal.
- **Mobile app integrations**: Connecting mobile apps with other services and platforms for enhanced functionality.

# What is OpenID Connect?

OpenID Connect is a simple identity layer built on top of OAuth 2.0. It allows clients to verify the user's identity based on the authentication performed by an Authorization Server, as well as to obtain basic profile information about the user in an interoperable way.

OIDC uses OAuth 2.0 as its foundation. While OAuth 2.0 provides a framework for authorization, OIDC extends this to include authentication, delivering a more holistic security solution.

The core components of OpenID Connect include the ID token (a JWT that contains information about the user's session and authentication information), the User Info endpoint (to fetch user profile data), and the Discovery document (a JSON document listing crucial OIDC endpoints). The authentication process typically involves redirecting the user to an Authorization Server, logging in, and then being redirected back to the application with an ID Token and Access Token.

# Comparing OAuth and OpenID Connect

OAuth 2.0 and OpenID Connect are closely related standards used in online identity and access management, but they serve different purposes. Here are some key differences:

- **Primary focus**:
    - **OAuth 2.0** is a framework for authorization. It allows applications to obtain limited access to a user's data on another application or service.
    - **OpenID Connect** is built on top of OAuth 2.0 and is specifically designed for authentication. It allows applications to verify the identity of a user and obtain basic profile information.
- **Token usage**:
    - **OAuth 2.0** provides access tokens, which are used by applications to access APIs on behalf of the user.
    - **OpenID Connect** provides an ID Token in addition to the access token. The ID Token is a JWT (JSON Web Token) used for identity verification and contains user profile information.
- **User information**:
    - **OAuth 2.0** doesn't standardize (or provide) information about the user. It focuses on the scopes and permissions given to the access token.
    - **OpenID Connect** standardizes the way applications can receive identity information about the user in a secure and RESTful manner.
- **Scenarios**:
    - **OAuth 2.0** is used when an application needs to perform actions or access resources from another service on behalf of the user without needing to know the user's identity.
    - **OpenID Connect** is used when an application needs to authenticate the user and potentially access basic profile information.
- **Compatibility and extension**:
    - **OAuth 2.0** serves as a base for various protocols, including OpenID Connect.
    - **OpenID Connect** is an extension of OAuth 2.0, adding an additional layer specifically for user authentication.

## Security best practices

Implementing OAuth and OIDC securely involves using secure channels for all communications, validating all tokens, employing state parameters to mitigate CSRF attacks, and regularly updating and auditing your security configurations. Here are some best practices for ensuring security in OAuth and OIDC implementations:

- **Use HTTPS**: Always use HTTPS to protect the data in transit against interception and tampering. This is essential for all communications in OAuth and OpenID Connect flows.
- **Validate redirect URIs**: Strictly validate redirect URIs to prevent redirection attacks. Ensure that the redirect URI in an authorization request matches the one registered with the authorization server.
- **Use 'state' parameter**: Implement the 'state' parameter in OAuth flows to mitigate Cross-Site Request Forgery (CSRF) attacks. This parameter should be a random value that is validated at the beginning and end of the flow.
- **Utilize PKCE (Proof Key for Code Exchange)**: Especially in mobile and single-page applications, use PKCE to enhance the security of the authorization code flow.
- **Secure client credentials**: Store client credentials securely. Avoid exposing client secrets in client-side code.
- **Token security**: Use access tokens and ID tokens securely. Avoid storing tokens in insecure locations. Implement token expiration and refresh token rotation.
- **Validate ID tokens**: When using OpenID Connect, properly validate the ID token, especially its signature and the issuer.
- **Implement scopes and consent appropriately**: Define scopes clearly and request only the permissions that are necessary. Always get user consent where applicable.

# Using OAuth and OpenID Connect with Appwrite

Appwrite Authentication features support for over 30 OAuth 2.0 adapters, including a generic OIDC adapter, out of the box for developers to implement. To set up the generic OIDC adapter, you need the following details:

- Client ID
- Client Secret
- Well-Known Endpoint
- Authorization Endpoint
- Token Endpoint
- User Info Endpoint

Appwrite provides the necessary redirect URL to add to your OIDC app configuration.

Once that is done, you can use any of the Appwrite client SDKs to implement OAuth 2.0 or OIDC authentication in just a few lines of code.

```client-web
import { Client, Account, OAuthProvider } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const account = new Account(client);

account.createOAuth2Session({
    provider: OAuthProvider.Oidc,
    success: '<LINK_ON_SUCCESS>',
    failure: '<LINK_ON_FAILURE>'
});
```

To learn more about Appwrite and OAuth 2.0, ensure you visit the [Appwrite documentation](https://appwrite.io/docs/products/auth/oauth2).