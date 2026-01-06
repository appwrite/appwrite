package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/account"
)

client := client.New(
    client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    client.WithProject("<YOUR_PROJECT_ID>")
    client.WithSession("")
)

service := account.New(client)

response, error := service.CreateOAuth2Token(
    "amazon",
    account.WithCreateOAuth2TokenSuccess("https://example.com"),
    account.WithCreateOAuth2TokenFailure("https://example.com"),
    account.WithCreateOAuth2TokenScopes([]interface{}{}),
)
