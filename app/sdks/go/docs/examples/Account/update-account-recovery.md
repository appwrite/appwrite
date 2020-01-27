# Account Examples

## UpdateAccountRecovery

```go
    package appwrite-updateaccountrecovery

    import (
        "fmt"
        "os"
        "github.com/appwrite/sdk-for-go"
    )

    func main() {
        // Create a Client
        var clt := appwrite.Client{}

        // Set Client required headers

        // Create a new Account service passing Client
        var srv := appwrite.Account{
            client: &clt
        }

        // Call UpdateAccountRecovery method and handle results
        var res, err := srv.UpdateAccountRecovery("[USER_ID]", "[SECRET]", "password", "password")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```