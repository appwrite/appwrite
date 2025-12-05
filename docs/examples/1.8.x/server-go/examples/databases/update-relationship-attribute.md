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

response, error := service.UpdateRelationshipAttribute(
    "<DATABASE_ID>",
    "<COLLECTION_ID>",
    "",
    databases.WithUpdateRelationshipAttributeOnDelete("cascade"),
    databases.WithUpdateRelationshipAttributeNewKey(""),
)
