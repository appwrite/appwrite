Invite a new member to join your team. Provide an ID for existing users, or invite unregistered users using an email or phone number. If initiated from a Client SDK, an email or sms with a link to join the team will be sent to the invited user and an account will be created for them if one doesn't exist. If initiated from a Server SDKs, the new member will be added automatically to the team.

You only need to provide one of User ID, email, or phone number. When multiple are provided, the priority will be user ID > email > phone number, lower priority parameters are ignored.

Use the 'url' parameter to redirect the user from the invitation email back to your app. When the user is redirected, use the [Update Team Membership Status](/docs/client/teams#teamsUpdateMembershipStatus) endpoint to allow the user to accept the invitation to the team. 

Please note that to avoid a [Redirect Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only redirect URLs under the domains you have added as a platform on the Appwrite Console will be accepted.
