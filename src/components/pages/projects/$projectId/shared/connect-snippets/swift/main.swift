import Appwrite

let client = Client()
  .setEndpoint(ProcessInfo.processInfo.environment["APPWRITE_ENDPOINT"]!)
  .setProject(ProcessInfo.processInfo.environment["APPWRITE_PROJECT_ID"]!)
  .setKey(ProcessInfo.processInfo.environment["APPWRITE_API_KEY"]!)

let account = Account(client)
let user = try await account.get()
print("Hello, \(user.name)")
