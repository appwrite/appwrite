# Update User Status

Update the status of a user in your project. This allows you to enable (`true`) or block (`false`) a user without deleting their account.

### API Information
- **Endpoint**: `/v1/users/{userId}/status`
- **Method**: `PATCH`
- **Permissions**: `users.write`

### Parameters
- **userId**: (Required) The ID of the user whose status you wish to update.
- **status**: (Required) Boolean value representing the status. `true` for active, `false` for blocked.

### Usage Example
```json
{
  "name": "Update User Status",
  "endpoint": "/v1/users/{userId}/status",
  "method": "PATCH"
}
```