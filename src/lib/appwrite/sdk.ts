/**
 * Client-side Appwrite SDK Configuration
 *
 * This file provides both Console SDK and Project SDK instances for client-side use only.
 * All Appwrite operations are handled client-side.
 */

import {
  Account,
  Activities,
  Affiliates,
  Agent,
  Apps,
  Assistant,
  Avatars,
  Backups,
  Client,
  Console,
  Functions,
  ImageFormat,
  Locale,
  Manager,
  Messaging,
  Migrations,
  Oauth2,
  Project,
  Project as ProjectApi,
  Projects,
  Proxy,
  Realtime,
  Storage,
  Teams,
  Users,
  Vcs,
  Sites,
  Tokens,
  TablesDB,
  DocumentsDB,
  VectorsDB,
  Postgresql,
  Mysql,
  Mongo,
  Domains,
  Organization,
  Organizations,
  Presences,
  Usage,
  Webhooks,
  Notifications,
  Waf,
  type Models,
} from '@appwrite.io/console'
import {
  getDebugEndpointBaseUrl,
  subscribeToDebugEndpointChange,
} from '@/lib/debug-endpoint'
import { wrapServiceObject } from '@/lib/appwrite/slow-call-reporting'
import { getRuntimeConfig } from '@/lib/runtime-config'
import { resolveAppwriteEndpointFallback } from '@/lib/runtime-config-shared'
import { clearConsoleAccountCache } from '@/lib/console-account-cache'
import {
  fetchConsoleAccount,
  registerConsoleAccountGet,
} from '@/lib/console-account-get'
import {
  CONSOLE_IMPERSONATION_TARGET_KEY,
  clearConsoleImpersonationSession,
} from '@/lib/console-impersonation'
import {
  ensureFingerprintServerTimeSynced,
  resetFingerprintServerTimeCache,
} from '@/lib/fingerprint'
import { getActiveLanguage } from '@/lib/i18n/active-language'
import { subscribeToDebugOverrides } from '@/lib/debug-overrides'

/**
 * True when the endpoint host is a known multi-region Appwrite cloud host
 * (e.g. cloud.appwrite.io or cloud.staging.appwrite.io). For those hosts we
 * build regional URLs by prefixing the region subdomain; for single-node or
 * custom hosts we do not add a region subdomain.
 */
function isMultiRegionSupported(url: URL): boolean {
  const host = url.hostname.toLowerCase()
  return (
    host === 'cloud.appwrite.io' ||
    host.endsWith('.cloud.appwrite.io') ||
    host === 'cloud.staging.appwrite.io' ||
    host.endsWith('.cloud.staging.appwrite.io')
  )
}

/**
 * Single source of truth for API endpoints.
 * - Debug override (from debug menu) takes precedence when set.
 * - No region: returns base endpoint (override, runtime env, or profile-aware fallback).
 * - With region: when the base is a multi-region cloud host, returns
 *   region-specific endpoint by prefixing the region subdomain to the base
 *   host (e.g. base https://cloud.staging.appwrite.io/v1 → https://fra.cloud.staging.appwrite.io/v1).
 *   Follows the same pattern as the reference Console (getApiEndpoint + getSubdomain).
 */
export function getApiEndpoint(region?: string): string {
  let baseEndpoint: string

  if (typeof window !== 'undefined') {
    const debugBase = getDebugEndpointBaseUrl()
    if (debugBase) {
      baseEndpoint = debugBase
    } else {
      const config = getRuntimeConfig()
      baseEndpoint =
        config.appwriteEndpoint ||
        resolveAppwriteEndpointFallback(config.consoleProfile, window.location)
    }
  } else {
    const config = getRuntimeConfig()
    baseEndpoint =
      config.appwriteEndpoint ||
      (typeof window !== 'undefined'
        ? resolveAppwriteEndpointFallback(
            config.consoleProfile,
            window.location,
          )
        : resolveAppwriteEndpointFallback(config.consoleProfile))
  }

  if (!baseEndpoint) {
    throw new Error('VITE_APPWRITE_ENDPOINT is not configured')
  }

  let url: URL
  try {
    url = new URL(baseEndpoint)
  } catch {
    throw new Error('VITE_APPWRITE_ENDPOINT is not a valid URL')
  }

  const protocol = url.protocol
  const hostname = url.hostname
  const hostWithPort = url.port ? `${hostname}:${url.port}` : hostname

  const subdomain =
    region &&
    region.trim().toLowerCase() !== 'unknown' &&
    isMultiRegionSupported(url)
      ? `${region.trim().toLowerCase().replace(/\s+/g, '')}.`
      : ''

  return `${protocol}//${subdomain}${hostWithPort}/v1`
}

/** Compact endpoint label: host (with port when non-default) and path, without protocol. */
export function formatApiEndpointDisplay(endpoint: string): string {
  if (!endpoint) return ''

  try {
    const url = new URL(endpoint)
    const pathname = url.pathname || '/v1'
    return `${url.host}${pathname}`
  } catch {
    const match = endpoint.match(/https?:\/\/([^/]+)(\/.*)?/)
    if (match) {
      return `${match[1]}${match[2] || '/v1'}`
    }
    return endpoint.replace(/^https?:\/\//, '')
  }
}

/** Base endpoint (console / default). Use for console-level URLs. */
export function getBaseEndpoint(): string {
  return getApiEndpoint()
}

// Project region cache: when we fetch a project we register its region so forProject()
// and getProjectApiEndpoint use the correct regional endpoint.
const projectRegions = new Map<string, string>()

/**
 * Register a project's region so SDK uses the correct region endpoint for that project.
 * Called automatically when project is fetched; can be called explicitly if needed.
 */
export function setProjectRegion(
  projectId: string,
  region: string | undefined,
) {
  if (region && region.trim().toLowerCase() !== 'unknown') {
    projectRegions.set(
      projectId,
      region.trim().toLowerCase().replace(/\s+/g, ''),
    )
  }
}

/**
 * Get the cached region for a project (set when project is fetched).
 * Used when making console-region calls (e.g. upload CSV to console bucket).
 */
export function getProjectRegion(projectId: string): string | undefined {
  return projectRegions.get(projectId)
}

/**
 * Endpoint for project-scoped API calls and URL construction.
 * Uses cached region from setProjectRegion (populated when project is fetched).
 * When region is not yet cached, returns base endpoint.
 */
export function getProjectApiEndpoint(projectId: string): string {
  const region = projectRegions.get(projectId)
  return getApiEndpoint(region)
}

function wrapConsoleAccountGet<T extends { account: Account }>(sdkRaw: T): T {
  sdkRaw.account.get = (() =>
    fetchConsoleAccount()) as typeof sdkRaw.account.get
  return sdkRaw
}

// Create Console SDK instance (raw, no slow-call wrapping)
function createConsoleSdkRaw(client: Client) {
  return {
    client,
    account: new Account(client),
    affiliates: new Affiliates(client),
    apps: new Apps(client),
    oauth2: new Oauth2(client),
    avatars: new Avatars(client),
    postgresql: new Postgresql(client),
    mysql: new Mysql(client),
    mongo: new Mongo(client),
    functions: new Functions(client),
    locale: new Locale(client),
    manager: new Manager(client),
    projects: new Projects(client),
    teams: new Teams(client),
    users: new Users(client),
    migrations: new Migrations(client),
    console: new Console(client),
    agent: new Agent(client),
    /** Legacy `/console/assistant` chat stream. Prefer `agent` for conversations. */
    assistant: new Assistant(client),
    sites: new Sites(client),
    domains: new Domains(client),
    storage: new Storage(client),
    /**
     * Organization-scoped console API (`X-Appwrite-Organization`).
     * Required for `/organization/projects` and related org project routes.
     * Matches the reference console: `sdk.forConsole.organization(orgId)`.
     */
    organization(organizationId: string) {
      const id = String(organizationId ?? '').trim()
      if (!id) {
        throw new Error('Organization ID is required')
      }
      const organizationClient = new Client()
      installSetProjectWithHeader(organizationClient)
      organizationClient.setEndpoint(client.config.endpoint)
      if (client.config.project) {
        organizationClient.setProject(client.config.project)
      }
      if (client.config.locale) {
        organizationClient.setLocale(client.config.locale)
      }
      Object.assign(organizationClient.headers, client.getHeaders(), {
        'X-Appwrite-Organization': id,
      })
      return new Organization(organizationClient)
    },
    organizations: new Organizations(client),
    presences: new Presences(client),
    usage: new Usage(client),
    webhooks: new Webhooks(client),
    notifications: new Notifications(client),
  }
}

// Initialize clients
const endpoint = getApiEndpoint()

const clientConsole = new Client()
const clientProject = new Client()

/**
 * Upstream `Client.setProject` only stores `config.project`. Service methods
 * (`tablesDB.update`, etc.) pass `X-Appwrite-Project` from config on each call,
 * but raw `client.call` does not. Keep the shared console/project clients in
 * sync so `sdk.forProject(id)` / `setProject('console')` alone is enough for
 * every request on that instance.
 */
function installSetProjectWithHeader(client: Client) {
  const originalSetProject = client.setProject.bind(client)
  client.setProject = ((value: string) => {
    const projectId = String(value ?? '')
    client.headers['X-Appwrite-Project'] = projectId
    return originalSetProject(projectId)
  }) as Client['setProject']
}

installSetProjectWithHeader(clientConsole)
installSetProjectWithHeader(clientProject)

// Configure Console client
clientConsole.setEndpoint(endpoint).setProject('console')

// Configure Project client (will be set per-project via sdk.forProject)
clientProject.setEndpoint(endpoint).setMode('admin')

// Match server-side content (e.g. localized responses) to the active UI language.
clientConsole.setLocale(getActiveLanguage())
clientProject.setLocale(getActiveLanguage())

// Keep SDK locale in sync when the language changes (debug menu / storage event).
if (typeof window !== 'undefined') {
  subscribeToDebugOverrides(() => {
    const language = getActiveLanguage()
    if (clientConsole.config.locale !== language) {
      clientConsole.setLocale(language)
      clientProject.setLocale(language)
    }
  })
}

function scheduleConsoleFingerprintServerTimeSync(): Promise<void> {
  if (typeof window === 'undefined') return Promise.resolve()
  const ep = clientConsole.config.endpoint as string | undefined
  const proj = clientConsole.config.project as string | undefined
  if (!ep?.trim() || !proj?.trim()) return Promise.resolve()
  return ensureFingerprintServerTimeSynced(ep, proj)
}

// When debug endpoint override changes, re-apply base endpoint to both clients
if (typeof window !== 'undefined') {
  subscribeToDebugEndpointChange(() => {
    const base = getApiEndpoint()
    clientConsole.setEndpoint(base)
    clientProject.setEndpoint(base)
    resetFingerprintServerTimeCache()
    void scheduleConsoleFingerprintServerTimeSync()
  })

  void scheduleConsoleFingerprintServerTimeSync()
}

// Realtime instances: one per client (console vs project).
// For project subscriptions, call sdk.forProject(projectId) before subscribing
// so the project client has the correct project ID.
const realtimeConsole = new Realtime(clientConsole)
const realtimeProject = new Realtime(clientProject)

const IMPERSONATION_HEADER_KEYS = [
  'X-Appwrite-Impersonate-User-Id',
  'X-Appwrite-Impersonate-User-Email',
  'X-Appwrite-Impersonate-User-Phone',
] as const

function clearImpersonationHeaders(client: Client) {
  for (const key of IMPERSONATION_HEADER_KEYS) {
    delete client.headers[key]
  }
  const cfg = client.config as {
    impersonateuserid?: string
    impersonateuseremail?: string
    impersonateuserphone?: string
  }
  cfg.impersonateuserid = ''
  cfg.impersonateuseremail = ''
  cfg.impersonateuserphone = ''
}

/**
 * Some installs resolve an older `dist` build where `Client.prototype.setImpersonateUserId`
 * is missing even though newer sources include it. Mirror the SDK implementation so
 * impersonation always works when `headers` / `config` are present.
 */
function applyImpersonateUserIdToClient(client: Client, userId: string) {
  const id = userId.trim()
  if (!id) return

  const c = client as Client & {
    setImpersonateUserId?: (value: string) => Client
  }

  if (typeof c.setImpersonateUserId === 'function') {
    c.setImpersonateUserId(id)
    return
  }

  c.headers['X-Appwrite-Impersonate-User-Id'] = id
  const cfg = c.config as { impersonateuserid?: string }
  cfg.impersonateuserid = id
}

/**
 * Apply Console user impersonation on the main console client (and mirror on the
 * shared project client so project-scoped Console API calls use the same effective user).
 * Callers should persist session via `persistConsoleImpersonationSession` when starting.
 */
export function applyConsoleImpersonateUserId(targetUserId: string) {
  const id = String(targetUserId ?? '').trim()
  if (!id) return
  for (const client of [clientConsole, clientProject]) {
    clearImpersonationHeaders(client)
    applyImpersonateUserIdToClient(client, id)
  }
}

/** Remove impersonation headers from console and project clients. */
export function clearConsoleImpersonateUser() {
  for (const client of [clientConsole, clientProject]) {
    clearImpersonationHeaders(client)
  }
}

const CONSOLE_SDK_PROJECT_ID = 'console'

/**
 * Clears console auth state in the browser without calling Appwrite account APIs.
 * Use when the session cookie cannot be revoked via the API (e.g. account blocked with 403).
 *
 * - Clears operator impersonation.
 * - Removes Appwrite `cookieFallback` from localStorage (SDK uses it when cookies are restricted).
 * - Clears in-memory session/JWT/key on the shared console and project clients.
 * - Best-effort expiry of `a_session_console` on the current document host (only works for
 *   non-httpOnly cookies; cross-site session cookies require a successful server sign-out).
 */
export function clearConsoleSessionLocally(): void {
  if (typeof window === 'undefined') return

  clearConsoleImpersonateUser()
  clearConsoleImpersonationSession()
  clearConsoleAccountCache()

  try {
    window.localStorage.removeItem('cookieFallback')
  } catch {
    /* private mode */
  }

  const resetAuthOnClient = (client: Client) => {
    client.config.jwt = ''
    client.config.key = ''
    const cfg = client.config as { session?: string; cookie?: string }
    cfg.session = ''
    cfg.cookie = ''
    try {
      client.setCookie('')
    } catch {
      /* noop */
    }
  }
  resetAuthOnClient(clientConsole)
  resetAuthOnClient(clientProject)

  const cookieName = `a_session_${CONSOLE_SDK_PROJECT_ID}`
  const expired = 'Thu, 01 Jan 1970 00:00:00 GMT'
  const host = window.location.hostname
  const expire = (domain?: string) => {
    const d = domain ? `; domain=${domain}` : ''
    document.cookie = `${cookieName}=; expires=${expired}; path=/${d}`
  }
  expire()
  if (host) {
    expire(host)
    const parts = host.split('.')
    if (parts.length > 1) {
      expire(`.${parts.slice(-2).join('.')}`)
    }
  }
}

function restoreConsoleImpersonationFromSession() {
  try {
    const id = sessionStorage.getItem(CONSOLE_IMPERSONATION_TARGET_KEY)?.trim()
    if (id) {
      applyConsoleImpersonateUserId(id)
    }
  } catch {
    /* private mode / SSR */
  }
}

if (typeof window !== 'undefined') {
  restoreConsoleImpersonationFromSession()
}

/**
 * Site deployment screenshots are stored in console-owned storage. Preview URLs must use
 * project id `console` while targeting the project's regional API host (see
 * `getProjectApiEndpoint`), not the user's project id in the query string.
 */
export function getSiteScreenshotFilePreviewUrl(
  projectId: string,
  params: {
    bucketId: string
    fileId: string
    width?: number
    height?: number
    output?: ImageFormat
  },
): string {
  const c = new Client()
  Object.assign(c.config, clientConsole.config)
  Object.assign(c.headers, clientConsole.headers)
  c.setEndpoint(getProjectApiEndpoint(projectId)).setProject('console')
  return new Storage(c).getFilePreview(params)
}

/**
 * Realtime for console-scoped channels on the project's regional API host
 * (project id `console`, same session as the main console client).
 */
export function createRegionalConsoleRealtime(projectId: string): Realtime {
  const c = new Client()
  Object.assign(c.config, clientConsole.config)
  Object.assign(c.headers, clientConsole.headers)
  c.setEndpoint(getProjectApiEndpoint(projectId)).setProject('console')
  return new Realtime(c)
}

// Create Project SDK instance (raw), then wrap for slow-call reporting
const tablesDBForProject = new TablesDB(clientProject)
const documentsDBForProject = new DocumentsDB(clientProject)
const vectorsDBForProject = new VectorsDB(clientProject)

/**
 * Upstream product `update()` only serializes name / enabled / replicas.
 * Dedicated compute tier changes need `specification` on the same product
 * update path. Extend our project SDK instances so call sites always use
 * `tablesDB.update` / `documentsDB.update` / `vectorsDB.update` (never a
 * raw REST `client.call` from feature code).
 */
function installProductDatabaseUpdateSpecificationSupport(
  service: TablesDB | DocumentsDB | VectorsDB,
  pathPrefix: 'tablesdb' | 'documentsdb' | 'vectorsdb',
) {
  const originalUpdate = service.update.bind(service)
  service.update = ((
    paramsOrFirst: unknown,
    ...rest: unknown[]
  ): Promise<Models.Database> => {
    const params =
      paramsOrFirst &&
      typeof paramsOrFirst === 'object' &&
      !Array.isArray(paramsOrFirst)
        ? (paramsOrFirst as Record<string, unknown>)
        : {
            databaseId: paramsOrFirst,
            name: rest[0],
            enabled: rest[1],
            replicas: rest[2],
          }

    const specification =
      typeof params.specification === 'string'
        ? params.specification.trim()
        : undefined
    if (!specification) {
      return originalUpdate(
        paramsOrFirst as never,
        ...(rest as never[]),
      ) as Promise<Models.Database>
    }

    const databaseId = params.databaseId
    if (typeof databaseId === 'undefined') {
      return originalUpdate(
        paramsOrFirst as never,
        ...(rest as never[]),
      ) as Promise<Models.Database>
    }

    const payload: Record<string, unknown> = { specification }
    if (typeof params.name !== 'undefined') payload.name = params.name
    if (typeof params.enabled !== 'undefined') payload.enabled = params.enabled
    if (typeof params.replicas !== 'undefined') {
      payload.replicas = params.replicas
    }

    const uri = new URL(
      `${service.client.config.endpoint}/${pathPrefix}/${encodeURIComponent(String(databaseId))}`,
    )
    return service.client.call(
      'put',
      uri,
      {
        'X-Appwrite-Project': service.client.config.project,
        'content-type': 'application/json',
        accept: 'application/json',
      },
      payload,
    ) as Promise<Models.Database>
  }) as typeof service.update
}

installProductDatabaseUpdateSpecificationSupport(
  tablesDBForProject,
  'tablesdb',
)
installProductDatabaseUpdateSpecificationSupport(
  documentsDBForProject,
  'documentsdb',
)
installProductDatabaseUpdateSpecificationSupport(
  vectorsDBForProject,
  'vectorsdb',
)

const sdkForProjectRaw = {
  client: clientProject,
  account: new Account(clientProject),
  activities: new Activities(clientProject),
  apps: new Apps(clientProject),
  avatars: new Avatars(clientProject),
  backups: new Backups(clientProject),
  postgresql: new Postgresql(clientProject),
  mysql: new Mysql(clientProject),
  mongo: new Mongo(clientProject),
  functions: new Functions(clientProject),
  locale: new Locale(clientProject),
  messaging: new Messaging(clientProject),
  project: new Project(clientProject),
  projectApi: new ProjectApi(clientProject),
  storage: new Storage(clientProject),
  tokens: new Tokens(clientProject),
  teams: new Teams(clientProject),
  users: new Users(clientProject),
  vcs: new Vcs(clientProject),
  proxy: new Proxy(clientProject),
  migrations: new Migrations(clientProject),
  sites: new Sites(clientProject),
  tablesDB: tablesDBForProject,
  documentsDB: documentsDBForProject,
  vectorsDB: vectorsDBForProject,
  waf: new Waf(clientProject),
  console: new Console(clientProject), // suggestions API, unified database list
  usage: new Usage(clientProject),
  webhooks: new Webhooks(clientProject),
}

const sdkForProject = wrapServiceObject(
  sdkForProjectRaw as Record<string, unknown>,
  'forProject',
) as typeof sdkForProjectRaw

const consoleSdkRawBase = createConsoleSdkRaw(clientConsole)
registerConsoleAccountGet(
  consoleSdkRawBase.account.get.bind(consoleSdkRawBase.account),
)
const consoleSdkRaw = wrapConsoleAccountGet(consoleSdkRawBase)

// Export SDK instances
export const sdk = {
  // Console SDK - for managing console-level resources (wrapped for slow-call reporting)
  forConsole: wrapServiceObject(
    consoleSdkRaw as Record<string, unknown>,
    'forConsole',
  ) as ReturnType<typeof createConsoleSdkRaw>,

  // Console SDK for specific region - for managing console-level resources in a specific region
  forConsoleIn(region: string) {
    const regionEndpoint = getApiEndpoint(region)
    const regionClient = new Client()
    regionClient
      .setEndpoint(regionEndpoint)
      .setProject('console')
      .setLocale(getActiveLanguage())
    return wrapServiceObject(
      wrapConsoleAccountGet(createConsoleSdkRaw(regionClient)) as Record<
        string,
        unknown
      >,
      'forConsoleIn',
    ) as ReturnType<typeof createConsoleSdkRaw>
  },

  // Project SDK - for managing project-specific resources.
  // Endpoint is set from cached region (setProjectRegion when project is fetched) or explicit region param.
  // Always fetch project first in loaders so region is cached before project-scoped calls.
  forProject(projectId: string, region?: string) {
    const effectiveRegion = region ?? projectRegions.get(projectId)
    const projectEndpoint = getApiEndpoint(effectiveRegion)

    if (projectEndpoint !== clientProject.config.endpoint) {
      clientProject.setEndpoint(projectEndpoint)
    }
    if (projectId !== clientProject.config.project) {
      clientProject.setProject(projectId)
    }

    return sdkForProject
  },

  /**
   * Realtime for console-level subscriptions (sites, functions, deployments,
   * executions, migrations, platform ping, rules). Uses project = 'console'.
   * Prefer `registerConsoleRealtimeListener` from `@/lib/realtime` so the app
   * keeps a single WebSocket; direct subscribe() here forces reconnects when
   * combined with other features.
   */
  getConsoleRealtime(): Realtime {
    return realtimeConsole
  },

  /**
   * Realtime for project-level subscriptions. Call sdk.forProject(projectId)
   * before subscribing so the client has the correct project ID.
   * Only one project subscription scope should be active at a time; close
   * the subscription before switching to another project.
   */
  getProjectRealtime(): Realtime {
    return realtimeProject
  },
}

// Export types for TypeScript
export type ConsoleSdk = ReturnType<typeof createConsoleSdkRaw>
export type ProjectSdk = typeof sdkForProject
