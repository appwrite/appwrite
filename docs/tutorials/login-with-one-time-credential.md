# How to Log in Through a One-Time Credential on Appwrite 🔐🚪🔑

This document is part of the Appwrite contributors' guide. Before you continue reading this document make sure you have read the [Code of Conduct](https://github.com/appwrite/.github/blob/main/CODE_OF_CONDUCT.md) and the [Contributing Guide](https://github.com/appwrite/appwrite/blob/master/CONTRIBUTING.md).

Welcome to the world of open-source development with Appwrite! This guide will walk you through the process of logging in through a one-time credential in an Appwrite project. This feature enhances the security and ease of use for authentication in Appwrite-powered applications.

## Getting started 🌟

Appwrite is an open-source backend server that simplifies backend development for web, mobile, native, and backend applications. One of its powerful features is the ability to log in through a one-time credential. In this step-by-step guide, we'll walk you through the process of implementing this feature in your app.

## Prerequisites 📋

Before you start contributing to an Appwrite project and implementing one-time credential login, make sure you have the following prerequisites:

1. **GitHub Account**: You need a GitHub account to contribute to open-source projects. If you don't have one, you can create it for free at [GitHub](https://github.com/).

2. **Appwrite Project**: You should be part of an Appwrite project or open-source repository where you can make contributions. If you don't have access, you can fork an existing repository or create a new one.
   
4. **Appwrite SDK**: Familiarize yourself with the Appwrite SDK for your chosen programming language. You can find SDKs and installation instructions in the [official Appwrite SDK repository](https://github.com/appwrite/appwrite).
   
## How to Implement the One-Time Login Function 🛠️

The first step is to navigate to the [Appwrite Website](https://cloud.appwrite.io) and login. 
If you do not already have an account with Appwrite, then you will have to create an account on [Appwrite website](https://cloud.appwrite.io/register). 
You will also need a basic understanding of the Appwrite Web application to complete this task. If you dont, you may need to take a few minutes to explore (especially) the dashboard before continueing with the next step.

### Step 1: Create a Project in Appwrite

- Navigate to the App dashboard
 
- Click on "Projects" in the sidebar.

- Click the "+ Create Project" button.

- Fill in the project details, such as name and description, and click "Create."

### Step 2: Create a One-Time Login Function

In this step, we'll create a function in your app that generates a one-time credential for logging in.

- Open your code editor and create a new function or endpoint.

- Initialize the Appwrite SDK in your code and set up your project credentials using the Project ID and API Key you obtained in Step 1.

- Use the Appwrite SDK to create a one-time login credential. Here's an example in JavaScript:

```javaScript
{
    const appwrite = new Appwrite();
appwrite
  .setEndpoint('https://your-appwrite-server.com/v1') // Replace with your Appwrite server URL
  .setProject('your-project-id') // Replace with your Appwrite project ID
  .setKey('your-api-key'); // Replace with your API key

appwrite.account.createSession('magic-link', 'https://your-app.com/login')
  .then(session => {
    const magicLink = session.url;
    console.log('Magic Link:', magicLink);
  })
  .catch(error => {
    console.error('Error:', error);
  });

}
```
- This code generates a magic link that users can click to log in securely. You can send this link to users via email or any other method you prefer.

### Step 3: Handle the One-Time Login

In your app, create a login page or route where users can click the magic link to log in.

When a user clicks the magic link, your app should extract the session token from the URL and use it to authenticate the user.

Here's an example in JavaScript for handling the login:

```javaScript
{const urlParams = new URLSearchParams(window.location.search);
const token = urlParams.get('token'); // Get the token from the URL

if (token) {
  // Authenticate the user using the token
  appwrite.account.createOAuth2Session(token, 'magic-link')
    .then(response => {
      // User is authenticated. You can now redirect them to their dashboard or perform any necessary actions.
      console.log('User authenticated:', response);
    })
    .catch(error => {
      console.error('Authentication failed:', error);
    });
}
```
### Step 4: Test Your One-Time Login

- Start your app and navigate to the login page or route.

- Click the magic link generated in Step 2.

- Verify that the user is successfully authenticated and redirected to the appropriate page.

## Contributor's Guide: How to Log in Through a One-Time Credential on Appwrite 

## Step 1: Fork the Repository

1. Before making any changes, you will need to fork Appwrite's repository to keep branches on the official repo clean. To do that, visit the [Appwrite Github repository](https://github.com/appwrite/appwrite) and click on the fork button.

2. Click the "Fork" button in the top-right corner of the repository page. This will create a copy of the repository in your own GitHub account.

### Step 2: Clone the Forked Repository 🧲

1. Open your terminal or command prompt. 

2. Use the `git clone` command to clone your forked repository to your local machine. Replace `<repository_url>` with the URL of your forked repository.

   ```bash
   git clone <repository_url>
   ```
   
### Step 3: Create a New Branch 🌿

1. Change to the directory of the cloned repository.

   ```bash
   cd <repository_directory>
   ```

2. Create a new branch for your work. Replace `<branch_name>` with a descriptive name for your feature or contribution.

   ```bash
   git checkout -b <branch_name>
   ```

### Step 3: Document the One-Time Credential Login 🛠️

1. Locate the `contributor.md` or similar documentation file in the project's repository. This file contains information for contributors.

2. Open the `contributor.md` file in your preferred text editor.

3. Add a new section titled "One-Time Credential Login."

4. Provide detailed instructions for contributors on how to implement one-time credential login using the Appwrite SDK for the project's chosen programming language. You can use the template provided in the previous response as a starting point.

5. Include code examples, explanations, and any specific project-related guidelines for implementing this feature.

6. Save the `contributor.md` file.

## Step 4: Commit and Push Your Changes 🚀

1. In your terminal, stage your changes and commit them with a descriptive message.

   ```bash
   git add contributor.md
   git commit -m "Add instructions for one-time credential login"
    ```

2. Push your changes to your GitHub fork.

   ```bash
   git push origin <branch_name>
   ```

## Step 5: Create a Pull Request 🎣

1. Visit your GitHub fork and navigate to the "Pull Requests" tab.

2. Click the "New Pull Request" button.

3. Choose the base repository (the original Appwrite project) and the branch you want to merge your changes into.

4. Review your changes, provide a title and description for the pull request, and click "Create Pull Request."

### Step 6: Collaborate and Get Feedback 📝🗣️ 🤝

1. Collaborate with other contributors and maintainers to address feedback, make improvements, and ensure your contribution aligns with project standards.

2. Once your pull request is approved, it will be merged into the main project, and your contribution will become part of the Appwrite ecosystem.

Congratulations 🎉👏🥳👍😄! You've successfully contributed to the Appwrite project by adding instructions for logging in through a one-time credential in the contributor.md file. Your contribution will help other developers understand and implement this feature more easily. Thank you for your valuable contribution to the open-source community!

## 🤕 Stuck ?
If you need any help with the contribution, feel free to head over to our Discord channel and we'll be happy to help you out.
