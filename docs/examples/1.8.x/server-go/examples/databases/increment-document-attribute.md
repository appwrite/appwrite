package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/databases"
)

client := client.New(
    client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    client.WithProject("<YOUR_PROJECT_ID>")
    client.WithSession("")
)

service := databases.New(client)

response, error := service.IncrementDocumentAttribute(
    "<DATABASE_ID>",
    "<COLLECTION_ID>",
    "<DOCUMENT_ID>",
    "",
    databases.WithIncrementDocumentAttributeValue(0),
    databases.WithIncrementDocumentAttributeMax(0),
    databases.WithIncrementDocumentAttributeTransactionId("<TRANSACTION_ID>"),
)
