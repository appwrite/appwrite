---
layout: post
title: How password hashing algorithms keep your data safe
description: Learn about password hashing algorithms and how they help secure user credentials in your application.
date: 2023-10-20
cover: /images/blog/password-hashing-algorithms.avif
timeToRead: 7
author: aditya-oberai
category: security
faqs:
  - question: "What is the difference between hashing and encryption?"
    answer: "Hashing is a one way function that turns input into a fixed size output you cannot reverse. Encryption is two way: you can decrypt the data back to its original form with the right key. Passwords should always be hashed, never encrypted."
  - question: "Which password hashing algorithm should I use today?"
    answer: "Argon2id is the current recommendation for new applications. Bcrypt and scrypt are still acceptable if Argon2 is not available. Avoid plain SHA-256 or MD5 since they are far too fast for password use."
  - question: "Why is a salt important when hashing passwords?"
    answer: "A salt is a random value added to each password before hashing, so two users with the same password get different hashes. This blocks rainbow table attacks and forces attackers to crack every hash individually."
  - question: "What hashing algorithm does Appwrite use?"
    answer: "[Appwrite Authentication](/docs/products/auth) uses Argon2id by default when a user signs up from a client app. If you create a user from a server SDK with a pre hashed password, Appwrite accepts Argon2, Bcrypt, MD5, Scrypt, and a few other formats so you can migrate existing users."
  - question: "Can I migrate users from another auth system to Appwrite?"
    answer: "Yes. The server side Users API lets you import users with their existing password hashes if they use a supported algorithm. Your users keep their existing passwords and rehash on next login if needed."
  - question: "What is memory hardness in a password hash?"
    answer: "Memory hardness means the algorithm requires a lot of RAM to compute, not just CPU time. This makes it expensive to attack with GPUs or ASICs in parallel. Argon2 and scrypt are memory hard, while bcrypt and PBKDF2 are not."
---

In today's digital world, keeping sensitive information like passwords secure is extremely important. Password hashing algorithms are essential for protecting user credentials and ensuring authentication systems are reliable.

In this blog, we'll break down how password hashing algorithms work, highlight their key features, and review some of the most popular and secure algorithms. This will help you make informed choices when setting up password storage and verification in your applications.

## What is a password hashing algorithm?

**Password hashing algorithms** are specialized mathematical functions that transform plain text passwords into unique, fixed-size outputs, known as hashes, which are then stored in databases. Through the use of techniques such as salting, adjustable work factors, and memory hardness, modern password hashing algorithms are designed to prevent attacks like rainbow tables and data breaches.

Password hashing involves using a hash function to combine a password with a unique value called a salt. This process is repeated multiple times based on a set difficulty level. Sometimes, additional memory usage is included to make the process even more complex and secure.

This function would operate as follows:

`hash_result = HashFunction(Iterate(Combine(password, salt), work_factor), memory_hardness)`

Where:

- **`Combine(password, salt)`**: Combines the password and salt, possibly using concatenation or a more complex operation.
- **`Iterate(data, n)`**: Repeatedly applies a hash function 'n' times to 'data' to increase computational complexity.
- **`HashFunction(data, memory_hardness)`**: Applies the primary hash function to the data and optionally incorporates memory hardness.

The result is a fixed-size hash that is unique, deterministic, and resistant to reverse engineering, ensuring the secure storage and verification of passwords. For example, inputting a string `loremipsum` into a hashing function that uses the SHA-256 algorithm would output `5245a52778d684fa698f69861fb2e058b308f6a74fed5bf2fe77d97bad5e071c`.

### Characteristics of password hashing algorithms

Password hashing algorithms have certain characteristics:

- **One-way function**
Password hashing algorithms should be one-way functions, so it's nearly impossible to reverse-engineer the original password from the hash. This prevents attackers from getting user passwords from stored hashes.
- **Deterministic**
A password hashing algorithm must always produce the same hash output for a given input to ensure consistency and reliability.
- **Fixed-size output**
Password hashing algorithms must produce a fixed-size output (hash) regardless of the input size. This is necessary when verifying the password inputted by comparing it with its hash.
- **Slow computation**
Unlike general hashing algorithms, which prioritize fast computation, password hashing algorithms should be intentionally slow to compute. This characteristic makes it more time-consuming and resource-intensive for attackers to perform brute-force attacks or attempt to guess passwords using a large number of inputs.
- **Avalanche effect**
A small change in the input should cause a big change in the hash output, making the new output look unrelated to the old one. This makes it hard for attackers to guess the input from the output or find two inputs that create the same hash (collision). For example, the SHA-256 hash for `eight` is `c195d2d8756234367242ba7616c5c60369bc25ced2dcb5b92808d31b58ef217a`, but for `right` is `27042f4e6eca7d0b2a7ee4026df2ecfa51d3339e6d122aa099118ecd8563bad9`, despite having only one different character.
- **Pseudorandomness**
The output of a password hashing algorithm should look random and evenly spread out, so attackers can't find patterns or guess relationships between the inputs and their hash outputs.
- **Resistance to side-channel attacks**
Password hashing algorithms should be designed to resist side-channel attacks. An example of this would timing attacks, where an attacker attempts to gain information about the password or hash by analyzing the time taken to compute the hash.
- **Adjustable work factor**
A good password hashing algorithm should let you adjust the work factor, which means increasing the complexity over time as hardware gets better. This keeps the password hashing process secure and too resource-intensive for dictionary attacks.
- **Memory hardness**
Some modern password hashing algorithms are designed to be memory-hard, meaning that they require a significant amount of memory to compute the hash. This characteristic makes it more difficult for attackers to perform parallel attacks using specialized hardware, such as GPUs or ASICs, which have limited memory resources.
- **Wide adoption and peer review**
A reliable password hashing algorithm should have a proven track record, be widely adopted, and have undergone extensive peer review and analysis by the cryptographic community. This ensures that the algorithm has been tested for vulnerabilities and is considered secure for password storage and verification.

## Examples of password-hashing algorithms

Here are some modern password-hashing algorithms and their concise descriptions:

- **Bcrypt**
Bcrypt is a widely used password hashing algorithm based on the Blowfish cipher. It incorporates a salt and an adjustable work factor to slow down the hashing process, making brute-force attacks more time-consuming. Bcrypt is designed to be resistant to side-channel attacks and is considered secure for password storage.
- **Scrypt**
Scrypt is a memory-hard password hashing algorithm that requires a significant amount of memory to compute the hash, making it more difficult for attackers to perform parallel attacks using specialized hardware. It also supports adjustable work factors and salt usage. Scrypt was specifically designed to protect against hardware-based attacks, such as those using GPUs or ASICs.
- **Argon2**
Argon2 is a modern, memory-hard password hashing algorithm that won the Password Hashing Competition in 2015. It offers adjustable work factors for both time (computation) and memory usage, providing a balance between security and performance. Argon2 supports salting and has three main variants:
    - `Argon2i`: optimized for resistance to side-channel attacks
    - `Argon2d`: optimized for resistance to time-memory trade-off (TMTO) attacks
    - `Argon2id`: a hybrid version of both of the above
- **PBKDF2 (Password-Based Key Derivation Function 2)**
PBKDF2 is a widely-used password hashing algorithm that iteratively applies a pseudorandom function, such as HMAC, to the input password and salt. It supports an adjustable work factor, increasing the number of iterations to make the hashing process slower and more resistant to attacks. While PBKDF2 is considered secure, it is not memory-hard and may be more susceptible to hardware-based attacks compared to `scrypt` or `Argon2`. These modern password hashing algorithms are designed to provide increased security for password storage and verification by incorporating features like salting, adjustable work factors, memory hardness, and resistance to various types of attacks.

## Password hashing and Appwrite

Appwrite Authentication also leverages password hashing algorithms to allow developers to secure their users’ passwords via password hashing algorithms. Appwrite uses the `Argon2id` algorithm to hash the password when a user creates an account from a client-side application. Appwrite’s SDKs offer a simple abstraction for the Appwrite Accounts API to let developers implement this, like the following example:

```client-web
import { Client, Account, ID } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const account = new Account(client);

const user = await account.create({
    userId: ID.unique(),
    email: 'email@example.com',
    password: 'password'
});
```

When a developer implements account creation on a server-side application, however, the Appwrite Users API allows them to input a password that has been hashed using any of the following hashing algorithms:

- Argon2
- Bcrypt
- MD5
- Scrypt
- Scrypt Modified
- PHPass
- SHA

An additional side-benefit this offers is that it allows a developer to migrate users from other platforms, such as Firebase, Supabase, and Nhost, using [Appwrite Migrations](https://appwrite.io/docs/advanced/migrations).

## Recap

In summary, password hashing algorithms are essential tools for securing sensitive user data, particularly passwords, in modern applications. By understanding these algorithms, developers can make informed decisions when implementing password storage and verification systems. and ensure the security and integrity of their users' data.

If you want to implement an authentication system that leverages the power of hashing algorithms simply and quickly, try [Appwrite](https://appwrite.io).