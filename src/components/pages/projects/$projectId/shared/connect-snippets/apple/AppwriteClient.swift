import Appwrite
import Foundation

// ProcessInfo.environment only carries the scheme's variables when Xcode
// launches the app, so a released build reads nothing. Ship the values in
// Info.plist instead, fed from Appwrite.xcconfig:
//   APPWRITE_ENDPOINT   -> $(APPWRITE_ENDPOINT)
//   APPWRITE_PROJECT_ID -> $(APPWRITE_PROJECT_ID)
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
