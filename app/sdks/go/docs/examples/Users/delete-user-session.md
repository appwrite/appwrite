# Users Examples

## DeleteUserSession

```go
    package appwrite-deleteusersession

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
        clt.SetKey("")

        // Create a new Users service passing Client
        var srv := appwrite.Users{
            client: &clt
        }

        // Call DeleteUserSession method and handle results
        var res, err := srv.DeleteUserSession("[USER_ID]", "[SESSION_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```