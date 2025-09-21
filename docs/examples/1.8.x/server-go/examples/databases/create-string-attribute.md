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

response, error := service.CreateStringAttribute(
    "<DATABASE_ID>",
    "<COLLECTION_ID>",
    "",
    1,
    false,
    databases.WithCreateStringAttributeDefault("<DEFAULT>"),
    databases.WithCreateStringAttributeArray(false),
    databases.WithCreateStringAttributeEncrypt(false),
)
