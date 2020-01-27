# Account Examples

## CreateAccount

```go
    package appwrite-createaccount

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

        // Call CreateAccount method and handle results
        var res, err := srv.CreateAccount("email@example.com", "password")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```