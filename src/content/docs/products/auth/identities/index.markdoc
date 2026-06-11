---
layout: article
title: Identities
description: Handle multiple authentication methods per user through a unified system that maintains consistent identity across providers.
---

Identities enable linking multiple authentication methods to a single user account. This allows users to access a unified account through various OAuth2 providers.

An identity is another way to refer to a user account. A single user can have multiple identities, each corresponding to different authentication methods. Currently, identities are primarily used with OAuth2 providers. When a user logs in via an OAuth2 provider, an identity is created and linked to their Appwrite account. This system enables:

- Connecting multiple OAuth2 accounts to a single Appwrite account
- Maintaining consistent access regardless of login method
- Tracking which external providers are linked to an account

# Use cases {% #use-cases %}

Identities are primarily used in the following scenarios:

1. **OAuth2 authentication**: When users authenticate through any OAuth2 provider
2. **Account management**: When users want to link or unlink external provider accounts
3. **User profile consolidation**: When maintaining a single user profile across multiple authentication methods

# Create new identities {% #create-new-identities %}

To create a new identity:

1. The user must be logged into their Appwrite account
2. Initiate the OAuth2 authentication flow for the desired provider
3. The new identity will be automatically created and linked to the current account

For implementation details and code examples, refer to the [OAuth2 documentation](/docs/products/auth/oauth2).

# Manage email addresses {% #manage-email-addresses %}

Each email address must be unique across all users and identities. For example, if a user with email `joe@example.com` creates an identity using `other@company.com`, that second email becomes reserved.
This means no other user can create either a new account or a new identity using `other@company.com`. This restriction helps maintain consistent user identity across your application.

# List and delete identities {% #list-and-delete-identities %}

Users and administrators can manage identities through various operations available in the Account API:

- [List identities](/docs/references/cloud/client-web/account#listIdentities)
- [Delete an identity](/docs/references/cloud/client-web/account#deleteIdentity)

For detailed API specifications and code examples, refer to the [Account API Reference](/docs/references/cloud/client-web/account).

# Clean up identities {% #clean-up-identities %}

When a user account is deleted:

- Associated identities (and related targets) are removed via a background job
- This deletion is asynchronous and may not be immediate due to queue processing times
- In testing scenarios where instant deletion is required, manually remove identities (and targets) before deleting the user account

# Best practices {% #best-practices %}

A good user experience typically includes clear visibility of connected providers and straightforward identity management.

Verify email addresses where possible and implement proper session management. Secure identity deletion can help prevent unauthorized access.

Testing should ideally cover the cleanup of test identities and email conflict scenarios.
