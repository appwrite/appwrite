# Users Examples

## UpdateUserStatus

```go
    package appwrite-updateuserstatus

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

        // Call UpdateUserStatus method and handle results
        var res, err := srv.UpdateUserStatus("[USER_ID]", "1")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```