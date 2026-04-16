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

response, error := service.CreateRelationshipAttribute(
    "<DATABASE_ID>",
    "<COLLECTION_ID>",
    "<RELATED_COLLECTION_ID>",
    "oneToOne",
    databases.WithCreateRelationshipAttributeTwoWay(false),
    databases.WithCreateRelationshipAttributeKey(""),
    databases.WithCreateRelationshipAttributeTwoWayKey(""),
    databases.WithCreateRelationshipAttributeOnDelete("cascade"),
)
