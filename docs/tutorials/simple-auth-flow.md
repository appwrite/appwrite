# Simple Authentication Flow Tutorial

This tutorial shows you how to implement a complete authentication flow with Appwrite in just a few steps. Perfect for beginners who want to understand how user registration, login, and logout work together.

## What You'll Build

A simple web app with:
- User registration form
- Login form  
- User profile display
- Logout functionality

## Prerequisites

- Basic HTML/JavaScript knowledge
- An Appwrite project (create one at [cloud.appwrite.io](https://cloud.appwrite.io))

## Step 1: Setup Your HTML

Create a simple HTML file with forms for registration and login:

```html
<!DOCTYPE html>
<html>
<head>
    <title>Simple Auth with Appwrite</title>
    <style>
        .form-group { margin: 10px 0; }
        .hidden { display: none; }
        button { padding: 10px; margin: 5px; }
        input { padding: 8px; width: 200px; }
    </style>
</head>
<body>
    <div id="auth-forms">
        <h2>Register</h2>
        <form id="register-form">
            <div class="form-group">
                <input type="text" id="register-name" placeholder="Your name" required>
            </div>
            <div class="form-group">
                <input type="email" id="register-email" placeholder="Email" required>
            </div>
            <div class="form-group">
                <input type="password" id="register-password" placeholder="Password" required>
            </div>
            <button type="submit">Register</button>
        </form>

        <h2>Login</h2>
        <form id="login-form">
            <div class="form-group">
                <input type="email" id="login-email" placeholder="Email" required>
            </div>
            <div class="form-group">
                <input type="password" id="login-password" placeholder="Password" required>
            </div>
            <button type="submit">Login</button>
        </form>
    </div>

    <div id="user-profile" class="hidden">
        <h2>Welcome!</h2>
        <p>Name: <span id="user-name"></span></p>
        <p>Email: <span id="user-email"></span></p>
        <button id="logout-btn">Logout</button>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/appwrite@16.0.2"></script>
    <script src="auth.js"></script>
</body>
</html>
```

## Step 2: Initialize Appwrite

Create `auth.js` and set up your Appwrite client:

```javascript
import { Client, Account } from "appwrite";

// Initialize the Appwrite client
const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Replace with your endpoint
    .setProject('YOUR_PROJECT_ID'); // Replace with your project ID

const account = new Account(client);

// Get DOM elements
const authForms = document.getElementById('auth-forms');
const userProfile = document.getElementById('user-profile');
const registerForm = document.getElementById('register-form');
const loginForm = document.getElementById('login-form');
const logoutBtn = document.getElementById('logout-btn');

// Check if user is already logged in
checkCurrentUser();
```

## Step 3: Add Registration Logic

```javascript
registerForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const name = document.getElementById('register-name').value;
    const email = document.getElementById('register-email').value;
    const password = document.getElementById('register-password').value;
    
    try {
        // Create a new user account
        const user = await account.create(
            'unique()', // Let Appwrite generate a unique ID
            email,
            password,
            name
        );
        
        console.log('User created:', user);
        alert('Registration successful! You can now login.');
        
        // Clear the form
        registerForm.reset();
        
    } catch (error) {
        console.error('Registration failed:', error);
        alert('Registration failed: ' + error.message);
    }
});
```

## Step 4: Add Login Logic  

```javascript
loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const email = document.getElementById('login-email').value;
    const password = document.getElementById('login-password').value;
    
    try {
        // Create a new session (login)
        const session = await account.createEmailPasswordSession(email, password);
        console.log('Login successful:', session);
        
        // Show user profile
        await showUserProfile();
        
    } catch (error) {
        console.error('Login failed:', error);
        alert('Login failed: ' + error.message);
    }
});
```

## Step 5: Display User Profile

```javascript
async function showUserProfile() {
    try {
        // Get current user info
        const user = await account.get();
        
        // Update UI with user info
        document.getElementById('user-name').textContent = user.name;
        document.getElementById('user-email').textContent = user.email;
        
        // Hide auth forms and show profile
        authForms.classList.add('hidden');
        userProfile.classList.remove('hidden');
        
    } catch (error) {
        console.error('Failed to get user info:', error);
    }
}
```

## Step 6: Add Logout Functionality

```javascript
logoutBtn.addEventListener('click', async () => {
    try {
        // Delete current session (logout)
        await account.deleteSession('current');
        
        // Show auth forms and hide profile
        authForms.classList.remove('hidden');
        userProfile.classList.add('hidden');
        
        // Clear forms
        registerForm.reset();
        loginForm.reset();
        
        console.log('Logout successful');
        
    } catch (error) {
        console.error('Logout failed:', error);
    }
});
```

## Step 7: Check for Existing Session

```javascript
async function checkCurrentUser() {
    try {
        // Check if user is already logged in
        const user = await account.get();
        await showUserProfile();
    } catch (error) {
        // User is not logged in, show auth forms
        console.log('No active session');
    }
}
```

## Complete Code

Here's the complete `auth.js` file:

```javascript
import { Client, Account } from "appwrite";

// Initialize Appwrite
const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') 
    .setProject('YOUR_PROJECT_ID'); // Replace with your project ID

const account = new Account(client);

// DOM elements
const authForms = document.getElementById('auth-forms');
const userProfile = document.getElementById('user-profile');
const registerForm = document.getElementById('register-form');
const loginForm = document.getElementById('login-form');
const logoutBtn = document.getElementById('logout-btn');

// Registration
registerForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const name = document.getElementById('register-name').value;
    const email = document.getElementById('register-email').value;
    const password = document.getElementById('register-password').value;
    
    try {
        const user = await account.create('unique()', email, password, name);
        alert('Registration successful! You can now login.');
        registerForm.reset();
    } catch (error) {
        alert('Registration failed: ' + error.message);
    }
});

// Login
loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const email = document.getElementById('login-email').value;
    const password = document.getElementById('login-password').value;
    
    try {
        await account.createEmailPasswordSession(email, password);
        await showUserProfile();
    } catch (error) {
        alert('Login failed: ' + error.message);
    }
});

// Show user profile
async function showUserProfile() {
    try {
        const user = await account.get();
        document.getElementById('user-name').textContent = user.name;
        document.getElementById('user-email').textContent = user.email;
        authForms.classList.add('hidden');
        userProfile.classList.remove('hidden');
    } catch (error) {
        console.error('Failed to get user info:', error);
    }
}

// Logout
logoutBtn.addEventListener('click', async () => {
    try {
        await account.deleteSession('current');
        authForms.classList.remove('hidden');
        userProfile.classList.add('hidden');
        registerForm.reset();
        loginForm.reset();
    } catch (error) {
        console.error('Logout failed:', error);
    }
});

// Check for existing session on page load
async function checkCurrentUser() {
    try {
        const user = await account.get();
        await showUserProfile();
    } catch (error) {
        console.log('No active session');
    }
}

// Initialize
checkCurrentUser();
```

## What's Next?

Now you have a working authentication system! You can extend this by adding:
- Password reset functionality
- Email verification
- Social media login (OAuth)
- User preferences storage
- Protected routes

## Common Issues & Solutions

**"Project not found"**: Make sure your project ID is correct in the client configuration.

**CORS errors**: Add your domain to the allowed origins in your Appwrite project settings.

**"Invalid credentials"**: Check that email and password are correct, and the user is registered.

This tutorial shows the basics of Appwrite authentication. For production apps, consider adding proper error handling, loading states, and form validation.

## Related Resources

- [Appwrite Account API Reference](https://appwrite.io/docs/references/cloud/client-web/account)
- [Authentication Security Best Practices](https://appwrite.io/docs/products/auth/security)
- [Managing User Sessions](https://appwrite.io/docs/products/auth/sessions)