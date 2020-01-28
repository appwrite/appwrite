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

    // Create a new Users service passing Client
    var srv := appwrite.Users{
        client: &client
    }

    // Call DeleteUserSessions method and handle results
    var res, err := srv.DeleteUserSessions("[USER_ID]")
    if err != nil {
        panic(err)
    }

    fmt.Println(res)
}