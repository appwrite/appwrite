let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.users.deleteSession('[USER_ID]', '[SESSION_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});