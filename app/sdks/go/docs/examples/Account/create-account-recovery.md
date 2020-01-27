# Account Examples

## CreateAccountRecovery

```go
    package appwrite-createaccountrecovery

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

        // Call CreateAccountRecovery method and handle results
        var res, err := srv.CreateAccountRecovery("email@example.com", "https://example.com")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```