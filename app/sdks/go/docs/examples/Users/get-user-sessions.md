# Users Examples

## GetUserSessions

```go
    package appwrite-getusersessions

    import (
        "fmt"
        "os"
        "github.com/appwrite/sdk-for-go"
    )

    func main() {
        // Create a Client
        var clt := appwrite.Client{}

        // Set Client required headers
        clt.SetProject("")

        // Create a new Users service passing Client
        var srv := appwrite.Users{
            client: &clt
        }

        // Call GetUserSessions method and handle results
        var res, err := srv.GetUserSessions("[USER_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```