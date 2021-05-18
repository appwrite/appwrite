let sdk = new Appwrite();

sdk
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setJWT('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...') // Your secret JSON Web Token
;

let promise = sdk.teams.updateMembershipStatus('[TEAM_ID]', '[MEMBERSHIP_ID]', '[USER_ID]', '[SECRET]');

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});