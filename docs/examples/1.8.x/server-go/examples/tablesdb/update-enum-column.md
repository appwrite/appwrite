package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/tablesdb"
)

client := client.New(
    client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    client.WithProject("<YOUR_PROJECT_ID>")
    client.WithKey("<YOUR_API_KEY>")
)

service := tablesdb.New(client)

response, error := service.UpdateEnumColumn(
    "<DATABASE_ID>",
    "<TABLE_ID>",
    "",
    []interface{}{},
    false,
    "<DEFAULT>",
    tablesdb.WithUpdateEnumColumnNewKey(""),
)
