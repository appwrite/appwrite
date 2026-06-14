---
layout: post
title: Memberships privacy is now available for all Appwrite plans
description: Appwrite now offers memberships privacy for all plans, making it easier to protect your members' personal information.
date: 2024-12-10
cover: /images/blog/memberships-privacy-announcement/cover.avif
timeToRead: 4
author: ebenezer-don
category: product, announcement
faqs:
  - question: "What is memberships privacy in Appwrite?"
    answer: "Memberships privacy lets you mark specific membership details as private so they are not exposed to other users or team members. You can configure the userName, userEmail, and mfa fields to remain hidden in collaborative workflows. This protects sensitive information without changing how the rest of the app behaves."
  - question: "Which plans support memberships privacy?"
    answer: "Memberships privacy is available on all Appwrite plans, including free and self hosted deployments. You do not need to upgrade to a specific tier to use it. The feature is configured per project from the Appwrite Console."
  - question: "How do I enable memberships privacy on my project?"
    answer: "Open the Appwrite Console, go to Auth, then Security, then Memberships privacy. Select which fields (userName, userEmail, or mfa) should be private and save the configuration. The changes apply to subsequent membership reads within the project."
  - question: "Why hide team member details?"
    answer: "In many apps, team members do not need to see each other's email addresses or MFA status. Exposing those details can leak personal information and create unnecessary attack surface for social engineering. Hiding them keeps collaboration features working while reducing what gets shared across users."
  - question: "Does memberships privacy affect existing membership API calls?"
    answer: "The API still works the same way, but private fields are returned as empty for users without permission to see them. Backend code that needs the full data can still access it through a server API key. Frontend code should handle the redacted values gracefully."
  - question: "Where can I learn more about Appwrite Auth security?"
    answer: "See the [Appwrite Auth documentation](/docs/products/auth) for an overview of authentication, sessions, and security features. The memberships privacy section walks through the specific settings and behavior. You can also ask questions on the Appwrite Discord if you run into edge cases."
---

Protecting user data is fundamental to building secure and reliable applications. We're excited to announce that memberships privacy is now available on all Appwrite plans, enabling you to safeguard your members' personal information effectively.

# Why memberships privacy matters

Many applications don't require members' personal details to be visible to other users or team members. In collaborative environments, for instance, exposing information like email addresses or multi-factor authentication status may be unnecessary and potentially risky. This feature ensures you can implement appropriate privacy controls while maintaining full application functionality.

# What you can do with memberships privacy

Memberships privacy allows you to designate specific membership details as private, giving you precise control over information sharing within your app. You can now configure the following details as private:
- userName: The member's name
- userEmail: The member's email address
- mfa: Whether the member has enabled multi-factor authentication

When set to private, these details remain hidden in team or project workflows unless specifically required.

# How to configure memberships privacy

Implementing memberships privacy is straightforward:
1. Open your Appwrite Console
2. Navigate to Auth > Security > Memberships privacy
3. Select which details (userName, userEmail, or mfa) should be private

This setup ensures sensitive information remains protected where unnecessary.

Have questions or feedback about this feature? Join us on [Discord](https://appwrite.io/discord) - we look forward to hearing how you'll use memberships privacy to enhance your applications' security.

# More resources

- [Memberships privacy documentation](https://appwrite.io/docs/products/auth/security#memberships-privacy)
- [Introducing database backups](https://appwrite.io/blog/post/introducing-database-backups)
- [WebP support now available for Safari on all devices](https://appwrite.io/blog/post/webp-support-for-safari)
