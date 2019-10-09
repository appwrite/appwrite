# Teams Service

## List Teams

```http request
GET https://appwrite.io/v1/teams
```

** /docs/references/teams/list-teams.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| search | string | Search term to filter your list results. |  |
| limit | integer | Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request. | 25 |
| offset | integer | Results offset. The default value is 0. Use this param to manage pagination. | 0 |
| orderType | string | Order result by ASC or DESC order. | ASC |

## Create Team

```http request
POST https://appwrite.io/v1/teams
```

** /docs/references/teams/create-team.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| name | string | Team name. |  |
| roles | array | User roles array. Use this param to set the roles in the team for the user who created the team. The default role is **owner**, a role can be any string. | [&quot;owner&quot;] |

## Get Team

```http request
GET https://appwrite.io/v1/teams/{teamId}
```

** /docs/references/teams/get-team.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| teamId | string | **Required** Team unique ID. |  |

## Update Team

```http request
PUT https://appwrite.io/v1/teams/{teamId}
```

** /docs/references/teams/update-team.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| teamId | string | **Required** Team unique ID. |  |
| name | string | Team name. |  |

## Delete Team

```http request
DELETE https://appwrite.io/v1/teams/{teamId}
```

** /docs/references/teams/delete-team.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| teamId | string | **Required** Team unique ID. |  |

## Get Team Members

```http request
GET https://appwrite.io/v1/teams/{teamId}/members
```

** /docs/references/teams/get-team-members.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| teamId | string | **Required** Team unique ID. |  |

## Create Team Membership

```http request
POST https://appwrite.io/v1/teams/{teamId}/memberships
```

** /docs/references/teams/create-team-membership.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| teamId | string | **Required** Team unique ID. |  |
| email | string | New team member email address. |  |
| name | string | New team member name. |  |
| roles | array | Invite roles array. Learn more about [roles and permissions](/docs/permissions). |  |
| redirect | string | Reset page to redirect user back to your app from the invitation email. |  |

## Delete Team Membership

```http request
DELETE https://appwrite.io/v1/teams/{teamId}/memberships/{inviteId}
```

** /docs/references/teams/delete-team-membership.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| teamId | string | **Required** Team unique ID. |  |
| inviteId | string | **Required** Invite unique ID |  |

## Create Team Membership (Resend)

```http request
POST https://appwrite.io/v1/teams/{teamId}/memberships/{inviteId}/resend
```

** /docs/references/teams/create-team-membership-resend.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| teamId | string | **Required** Team unique ID. |  |
| inviteId | string | **Required** Invite unique ID. |  |
| redirect | string | Reset page to redirect user back to your app from the invitation email. |  |

## Update Team Membership Status

```http request
PATCH https://appwrite.io/v1/teams/{teamId}/memberships/{inviteId}/status
```

** /docs/references/teams/update-team-membership-status.md **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| teamId | string | **Required** Team unique ID. |  |
| inviteId | string | **Required** Invite unique ID |  |
| userId | string | User unique ID |  |
| secret | string | Secret Key |  |
| success | string | Redirect when registration succeed |  |
| failure | string | Redirect when registration failed |  |

