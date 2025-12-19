package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/databases"
)

client := client.New(
    client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    client.WithProject("<YOUR_PROJECT_ID>")
    client.WithKey("<YOUR_API_KEY>")
)

service := databases.New(client)

response, error := service.CreateCollection(
    "<DATABASE_ID>",
    "<COLLECTION_ID>",
    "<NAME>",
    databases.WithCreateCollectionPermissions(interface{}{"read("any")"}),
    databases.WithCreateCollectionDocumentSecurity(false),
    databases.WithCreateCollectionEnabled(false),
    databases.WithCreateCollectionAttributes([]interface{}{}),
    databases.WithCreateCollectionIndexes([]interface{}{}),
)
