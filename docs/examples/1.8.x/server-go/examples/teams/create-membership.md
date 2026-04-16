package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/teams"
)

client := client.New(
    client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    client.WithProject("<YOUR_PROJECT_ID>")
    client.WithSession("")
)

service := teams.New(client)

response, error := service.CreateMembership(
    "<TEAM_ID>",
    []interface{}{},
    teams.WithCreateMembershipEmail("email@example.com"),
    teams.WithCreateMembershipUserId("<USER_ID>"),
    teams.WithCreateMembershipPhone("+12065550100"),
    teams.WithCreateMembershipUrl("https://example.com"),
    teams.WithCreateMembershipName("<NAME>"),
)
