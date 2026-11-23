# Every HTTP connect sample exposes the same two endpoints (no params, JSON
# responses). Assertions stick to fields all frameworks serialize the same;
# `base_url` is provided by the workflow (--variable base_url=...).

# Applies the password strength policy hard-coded in the sample and echoes
# the updated policy back.
PATCH {{base_url}}/v1/policies
HTTP 200
[Asserts]
header "Content-Type" contains "json"
jsonpath "$.min" == 8
jsonpath "$.uppercase" == true
jsonpath "$.number" == true
jsonpath "$.symbols" == true

# Lists the project's policies.
GET {{base_url}}/v1/policies
HTTP 200
[Asserts]
header "Content-Type" contains "json"
jsonpath "$.policies" exists
jsonpath "$.policies" isCollection
