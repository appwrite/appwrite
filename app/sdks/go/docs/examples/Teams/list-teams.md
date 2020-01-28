package main

import (
    "fmt"
    "os"
    "github.com/appwrite/sdk-for-go"
)

func main() {
    // Create a Client
    var client := appwrite.Client{}

    // Set Client required headers
    client.SetProject("")
    client.SetKey("")

    // Create a new Teams service passing Client
    var srv := appwrite.Teams{
        client: &client
    }

    // Call ListTeams method and handle results
    var res, err := srv.ListTeams()
    if err != nil {
        panic(err)
    }

    fmt.Println(res)
}