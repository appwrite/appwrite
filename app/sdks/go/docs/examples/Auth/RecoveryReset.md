# Auth Examples

## RecoveryReset

```go
    package appwrite-recoveryreset

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

        // Call RecoveryReset method and handle results
        var res, err := srv.RecoveryReset("[USER_ID]", "[TOKEN]", "password", "password")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```