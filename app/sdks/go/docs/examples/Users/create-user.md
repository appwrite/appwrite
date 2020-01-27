# Users Examples

## CreateUser

```go
    package appwrite-createuser

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

        // Call CreateUser method and handle results
        var res, err := srv.CreateUser("email@example.com", "password")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```