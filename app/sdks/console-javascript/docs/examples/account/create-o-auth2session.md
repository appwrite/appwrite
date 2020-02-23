let sdk = new Appwrite();

sdk
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

let result = sdk.account.createOAuth2Session('bitbucket', 'https://example.com', 'https://example.com');

console.log(result); // Resource URL
