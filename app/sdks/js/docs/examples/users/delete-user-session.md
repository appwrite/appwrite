let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.users.deleteUserSession('[USER_ID]', '[SESSION_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});