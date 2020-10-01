# Create Account Session with Google & OAuth!

- Allow the user to login to his account using Google's OAuth 2.0 
- Each OAuth2 provider should be enabled from the Appwrite console first
- Use the success and failure arguments to redirect a URL's back to your app when login is completed

### Google Side of setup required to gain authorization:
#### Create the authorization credentials for your application:
1. Go [here](https://console.developers.google.com/apis/credentials) for creating authorization credentials  
2. Click **Create credentials** > **OAuth client ID**.
3. Select the **Web application** application type.
4. Fill in the form and click **Create**. 
Applications that use languages and frameworks like PHP, Java, Python, Ruby, and .NET must specify authorized **redirect URIs**. The redirect URIs are the endpoints to which the OAuth 2.0 server can send responses.

#### Load the Google Platform Library

Your application can now succesfully use this credential for gaining access to APIs that you want to enable  

###### Next



[Read More](https://developers.google.com/identity/sign-in/web/sign-in)

#### Example Request
```js
let sdk = new Appwrite();

sdk
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your application ID
;

// Go to OAuth provider login page
sdk.account.createOAuth2Session('google');
```

### Note:
#### Rate Limits
This endpoint is limited to 50 requests in every 60 minutes per IP address.   
We use rate limits to avoid service abuse by users and as a security practice.  
[Learn more about rate limiting](https://appwrite.io/docs/rate-limits)
