/**
 * Client-side OAuth (PKCE) for assistant MCP connections. Tokens are stored via
 * the Console assistant MCP SDK — never keep access tokens in React state beyond
 * the handoff.
 *
 * The hosted Appwrite MCP path prefers the pre-registered public client
 * (`appwrite-agent`). When that client is missing (fresh local/dev instances),
 * silent connect falls back to Dynamic Client Registration. Silent connect runs
 * authorize + approve via the Console SDK without a popup or consent redirect.
 */

import { AppwriteException } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

export const ASSISTANT_MCP_OAUTH_CALLBACK_PATH = '/agent/mcp/callback'
export const ASSISTANT_MCP_OAUTH_MESSAGE_TYPE = 'assistant-mcp-oauth' as const
export const ASSISTANT_MCP_OAUTH_STORAGE_KEY = 'assistant.mcp.oauth.pending'

export type McpOAuthTokenSet = {
  access_token: string
  token_type?: string
  expires_in?: number
  refresh_token?: string
  scope?: string
  id_token?: string
  authorization_details?: unknown
  [key: string]: unknown
}

export type McpOAuthClientInfo = {
  client_id: string
  client_secret?: string
  client_id_issued_at?: number
  client_secret_expires_at?: number
  registration_access_token?: string
  registration_client_uri?: string
  token_endpoint_auth_method?: string
  redirect_uris?: string[]
  [key: string]: unknown
}

export type McpOAuthPendingSession = {
  mcpId: string
  name: string
  url: string
  description?: string
  resource: string
  state: string
  codeVerifier: string
  redirectUri: string
  authorizationServer: string
  authorizationEndpoint: string
  tokenEndpoint: string
  client: McpOAuthClientInfo
  createdAt: number
}

export type McpOAuthCallbackMessage =
  | {
      type: typeof ASSISTANT_MCP_OAUTH_MESSAGE_TYPE
      status: 'success'
      code: string
      state: string
    }
  | {
      type: typeof ASSISTANT_MCP_OAUTH_MESSAGE_TYPE
      status: 'error'
      error: string
      errorDescription?: string
      state?: string
    }

type ProtectedResourceMetadata = {
  resource?: string
  authorization_servers?: string[]
  scopes_supported?: string[]
}

type AuthorizationServerMetadata = {
  issuer?: string
  authorization_endpoint?: string
  token_endpoint?: string
  registration_endpoint?: string
  code_challenge_methods_supported?: string[]
  scopes_supported?: string[]
}

function base64UrlEncode(bytes: ArrayBuffer | Uint8Array): string {
  const view = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes)
  let binary = ''
  for (const byte of view) {
    binary += String.fromCharCode(byte)
  }
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '')
}

function randomUrlSafeString(byteLength: number): string {
  const bytes = new Uint8Array(byteLength)
  crypto.getRandomValues(bytes)
  return base64UrlEncode(bytes)
}

async function sha256Base64Url(input: string): Promise<string> {
  const data = new TextEncoder().encode(input)
  const digest = await crypto.subtle.digest('SHA-256', data)
  return base64UrlEncode(digest)
}

export function getAssistantMcpOAuthRedirectUri(
  origin: string = typeof window !== 'undefined' ? window.location.origin : '',
): string {
  return `${origin.replace(/\/+$/, '')}${ASSISTANT_MCP_OAUTH_CALLBACK_PATH}`
}

function savePendingSession(session: McpOAuthPendingSession): void {
  sessionStorage.setItem(ASSISTANT_MCP_OAUTH_STORAGE_KEY, JSON.stringify(session))
}

export function readPendingMcpOAuthSession(): McpOAuthPendingSession | null {
  const raw = sessionStorage.getItem(ASSISTANT_MCP_OAUTH_STORAGE_KEY)
  if (!raw) return null
  try {
    return JSON.parse(raw) as McpOAuthPendingSession
  } catch {
    return null
  }
}

export function clearPendingMcpOAuthSession(): void {
  sessionStorage.removeItem(ASSISTANT_MCP_OAUTH_STORAGE_KEY)
}

async function fetchJson<T>(url: string, init?: RequestInit): Promise<T> {
  const response = await fetch(url, init)
  const text = await response.text()
  let body: unknown = null
  if (text) {
    try {
      body = JSON.parse(text)
    } catch {
      body = text
    }
  }
  if (!response.ok) {
    const detail =
      body &&
      typeof body === 'object' &&
      body !== null &&
      ('error_description' in body || 'message' in body || 'error' in body)
        ? String(
            (body as { error_description?: string; message?: string; error?: string })
              .error_description ||
              (body as { message?: string }).message ||
              (body as { error?: string }).error,
          )
        : `${response.status} ${response.statusText}`
    throw new Error(detail || `Request failed: ${url}`)
  }
  return body as T
}

function joinUrl(base: string, path: string): string {
  return `${base.replace(/\/+$/, '')}/${path.replace(/^\/+/, '')}`
}

async function discoverProtectedResource(
  mcpUrl: string,
): Promise<ProtectedResourceMetadata> {
  const url = new URL(mcpUrl)
  const candidates = [
    joinUrl(url.origin, '/.well-known/oauth-protected-resource'),
    joinUrl(
      url.origin,
      `/.well-known/oauth-protected-resource${url.pathname === '/' ? '' : url.pathname}`,
    ),
  ]

  let lastError: unknown
  for (const candidate of candidates) {
    try {
      return await fetchJson<ProtectedResourceMetadata>(candidate)
    } catch (error) {
      lastError = error
    }
  }
  throw lastError instanceof Error
    ? lastError
    : new Error('Failed to discover MCP protected resource metadata')
}

async function discoverAuthorizationServer(
  authorizationServer: string,
): Promise<AuthorizationServerMetadata> {
  const base = authorizationServer.replace(/\/+$/, '')
  const candidates = [
    `${base}/.well-known/openid-configuration`,
    `${base}/.well-known/oauth-authorization-server`,
  ]
  let lastError: unknown
  for (const candidate of candidates) {
    try {
      return await fetchJson<AuthorizationServerMetadata>(candidate)
    } catch (error) {
      lastError = error
    }
  }
  throw lastError instanceof Error
    ? lastError
    : new Error('Failed to discover authorization server metadata')
}

async function registerPublicClient(params: {
  registrationEndpoint: string
  redirectUri: string
  clientName: string
}): Promise<McpOAuthClientInfo> {
  const body = {
    client_name: params.clientName,
    redirect_uris: [params.redirectUri],
    token_endpoint_auth_method: 'none',
    grant_types: ['authorization_code', 'refresh_token'],
    response_types: ['code'],
    application_type: 'web',
    client_uri: typeof window !== 'undefined' ? window.location.origin : undefined,
  }

  const client = await fetchJson<McpOAuthClientInfo>(params.registrationEndpoint, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(body),
  })

  if (!client.client_id) {
    throw new Error('Dynamic client registration did not return a client_id')
  }
  return client
}

/**
 * Console OAuth2 only materializes `project:*` / `organization:*` API scopes
 * when the token carries RFC 9396 authorization_details that bind those
 * privileges to concrete resources (or `*`). Without this, consent can show
 * "all permissions" while the issued token still cannot list projects.
 */
export const MCP_DEFAULT_AUTHORIZATION_DETAILS = [
  { type: 'project', identifiers: ['*'] },
  { type: 'organization', identifiers: ['*'] },
] as const

function buildAuthorizeUrl(params: {
  authorizationEndpoint: string
  clientId: string
  redirectUri: string
  scope: string
  state: string
  codeChallenge: string
  resource: string
  authorizationDetails?: string
}): string {
  const url = new URL(params.authorizationEndpoint)
  url.searchParams.set('response_type', 'code')
  url.searchParams.set('client_id', params.clientId)
  url.searchParams.set('redirect_uri', params.redirectUri)
  url.searchParams.set('scope', params.scope)
  url.searchParams.set('state', params.state)
  url.searchParams.set('code_challenge', params.codeChallenge)
  url.searchParams.set('code_challenge_method', 'S256')
  url.searchParams.set('resource', params.resource)
  if (params.authorizationDetails) {
    url.searchParams.set('authorization_details', params.authorizationDetails)
  }
  return url.toString()
}

export async function exchangeMcpOAuthCode(params: {
  tokenEndpoint: string
  code: string
  redirectUri: string
  clientId: string
  codeVerifier: string
  resource: string
}): Promise<McpOAuthTokenSet> {
  // Appwrite console AS accepts JSON token requests (partners docs); include PKCE.
  const tokens = await fetchJson<McpOAuthTokenSet>(params.tokenEndpoint, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      grant_type: 'authorization_code',
      code: params.code,
      redirect_uri: params.redirectUri,
      client_id: params.clientId,
      code_verifier: params.codeVerifier,
      resource: params.resource,
    }),
  })

  if (!tokens.access_token) {
    throw new Error('Token response did not include an access_token')
  }
  return tokens
}

export type StartMcpOAuthConnectInput = {
  mcpId: string
  name: string
  url: string
  description?: string
  /** RFC 8707 resource indicator; defaults to the MCP URL. */
  resource?: string
  scopes?: string[]
  /**
   * Pre-registered OAuth2 client id. When set, skips Dynamic Client
   * Registration and reuses this client for every connect (first-party
   * Appwrite Agent).
   */
  clientId?: string
  clientName?: string
}

const MCP_DEFAULT_SCOPES = [
  'openid',
  'profile',
  'email',
  'project:all',
  'organization:all',
] as const

function buildFixedClientInfo(params: {
  clientId: string
  clientName?: string
  redirectUri: string
}): McpOAuthClientInfo {
  return {
    client_id: params.clientId,
    client_name: params.clientName || 'Appwrite Agent',
    token_endpoint_auth_method: 'none',
    redirect_uris: [params.redirectUri],
  }
}

function scopeFromInput(input: StartMcpOAuthConnectInput): string {
  const scopeList =
    input.scopes && input.scopes.length > 0
      ? input.scopes
      : [...MCP_DEFAULT_SCOPES]
  return scopeList.join(' ')
}

/** Seeded `appwrite-agent` (or override) is absent on this authorization server. */
function isInvalidOAuthClientError(error: unknown): boolean {
  if (error instanceof AppwriteException) {
    return (
      error.type === 'oauth2_invalid_client_id' ||
      (error.code === 400 && /invalid client/i.test(error.message))
    )
  }
  if (!error || typeof error !== 'object') return false
  const typed = error as { type?: unknown; code?: unknown; message?: unknown }
  if (typed.type === 'oauth2_invalid_client_id') return true
  return (
    typed.code === 400 &&
    typeof typed.message === 'string' &&
    /invalid client/i.test(typed.message)
  )
}

export function extractAuthorizationCodeFromRedirectUrl(
  redirectUrl: string,
  expectedState?: string,
): string {
  let url: URL
  try {
    url = new URL(
      redirectUrl,
      typeof window !== 'undefined' ? window.location.origin : undefined,
    )
  } catch {
    throw new Error('Authorization redirect URL was invalid')
  }

  const error = url.searchParams.get('error')
  if (error) {
    throw new Error(
      url.searchParams.get('error_description') ||
        error ||
        'Authorization was denied',
    )
  }

  const code = url.searchParams.get('code')
  if (!code) {
    throw new Error('Authorization redirect did not include a code')
  }

  if (expectedState) {
    const state = url.searchParams.get('state')
    if (state !== expectedState) {
      throw new Error('MCP OAuth state mismatch')
    }
  }

  return code
}

/**
 * Discover AS + build a pending PKCE session. Uses a pre-registered
 * {@link StartMcpOAuthConnectInput.clientId} when provided; otherwise falls
 * back to Dynamic Client Registration.
 */
export async function prepareMcpOAuthSession(
  input: StartMcpOAuthConnectInput,
): Promise<{
  session: McpOAuthPendingSession
  scope: string
  codeChallenge: string
  authorizationDetails: string
}> {
  if (typeof window === 'undefined') {
    throw new Error('MCP OAuth can only run in the browser')
  }

  const redirectUri = getAssistantMcpOAuthRedirectUri()
  const resource = input.resource || input.url
  const prm = await discoverProtectedResource(input.url)
  const authorizationServer = prm.authorization_servers?.[0]
  if (!authorizationServer) {
    throw new Error('MCP server did not advertise an authorization server')
  }

  const asMeta = await discoverAuthorizationServer(authorizationServer)
  if (!asMeta.authorization_endpoint || !asMeta.token_endpoint) {
    throw new Error('Authorization server metadata is incomplete')
  }
  if (
    asMeta.code_challenge_methods_supported &&
    !asMeta.code_challenge_methods_supported.includes('S256')
  ) {
    throw new Error('Authorization server does not support PKCE S256')
  }

  const scope = scopeFromInput(input)
  const fixedClientId = input.clientId?.trim()
  let client: McpOAuthClientInfo

  if (fixedClientId) {
    // First-party Appwrite Agent: reuse the seeded public client.
    client = buildFixedClientInfo({
      clientId: fixedClientId,
      clientName: input.clientName,
      redirectUri,
    })
  } else {
    if (!asMeta.registration_endpoint) {
      throw new Error(
        'Authorization server does not support dynamic client registration',
      )
    }
    client = await registerPublicClient({
      registrationEndpoint: asMeta.registration_endpoint,
      redirectUri,
      clientName: input.clientName || 'Appwrite Agent',
    })
  }

  const state = randomUrlSafeString(24)
  const codeVerifier = randomUrlSafeString(64)
  const codeChallenge = await sha256Base64Url(codeVerifier)
  const authorizationDetails = JSON.stringify(MCP_DEFAULT_AUTHORIZATION_DETAILS)

  const session: McpOAuthPendingSession = {
    mcpId: input.mcpId,
    name: input.name,
    url: input.url,
    description: input.description,
    resource,
    state,
    codeVerifier,
    redirectUri,
    authorizationServer,
    authorizationEndpoint: asMeta.authorization_endpoint,
    tokenEndpoint: asMeta.token_endpoint,
    client,
    createdAt: Date.now(),
  }
  savePendingSession(session)

  return { session, scope, codeChallenge, authorizationDetails }
}

async function resolveAuthorizationCode(params: {
  clientId: string
  redirectUri: string
  scope: string
  state: string
  codeChallenge: string
  resource: string
  authorizationDetails: string
}): Promise<string> {
  const authorizeResult = await sdk.forConsole.oauth2.authorize({
    clientId: params.clientId,
    redirectUri: params.redirectUri,
    responseType: 'code',
    scope: params.scope,
    state: params.state,
    codeChallenge: params.codeChallenge,
    codeChallengeMethod: 'S256',
    resource: params.resource,
    authorizationDetails: params.authorizationDetails,
  })

  let redirectUrl = authorizeResult.redirectUrl?.trim() || ''
  if (!redirectUrl) {
    const grantId = authorizeResult.grantId?.trim()
    if (!grantId) {
      throw new Error('Authorization did not return a grant or redirect')
    }
    // Auto-approve: the agent is a first-party console surface and the user
    // already authenticated into the console with these privileges.
    const approveResult = await sdk.forConsole.oauth2.approve({ grantId })
    redirectUrl = approveResult.redirectUrl?.trim() || ''
  }

  if (!redirectUrl) {
    throw new Error('Authorization approve did not return a redirect URL')
  }

  return extractAuthorizationCodeFromRedirectUrl(redirectUrl, params.state)
}

async function connectMcpOAuthSilentlyWithDcr(
  input: StartMcpOAuthConnectInput,
): Promise<{
  mcpId: string
  name: string
  url: string
  description?: string
  tokens: McpOAuthTokenSet
  clientInfo: McpOAuthClientInfo
}> {
  // Omit fixed client id so prepareMcpOAuthSession runs Dynamic Client Registration.
  const { session, scope, codeChallenge, authorizationDetails } =
    await prepareMcpOAuthSession({ ...input, clientId: undefined })

  try {
    const code = await resolveAuthorizationCode({
      clientId: session.client.client_id,
      redirectUri: session.redirectUri,
      scope,
      state: session.state,
      codeChallenge,
      resource: session.resource,
      authorizationDetails,
    })
    return await completeMcpOAuthConnect({ code, session })
  } catch (error) {
    clearPendingMcpOAuthSession()
    throw error
  }
}

/**
 * Run the full Appwrite MCP OAuth connect without a popup or consent UI.
 * Uses Console `oauth2.authorize` + `oauth2.approve` with the signed-in session.
 * Prefers the pre-registered Agent client id; falls back to DCR when it is missing.
 */
export async function connectMcpOAuthSilently(
  input: StartMcpOAuthConnectInput,
): Promise<{
  mcpId: string
  name: string
  url: string
  description?: string
  tokens: McpOAuthTokenSet
  clientInfo: McpOAuthClientInfo
}> {
  const fixedClientId = input.clientId?.trim()

  // Fast path for the seeded Appwrite Agent client: no DCR and no AS discovery.
  if (fixedClientId) {
    if (typeof window === 'undefined') {
      throw new Error('MCP OAuth can only run in the browser')
    }

    const redirectUri = getAssistantMcpOAuthRedirectUri()
    const resource = input.resource || input.url
    const scope = scopeFromInput(input)
    const state = randomUrlSafeString(24)
    const codeVerifier = randomUrlSafeString(64)
    const codeChallenge = await sha256Base64Url(codeVerifier)
    const authorizationDetails = JSON.stringify(
      MCP_DEFAULT_AUTHORIZATION_DETAILS,
    )
    const clientInfo = buildFixedClientInfo({
      clientId: fixedClientId,
      clientName: input.clientName,
      redirectUri,
    })

    try {
      const code = await resolveAuthorizationCode({
        clientId: fixedClientId,
        redirectUri,
        scope,
        state,
        codeChallenge,
        resource,
        authorizationDetails,
      })

      const tokens = await sdk.forConsole.oauth2.createToken({
        grantType: 'authorization_code',
        code,
        redirectUri,
        clientId: fixedClientId,
        codeVerifier,
        resource,
      })

      if (!tokens.access_token) {
        throw new Error('Token response did not include an access_token')
      }

      return {
        mcpId: input.mcpId,
        name: input.name,
        url: input.url,
        description: input.description,
        tokens,
        clientInfo,
      }
    } catch (error) {
      clearPendingMcpOAuthSession()
      // Fresh local/dev AS often has no seeded `appwrite-agent` app yet.
      if (isInvalidOAuthClientError(error)) {
        return await connectMcpOAuthSilentlyWithDcr(input)
      }
      throw error
    }
  }

  return await connectMcpOAuthSilentlyWithDcr(input)
}

/**
 * Discover AS (+ DCR only when no fixed client id), then open the authorize URL
 * in a popup. Resolves with authorization code after the callback page posts back.
 */
export async function startMcpOAuthConnect(
  input: StartMcpOAuthConnectInput,
): Promise<{ code: string; session: McpOAuthPendingSession }> {
  const { session, scope, codeChallenge, authorizationDetails } =
    await prepareMcpOAuthSession(input)

  const authorizeUrl = buildAuthorizeUrl({
    authorizationEndpoint: session.authorizationEndpoint,
    clientId: session.client.client_id,
    redirectUri: session.redirectUri,
    scope,
    state: session.state,
    codeChallenge,
    resource: session.resource,
    authorizationDetails,
  })

  const popup = window.open(
    authorizeUrl,
    'assistant-mcp-oauth',
    'popup=yes,width=520,height=720',
  )
  if (!popup) {
    // Popup blocked - fall back to top-level navigation.
    window.location.assign(authorizeUrl)
    return new Promise(() => {
      // Page is navigating away.
    })
  }

  return await waitForMcpOAuthPopup(popup, session)
}

function waitForMcpOAuthPopup(
  popup: Window,
  session: McpOAuthPendingSession,
): Promise<{ code: string; session: McpOAuthPendingSession }> {
  return new Promise((resolve, reject) => {
    let settled = false

    const cleanup = () => {
      window.removeEventListener('message', onMessage)
      window.clearInterval(closedPoll)
    }

    const settle = (fn: () => void) => {
      if (settled) return
      settled = true
      cleanup()
      fn()
    }

    const onMessage = (event: MessageEvent) => {
      if (event.origin !== window.location.origin) return
      const data = event.data as McpOAuthCallbackMessage | null
      if (!data || data.type !== ASSISTANT_MCP_OAUTH_MESSAGE_TYPE) return

      if (data.status === 'error') {
        settle(() => {
          clearPendingMcpOAuthSession()
          reject(
            new Error(
              data.errorDescription || data.error || 'MCP OAuth was cancelled',
            ),
          )
        })
        return
      }

      if (data.state !== session.state) {
        settle(() => {
          clearPendingMcpOAuthSession()
          reject(new Error('MCP OAuth state mismatch'))
        })
        return
      }

      settle(() => resolve({ code: data.code, session }))
    }

    window.addEventListener('message', onMessage)

    const closedPoll = window.setInterval(() => {
      if (!popup.closed) return
      settle(() => {
        // Callback may have already posted; only fail if still pending.
        const pending = readPendingMcpOAuthSession()
        if (pending?.state === session.state) {
          clearPendingMcpOAuthSession()
          reject(new Error('MCP OAuth window was closed'))
        }
      })
    }, 400)
  })
}

/**
 * Complete connect: exchange the auth code and return SDK-ready payloads.
 */
export async function completeMcpOAuthConnect(params: {
  code: string
  session?: McpOAuthPendingSession | null
}): Promise<{
  mcpId: string
  name: string
  url: string
  description?: string
  tokens: McpOAuthTokenSet
  clientInfo: McpOAuthClientInfo
}> {
  const session = params.session ?? readPendingMcpOAuthSession()
  if (!session) {
    throw new Error('No pending MCP OAuth session')
  }

  try {
    const tokens = await exchangeMcpOAuthCode({
      tokenEndpoint: session.tokenEndpoint,
      code: params.code,
      redirectUri: session.redirectUri,
      clientId: session.client.client_id,
      codeVerifier: session.codeVerifier,
      resource: session.resource,
    })

    return {
      mcpId: session.mcpId,
      name: session.name,
      url: session.url,
      description: session.description,
      tokens,
      clientInfo: session.client,
    }
  } finally {
    clearPendingMcpOAuthSession()
  }
}

export function parseMcpOAuthCallbackSearch(
  search: string | Record<string, unknown>,
): McpOAuthCallbackMessage {
  const params =
    typeof search === 'string'
      ? new URLSearchParams(search.startsWith('?') ? search : `?${search}`)
      : new URLSearchParams(
          Object.entries(search).flatMap(([key, value]) =>
            typeof value === 'string' ? [[key, value]] : [],
          ),
        )

  const error = params.get('error')
  if (error) {
    return {
      type: ASSISTANT_MCP_OAUTH_MESSAGE_TYPE,
      status: 'error',
      error,
      errorDescription: params.get('error_description') ?? undefined,
      state: params.get('state') ?? undefined,
    }
  }

  const code = params.get('code')
  const state = params.get('state')
  if (!code || !state) {
    return {
      type: ASSISTANT_MCP_OAUTH_MESSAGE_TYPE,
      status: 'error',
      error: 'invalid_callback',
      errorDescription: 'Missing authorization code or state',
    }
  }

  return {
    type: ASSISTANT_MCP_OAUTH_MESSAGE_TYPE,
    status: 'success',
    code,
    state,
  }
}
