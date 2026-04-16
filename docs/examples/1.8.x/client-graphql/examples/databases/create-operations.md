mutation {
    databasesCreateOperations(
        transactionId: "<TRANSACTION_ID>",
        operations: [
	    {
	        "action": "create",
	        "databaseId": "<DATABASE_ID>",
	        "collectionId": "<COLLECTION_ID>",
	        "documentId": "<DOCUMENT_ID>",
	        "data": {
	            "name": "Walter O'Brien"
	        }
	    }
	]
    ) {
        _id
        _createdAt
        _updatedAt
        status
        operations
        expiresAt
    }
}
