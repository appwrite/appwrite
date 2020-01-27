# Account Examples

## CreateAccountSessionOAuth

```go
    package appwrite-createaccountsessionoauth

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

        // Call CreateAccountSessionOAuth method and handle results
        var res, err := srv.CreateAccountSessionOAuth("bitbucket", "https://example.com", "https://example.com")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```