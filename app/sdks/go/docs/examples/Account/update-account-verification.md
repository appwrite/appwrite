# Account Examples

## UpdateAccountVerification

```go
    package appwrite-updateaccountverification

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

        // Call UpdateAccountVerification method and handle results
        var res, err := srv.UpdateAccountVerification("[USER_ID]", "[SECRET]", "password")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```