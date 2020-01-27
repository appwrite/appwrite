# Account Examples

## CreateAccountSession

```go
    package appwrite-createaccountsession

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

        // Call CreateAccountSession method and handle results
        var res, err := srv.CreateAccountSession("email@example.com", "password")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```