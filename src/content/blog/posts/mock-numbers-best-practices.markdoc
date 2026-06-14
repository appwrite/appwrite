---
layout: post
title: "Mock numbers for phone sign-in: Use cases and best practices"
description: Dive deeper into use cases, best practices and examples of mock numbers in action.
date: 2024-08-23
cover: /images/blog/mock-numbers-use-cases/cover.avif
timeToRead: 5
author: ebenezer-don
category: product
featured: false
faqs:
  - question: "What are mock phone numbers in Appwrite?"
    answer: "Mock phone numbers are predefined phone and verification code pairs you configure in your project to simulate phone authentication. When you use a mock number in a phone sign in flow, Appwrite accepts the matching mock code instead of sending a real SMS. This makes testing phone auth fast and free."
  - question: "Why use mock numbers instead of real SMS in testing?"
    answer: "Real SMS costs money per message and can be delayed by carrier networks, which slows development and QA. Mock numbers remove cost and delay so you can iterate on auth flows quickly. They also let app store reviewers test phone sign in without sharing personal numbers."
  - question: "How do I set up mock phone numbers in Appwrite?"
    answer: "In the Appwrite Console, go to your project, then Auth, then Security, and scroll to Mock phone numbers. Click Generate number to create a mock phone and verification code, then Update to save. You can add multiple mock numbers or edit existing ones from the same screen. See [Appwrite Auth docs](/docs/products/auth) for more details."
  - question: "Are mock numbers safe to use in production?"
    answer: "Mock numbers are intended for development and testing, not for production traffic. They live in your project configuration and bypass real SMS delivery, which is fine for testing but inappropriate for real users. Disable or rotate mock numbers when moving to production so they cannot be misused."
  - question: "Can mock numbers help with App Store review?"
    answer: "Yes, App Store and Play Store reviewers often need to test authentication flows that include phone sign in. Providing mock credentials in your review notes lets them complete sign in without using their own phone numbers. This avoids review delays caused by unreachable phone verification."
  - question: "Do mock numbers work with all phone auth flows?"
    answer: "Yes, mock numbers integrate with the standard Appwrite phone auth flow using createPhoneToken followed by createSession. You pass the mock number and matching mock verification code as you would with a real number. This means your client side code does not need any special handling for tests."
---
If you've ever struggled with testing phone sign-in flows without incurring the cost of real SMS messages or waiting for delivery delays, you'll appreciate the convenience of mock numbers. In Appwrite, mock numbers are predefined phone numbers and verification codes that you can use to simulate phone sign-in scenarios during development and testing.

In this guide, we'll explore the use cases, setup, and best practices for using mock numbers in your development and testing processes.

# Use cases for mock numbers

Mock numbers offer several benefits for developers, testers, and other stakeholders involved in the app development lifecycle. Here are some common use cases for using mock numbers in your projects:

 **1. Development and debugging**

Mock numbers make it easier to develop and debug phone authentication flows. You can quickly test authentication scenarios without waiting for SMS delivery or using real phone numbers.

 **2. Quality assurance testing**

QA teams can consistently test various authentication scenarios with mock numbers, including edge cases, to ensure the app behaves correctly under different conditions.

 **3. App Store review process**

Authentication flow testing might be required when submitting apps to platforms like Google Play and the Apple App Store. Mock numbers allow reviewers to test these features without using personal phone numbers.

 **4. Demonstrations and demos**

Mock numbers simplify the demonstration of phone sign-in functionality during presentations. You can showcase the complete authentication flow without delays or interruptions caused by SMS delivery.

# Setting up mock numbers in Appwrite
To set up mock numbers in Appwrite, you need to follow a few simple steps.

## Step 1: Access the Appwrite console

Log in to your Appwrite account and select the project you want to configure. Ensure phone authentication is enabled for your project.

## Step 2: Configure mock numbers

1. **Navigate to `Project > Auth > Security` :** Scroll to the **Mock phone numbers** section
2. **Generate Mock Number**: Click the **generate number** button. This will generate a mock phone number and verification code that you can use to test user authentication.

    ![Mock-numbers-Appwrite](/images/blog/mock-numbers-use-cases/1.avif)

    You can regenerate the number and verification code by clicking the icon beside the input field or you can edit it directly. To generate another mock phone number and verification code, click the **Add number** button.

3. **Save Changes**: Click the `Update` button to apply the mock number configurations.

## Step 3: Implement mock numbers in your application

Use Appwrite’s SDK to integrate phone sign-in and test the authentication flow with mock numbers. Let's walk through an example using the Appwrite JavaScript SDK.

## Initialize Appwrite client and account

First, you need to set up the Appwrite client and account objects:

```jsx
import { Client, Account } from 'appwrite'

// Initialize Appwrite client
const client = new Client()
client.setEndpoint('https://<REGION>.cloud.appwrite.io/v1').setProject('<PROJECT_ID>')

// Initialize Account
const account = new Account(client)

```

In this code:

- We import the necessary modules from the Appwrite SDK.
- We create and configure a `Client` instance with the Appwrite endpoint and project ID.
- We create an `Account` instance associated with the client.

## Create a phone session using a mock number

Next, create a function to handle phone sign-in with mock numbers:

```jsx
async function createPhoneSession(phone, secret) {
  try {
		// Create a phone token
		const token = await account.createPhoneToken({
			userId: 'unique_id',
			phone: phone.trim()
		})
    const userId = token.userId

		// Create a session using the provided secret
		const session = await account.createSession({
			userId,
			secret
		})
    console.log('Session created:', session)
    return session
  } catch (error) {
    console.error('Error creating phone session:', error)
  }
}

```

Here's what happens in this function:

- The `createPhoneToken` method generates a phone token for the provided phone number.
- We extract the `userId` from the token.
- The `createSession` method creates a session using the `userId` and the provided secret (verification code).
- If successful, the session details are logged; otherwise, the error is logged.

## Example usage

Finally, use the function to create a session with a mock number and verification code:

```jsx
const mockPhoneNumber = 'mock_phone_number'// Replace with your mock numberconst mockSecretCode = 'mock_verification_code'// Replace with your mock verification codecreatePhoneSession(mockPhoneNumber, mockSecretCode)

```

In this example:

- Replace `'mock_phone_number'` with your actual mock number.
- Replace `'mock_verification_code'` with the verification code set for the mock number.

## Step 4: Validate and test

1. **Run tests**: Execute tests using the mock numbers to ensure that the phone sign-in flow behaves as expected.
2. **Check results**: Verify that the session is created successfully and that all parts of the authentication flow are functioning correctly.

# Best practices for using mock numbers

## Consider security

Use mock numbers only in development, testing, and demo environments. Avoid using them in production to maintain the integrity and security of your application’s authentication system.

## Maintain consistency in test scenarios

Ensure that mock numbers and verification codes are used consistently across different testing environments. This consistency helps achieve reliable and repeatable test results.

## Document clearly

Document the mock numbers and codes used in your testing processes. Include details about their purpose, the scenarios they are used for, and any specific configurations.

## Separate environments

Maintain a clear separation between mock setups and production environments. Use environment-specific configurations to avoid accidental use of mock numbers in live applications.

## Recognize testing limitations

Remember that mock numbers are useful for simulating the authentication flow but may not replicate all real-world conditions, such as network delays or SMS delivery issues. For comprehensive testing, real phone numbers and actual SMS delivery may still be necessary.

## Clean up unused mock numbers

Remove mock numbers that are not in use, or delete them once the tests are completed. This practice helps maintain a clean testing environment and prevents the accumulation of obsolete data.

# Conclusion

Mock numbers in Appwrite offer a practical solution for testing phone sign-in flows, facilitating development, QA testing, app reviews, and demonstrations. Mock numbers are available to Pro users (introduced in 1.6).

By using mock numbers effectively and following best practices, you can streamline your testing processes and ensure a robust authentication experience for your users.

Further reading and resources:

- [Appwrite phone (SMS) auth docs](https://appwrite.io/docs/products/auth/phone-sms)
- [Appwrite custom token docs](https://appwrite.io/docs/products/auth/custom-token)
- [Our Discord community](https://appwrite.io/discord)
