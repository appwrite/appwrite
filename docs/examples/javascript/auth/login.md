let sdk = new Appwrite();

sdk
    .setProject('')
;

sdk.auth.login(
    'email@example.com',
    'password',
    'http://example.com/success', // required for JS SDK
    'http://example.com/failure' // required for JS SDK
); // Will redirect to relevant page depends on the operation result