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

response, error := service.CreateScryptModifiedUser(
    "<USER_ID>",
    "email@example.com",
    "password",
    "<PASSWORD_SALT>",
    "<PASSWORD_SALT_SEPARATOR>",
    "<PASSWORD_SIGNER_KEY>",
    users.WithCreateScryptModifiedUserName("<NAME>"),
)
