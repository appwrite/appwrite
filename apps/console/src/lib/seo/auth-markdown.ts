import { MCP_SERVER_URL } from '@/lib/config/mcp'
import { DEFAULT_LLMS_ORIGIN } from '@/lib/seo/llms'

/**
 * `/auth.md`: how an agent gets credentials for Appwrite, in the section
 * order of the auth.md convention (https://github.com/workos/auth.md). Every
 * endpoint and capability here comes from Appwrite Cloud's published OAuth
 * metadata; the agentic-registration parts Appwrite does not implement are
 * stated as such instead of advertised.
 */
export function buildAuthMarkdown(
  origin: string = DEFAULT_LLMS_ORIGIN,
): string {
  const resourceMetadata = `${MCP_SERVER_URL}/.well-known/oauth-protected-resource`
  return `# Appwrite auth.md

How an AI agent gets credentials to work with Appwrite. There are two cases:

- **Acting for a person** (for example through the Appwrite MCP server at ${MCP_SERVER_URL}): use OAuth 2.1. The person signs in to Appwrite Cloud and approves the scopes; the agent never sees their password.
- **Automation without a person present** (a backend, CI, a scheduled job): use a project API key that a project member creates in the Appwrite Console and gives to the agent.

Appwrite does not offer agentic self-registration: there is no \`agent_auth\` block, \`identity_endpoint\`, \`identity_assertion\` (ID-JAG), \`service_auth\` or anonymous claim flow. Every credential traces back to a person's consent or to a key a person created.

## Step 1 — Discover

Start from the resource you want to call. An unauthenticated request to the MCP server answers \`401\` with a challenge that names its metadata:

\`\`\`
WWW-Authenticate: Bearer error="invalid_token", resource_metadata="${resourceMetadata}", scope="..."
\`\`\`

1. Fetch the protected resource metadata (RFC 9728) at ${resourceMetadata}. It lists \`resource\`, \`authorization_servers\`, \`scopes_supported\` and \`bearer_methods_supported\` (\`header\`).
2. Fetch the authorization server metadata (RFC 8414) for the issuer in \`authorization_servers\`. For the issuer \`https://fra.cloud.appwrite.io/v1/oauth2/console\` it is at \`https://fra.cloud.appwrite.io/.well-known/oauth-authorization-server/v1/oauth2/console\`. Read the endpoints from that document rather than hard-coding them.

## Step 2 — Pick a method

| Situation | Method |
| --- | --- |
| A person is present and the agent can open a browser | Authorization code with PKCE (\`code_challenge_method=S256\`) |
| A person is present but the agent has no browser (CLI, remote shell) | Device authorization grant (RFC 8628, \`urn:ietf:params:oauth:grant-type:device_code\`) |
| No person is present | Project API key, created in the Console |

## Step 3 — Register

OAuth clients need a \`client_id\`. Either:

- **Client ID Metadata Document** (preferred, \`client_id_metadata_document_supported: true\`): use the HTTPS URL of a JSON document describing your client as the \`client_id\`. No registration call is needed.
- **Dynamic Client Registration** (RFC 7591): \`POST\` your client metadata to the \`registration_endpoint\`.

Public clients use \`token_endpoint_auth_method: "none"\` with PKCE.

## Step 4 — Get the person's approval

- **Authorization code**: send the person to the \`authorization_endpoint\` with \`response_type=code\`, \`code_challenge\` (S256), the \`scope\` you need and \`resource=${MCP_SERVER_URL}/\`. They sign in to Appwrite Cloud and approve. Pushed authorization requests (\`pushed_authorization_request_endpoint\`) are supported for long requests.
- **Device grant**: \`POST\` to the \`device_authorization_endpoint\`, show the person the \`user_code\` and \`verification_uri\`, and poll the token endpoint at the returned \`interval\` until they approve.

Ask only for the scopes the task needs. Project scopes look like \`project:databases.read\`, \`project:rows.write\` or \`project:functions.write\`; the full list is \`scopes_supported\` in the metadata.

## Step 5 — Exchange

\`POST\` to the \`token_endpoint\`:

- After authorization code: \`grant_type=authorization_code\` with the \`code\` and your \`code_verifier\`.
- During the device grant: \`grant_type=urn:ietf:params:oauth:grant-type:device_code\` with the \`device_code\`.
- To renew: \`grant_type=refresh_token\`.

The response contains an \`access_token\` and, when granted, a \`refresh_token\`.

## Step 6 — Use the access_token

Send it as a bearer token: \`Authorization: Bearer <access_token>\` to ${MCP_SERVER_URL}. Setup guides for MCP clients: ${origin}/docs/tooling/ai/mcp-servers.md.

With a project API key, call the REST API at your project's endpoint (for example \`https://fra.cloud.appwrite.io/v1\`) with the headers \`X-Appwrite-Project: <project id>\` and \`X-Appwrite-Key: <api key>\`. See ${origin}/docs/apis/rest.md.

## Errors

- \`401\` with \`WWW-Authenticate: Bearer error="invalid_token"\`: the token is missing, expired or revoked. Refresh it, or start again from Step 1.
- \`403\`: the token or key lacks a scope the call needs. Request the scope from the person, or ask them for a key with it.
- OAuth endpoint errors use the standard \`error\` and \`error_description\` fields (RFC 6749). Appwrite REST errors return JSON with \`message\`, \`code\` and \`type\`.

## Revocation

- Revoke OAuth tokens at the \`revocation_endpoint\` (RFC 7009). People can also remove an agent's access in the Console under Account → Applications.
- API keys are revoked by deleting them in the Console (Project → API keys) or through the Projects API.
`
}
