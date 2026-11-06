import Foundation
import Vapor

let app = try await Application.make(.detect())
app.http.server.configuration.port = 8080

func json(_ value: some Encodable) throws -> Response {
  var headers = HTTPHeaders()
  headers.contentType = .json
  return Response(
    status: .ok,
    headers: headers,
    body: .init(data: try JSONEncoder().encode(value))
  )
}

app.patch("v1", "policies") { _ async throws -> Response in
  try json(
    try await project.updatePasswordStrengthPolicy(
      min: 8,
      uppercase: true,
      number: true,
      symbols: true
    )
  )
}

app.get("v1", "policies") { _ async throws -> Response in
  try json(try await project.listPolicies())
}

try await app.execute()
try await app.asyncShutdown()
