import Appwrite
import Foundation

let env = ProcessInfo.processInfo.environment

let project = Project(
  Client()
    .setEndpoint(env["APPWRITE_ENDPOINT"]!)
    .setProject(env["APPWRITE_PROJECT_ID"]!)
    .setKey(env["APPWRITE_API_KEY"]!)
)
