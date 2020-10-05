let sdk = new Appwrite();

sdk
    .setProject('')
;

/**
 *  सानुकुले पृष्टे पुनःनिर्देषिते
 *  इदं क्रिया तु अनुबन्धस्ये संसक्त अस्ति
 */
sdk.auth.oauth(
    'facebook',
    'http://example.com/success',
    'http://example.com/failure'
);
