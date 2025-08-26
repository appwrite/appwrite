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

response, error := service.CreateIntegerColumn(
    "<DATABASE_ID>",
    "<TABLE_ID>",
    "",
    false,
    tablesdb.WithCreateIntegerColumnMin(0),
    tablesdb.WithCreateIntegerColumnMax(0),
    tablesdb.WithCreateIntegerColumnDefault(0),
    tablesdb.WithCreateIntegerColumnArray(false),
)
