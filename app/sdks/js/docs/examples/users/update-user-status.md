let sdk = new Appwrite();

sdk
    setProject('')
    setKey('')
;

let promise = sdk.users.updateUserStatus('[USER_ID]', '1');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});