let sdk = new Appwrite();

sdk
    setProject('')
;

let promise = sdk.users.getUserLogs('[USER_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});