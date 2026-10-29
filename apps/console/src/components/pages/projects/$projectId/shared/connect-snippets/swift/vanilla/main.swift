import Appwrite
import Foundation

let encoder = JSONEncoder()
encoder.outputFormatting = .prettyPrinted

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

print(String(decoding: try encoder.encode(policy), as: UTF8.self))

let policies = try await project.listPolicies()

print(String(decoding: try encoder.encode(policies), as: UTF8.self))
