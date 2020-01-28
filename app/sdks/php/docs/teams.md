# Teams Service

## List Teams

```http request
GET https://appwrite.io/v1/teams
```

** Get a list of all the current user teams. You can use the query params to filter your results. On admin mode, this endpoint will return a list of all of the project teams. [Learn more about different API modes](/docs/admin). **

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

** Create a new team. The user who creates the team will automatically be assigned as the owner of the team. The team owner can invite new members, who will be able add new owners and update or delete the team from your project. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| name | string | Team name. |  |
| roles | array | User roles array. Use this param to set the roles in the team for the user who created the team. The default role is **owner**, a role can be any string. | [&quot;owner&quot;] |

## Get Team

```http request
GET https://appwrite.io/v1/teams/{teamId}
```

** Get team by its unique ID. All team members have read access for this resource. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| teamId | string | **Required** Team unique ID. |  |

## Update Team

```http request
PUT https://appwrite.io/v1/teams/{teamId}
```

** Update team by its unique ID. Only team owners have write access for this resource. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| teamId | string | **Required** Team unique ID. |  |
| name | string | Team name. |  |

## Delete Team

```http request
DELETE https://appwrite.io/v1/teams/{teamId}
```

** Delete team by its unique ID. Only team owners have write access for this resource. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| teamId | string | **Required** Team unique ID. |  |

## Get Team Memberships

```http request
GET https://appwrite.io/v1/teams/{teamId}/memberships
```

** Get team members by the team unique ID. All team members have read access for this list of resources. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| teamId | string | **Required** Team unique ID. |  |

## Create Team Membership

```http request
POST https://appwrite.io/v1/teams/{teamId}/memberships
```

** Use this endpoint to invite a new member to your team. An email with a link to join the team will be sent to the new member email address. If member doesn&#039;t exists in the project it will be automatically created.

Use the &#039;url&#039; parameter to redirect the user from the invitation email back to your app. When the user is redirected, use the [Update Team Membership Status](/docs/teams#updateTeamMembershipStatus) endpoint to finally join the user to the team.

Please note that in order to avoid a [Redirect Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URL&#039;s are the once from domains you have set when added your platforms in the console interface. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| teamId | string | **Required** Team unique ID. |  |
| email | string | New team member email address. |  |
| name | string | New team member name. |  |
| roles | array | Invite roles array. Learn more about [roles and permissions](/docs/permissions). |  |
| url | string | URL to redirect the user back to your app from the invitation email. |  |

## Delete Team Membership

```http request
DELETE https://appwrite.io/v1/teams/{teamId}/memberships/{inviteId}
```

** This endpoint allows a user to leave a team or for a team owner to delete the membership of any other team member. You can also use this endpoint to delete a user membership even if he didn&#039;t accept it. **

### Parameters

| Field Name | Type | Description | Default |
| --- | --- | --- | --- |
| teamId | string | **Required** Team unique ID. |  |
| inviteId | string | **Required** Invite unique ID |  |

