package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/users"
)

client := client.New(
    client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    client.WithProject("<YOUR_PROJECT_ID>")
    client.WithKey("<YOUR_API_KEY>")
)

service := users.New(client)

response, error := service.Create(
    "<USER_ID>",
    users.WithCreateEmail("email@example.com"),
    users.WithCreatePhone("+12065550100"),
    users.WithCreatePassword(""),
    users.WithCreateName("<NAME>"),
)
