let sdk = new Appwrite();

sdk
    .setProject('')
;

sdk.auth.oauth(
    'facebook',
    'http://example.com/success',
    'http://example.com/failure'
); // Will redirect to relevant page depends on the operation result