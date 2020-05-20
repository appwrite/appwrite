let sdk = new Appwrite();

sdk
    .setProject('5df5acd0d48c2') // Your project ID
;

let result = sdk.avatars.getFavicon('https://example.com');

console.log(result); // Resource URL
