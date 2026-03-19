# Create User

Add a new user to your project. This endpoint creates a new user account with the provided details.

### API Information
- **Endpoint**: `/v1/users`
- **Method**: `POST`
- **Permissions**: `users.write`

### Parameters
- **userId**: (Required) A unique ID for the user. Use `ID.unique()` to generate a random ID.
- **email**: User email address. Must be unique across the project.
- **phone**: Phone number in E.164 format (e.g., +12065550100).
- **password**: User password. Must be at least 8 characters long.
- **name**: User name (max 128 chars).

### Example
```json
{
  "name": "Create User",
  "endpoint": "/v1/users",
  "method": "POST"
}
```