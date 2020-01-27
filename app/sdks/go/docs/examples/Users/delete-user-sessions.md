# Users Examples

## DeleteUserSessions

```go
    package appwrite-deleteusersessions

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

        // Call DeleteUserSessions method and handle results
        var res, err := srv.DeleteUserSessions("[USER_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```