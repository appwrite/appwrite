import Appwrite
import Foundation

private func config(_ key: String) -> String {
  guard let value = Bundle.main.object(forInfoDictionaryKey: key) as? String
  else {
    fatalError("Missing \(key) in Info.plist")
  }
  return value
}

let client = Client()
  .setEndpoint(config("APPWRITE_ENDPOINT"))
  .setProject(config("APPWRITE_PROJECT_ID"))
