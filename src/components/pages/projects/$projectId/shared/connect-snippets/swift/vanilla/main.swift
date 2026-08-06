import Appwrite

let client = Client()
  .setEndpoint(ProcessInfo.processInfo.environment["APPWRITE_ENDPOINT"]!)
  .setProject(ProcessInfo.processInfo.environment["APPWRITE_PROJECT_ID"]!)
  .setKey(ProcessInfo.processInfo.environment["APPWRITE_API_KEY"]!)

let project = Project(client)

let policy = try await project.updatePasswordStrengthPolicy(
  min: 8,
  uppercase: true,
  number: true,
  symbols: true
)

print(policy)

let policies = try await project.listPolicies()

print(policies)
