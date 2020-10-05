let sdk = new Appwrite();

sdk
    .setProject('')
;

/**
 *  सानुकुले पृष्टे पुनःनिर्देषिते
 *  इदं क्रिया तु अनुबन्धस्ये संसक्त अस्ति
 */
sdk.auth.register(
    'email@example.com',
    'password',
    'http://example.com/confirm',
    'http://example.com/success', // JS SDK हि आवश्यक 
    'http://example.com/failure' // JS SDK हि आवश्यक 
);
