# Auth Examples

## Logout

```go
    package appwrite-logout

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

        // Create a new Auth service passing Client
        var srv := appwrite.Auth{
            client: &clt
        }

        // Call Logout method and handle results
        var res, err := srv.Logout()
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```