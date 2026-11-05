---
layout: post
title: "Rethinking password security: say goodbye to plaintext passwords"
description: Understanding what passwordless authentication is and how it is a game-changer for both user experience and security.
date: 2024-03-22
cover: /images/blog/goodbye-plaintext-passwords/cover.avif
timeToRead: 6
author: aditya-oberai
category: security
faqs:
  - question: "Why is storing passwords in plaintext dangerous?"
    answer: "If anyone gains access to your database, they immediately have every user's password. Because most people reuse passwords across services, a single plaintext leak compromises accounts on other sites too. Plaintext storage also violates GDPR, PCI DSS, HIPAA, and most modern security frameworks, exposing your company to fines and lawsuits."
  - question: "What is password hashing and how is it different from encryption?"
    answer: "Hashing is a one way transformation that converts a password to a fixed length string that cannot be reversed back to the original. Encryption is two way, meaning anyone with the key can decrypt the value. Passwords should always be hashed, never encrypted, because your system never needs the original password back, only a way to verify a login attempt matches."
  - question: "Which password hashing algorithm should I use?"
    answer: "Use a modern memory hard algorithm like Argon2id, bcrypt, or scrypt. Avoid MD5, SHA-1, and plain SHA-256 since they are too fast and trivial to brute force on modern GPUs. Argon2id is the current recommendation from OWASP and is what Appwrite Authentication uses by default."
  - question: "What is passwordless authentication?"
    answer: "Passwordless authentication verifies users without a stored password, typically through magic links, one time codes, biometrics, or hardware tokens. It eliminates entire classes of attacks (phishing, credential stuffing, password reuse) and usually improves user experience. The trade off is dependency on email, SMS, or device security for the auth factor."
  - question: "How does Appwrite handle password security?"
    answer: "[Appwrite Authentication](/docs/products/auth) hashes passwords with Argon2id by default, supports passwordless flows like magic URLs and OTPs, integrates 30+ OAuth providers, and offers two factor authentication. You never store plaintext passwords yourself, and Appwrite's permissions system prevents accidental data exposure even if your client code has bugs."
  - question: "What should I do if I am currently storing passwords in plaintext?"
    answer: "Stop new plaintext storage immediately, then migrate existing passwords on next login by hashing each one as users sign in. Force a password reset for accounts that have not logged in within a reasonable window. Disclose the situation if any plaintext data was exposed publicly, since GDPR and similar laws require breach notifications."
---

Recently, we came across a report by [BleepingComputer](https://www.bleepingcomputer.com/news/security/misconfigured-firebase-instances-leaked-19-million-plaintext-passwords/), which shared how misconfigured Firebase projects led to the leakage of 19 million plaintext passwords on the public internet. This was primarily caused by missing or incorrectly configured security rules on Firebase instances that consequently permitted read access to databases, resulting in a massive data leak that exposed:

- Names: 84,221,169
- Emails: 106,266,766
- Phone numbers: 33,559,863
- Passwords: 20,185,831
- Billing info (bank details, invoices, etc.): 27,487,924

For passwords, the problem was a lot worse because 98% of them, or 19,867,627 to be exact, were in plaintext. While this problem is not inherently a Firebase bug, it does signify a massive lack of understanding around authentication tooling and password security. Therefore, this blog will discuss why plaintext passwords are bad for your security and alternative solutions.

# The dangers of plain text passwords

Storing passwords in plaintext presents significant security risks and vulnerabilities for both users and organizations. Here are several vital points illustrating why plaintext passwords are bad:

- **An easy target for hackers**: Plaintext passwords can be easily read and exploited by anyone who gains unauthorized access to the storage database, making them prime targets for hackers.
- **Lack of confidentiality**: Storing passwords in plaintext fails to protect the confidentiality of user information. If a security breach occurs, users’ passwords are exposed without any layer of protection.
- **Non-compliance with security standards**: Many industry standards and regulations, such as GDPR, PCI DSS, and HIPAA, require that personal data, including passwords, be adequately protected, and storing passwords in plaintext can result in non-compliance.
- **Increased risk of insider threats**: Plaintext passwords are vulnerable to external threats and insider threats. Employees with access to the database can view and potentially misuse this information, leading to internal security breaches.
- **Higher impact of data breaches**: When passwords are stored in plaintext, the impact of a data breach is magnified. Attackers can access the compromised system and use the same credentials to attempt access to other services, exploiting the common habit of password reuse.
- **No defense against brute force attacks**: Plaintext passwords offer no defense against brute force attacks. While hashed passwords can also be vulnerable, hashing makes decoding user passwords a lot more difficult.
- **Damage to reputation**: A company found to be storing passwords in plaintext can suffer significant damage to its reputation. Users and clients lose trust in a business that doesn’t prioritize their information’s security.

# Alternatives to plaintext passwords

Fortunately, modern authentication systems are built with the drawbacks of plaintext passwords kept in mind. Two major solutions that have been developed to solve this problem are as follows:

## Password hashing

Password hashing is a security technique that converts a plaintext password into a fixed-size string of characters, which is virtually impossible to reverse. This process uses a hash function to transform the original password, ensuring that even if the data storage is compromised, the actual passwords are not easily deciphered.

Benefits of password hashing compared to storing passwords in plaintext include:

- **Enhanced security**: Hashed passwords are extremely difficult to reverse-engineer, providing a higher level of security against data theft.
- **Data breach protection**: In the event of a breach, hashed passwords significantly reduce the risk of passwords being used for unauthorized access.
- **Compliance with standards**: Hashing passwords helps organizations comply with data protection and privacy regulations.
- **User trust**: By securing passwords effectively, businesses can maintain and even boost user trust by demonstrating a commitment to privacy and security.
- **Mitigation of reuse attacks**: Hashed passwords make it harder for attackers to use stolen credentials on other sites, reducing the impact of credential reuse attacks.
- **Scalability and future-proofing**: Hashing algorithms can be updated and strengthened over time, providing flexibility as security standards evolve.

You can read our [blog on password hashing algorithms](/blog/post/password-hashing-algorithms) to learn more.

## Passwordless authentication

Passwordless authentication eliminates the need for users to create and remember passwords, aiming to enhance both security and user experience. Instead of relying on a traditional password, passwordless methods verify identity through alternative means. This approach can significantly reduce the risk of phishing attacks and password theft, as there’s no password to steal or guess.

Some common passwordless authentication methods are:

- **Biometric verification**: Uses unique biological characteristics, such as fingerprints or facial recognition, for identification.
- **Magic links**: Sends a one-time use URL to the user’s registered email address for login.
- **One-time passwords (OTPs)**: Generates a single-use code sent via SMS or email.
- **Authentication apps**: Generates time-based codes or push notifications for user approval.
- **Smart cards and security tokens**: Physical devices that the user must possess to gain access.

You can read our [blog on passwordless authentication](/blog/post/improve-ux-passwordless-auth) to learn more.

# How Appwrite solves this problem

[Appwrite Authentication](/docs/products/auth) makes building secure and robust authentication easy and supports many authentication methods. Currently, the following authentication methods are available on Appwrite to combat plaintext passwords:

- Email and password login with `Argon2id` password hashing
- OTP-based login via SMS and email
- Magic URL login via email
- 30+ external OAuth2 providers, including Google, Facebook, GitHub, LinkedIn, and Apple
- JSON Web Token ([JWT](https://jwt.io/)) creation to extend user authentication to server-side functions
- Custom token login to allow integration with any external authentication solution of your choice
- Two-factor authentication to add an additional layer of security to user accounts

Additionally, to prevent unauthorized data access, Appwrite features a [robust permissions system](/docs/advanced/platform/permissions) that is thoroughly coupled with Appwrite Authentication. The most significant benefit of Appwrite’s permissions system is that any developer needs to offer read or write access to any user intentionally. No default rule provides open access to your application’s client, and the process of setting permissions is simplified by using Appwrite’s console UI.

Appwrite also enforces the setting of authorized clients to communicate with an Appwrite project for all supported client platforms (web, Android, Apple, and Flutter). This way, no unauthorized clients can access data, adding an extra layer of protection.

# Moving forward

No matter what rules and systems any platform provides, combating plaintext passwords is a responsibility shared by all application developers. If you, as a developer, care about the security and privacy of your user accounts and data, you must take the necessary actions to safeguard their authentication information. Appwrite takes this problem very seriously and will continue to add new tools and improve our existing ones to make user authentication simpler and safer for all developers and their users.

Learn more about Appwrite Authentication through our [docs](/docs/products/auth) and join our [Discord server](https://appwrite.io/discord).