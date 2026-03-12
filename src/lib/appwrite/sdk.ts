/**
 * Client-side Appwrite SDK Configuration
 *
 * This file provides both Console SDK and Project SDK instances for client-side use only.
 * All Appwrite operations are handled client-side.
 */

import {
  Account,
  Assistant,
  Avatars,
  Backups,
  Client,
  Console,
  Functions,
  Health,
  Locale,
  Messaging,
  Migrations,
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
  Domains,
  Organizations,
} from '@appwrite.io/console'
import {
  getDebugEndpointBaseUrl,
  subscribeToDebugEndpointChange,
} from '@/lib/debug-endpoint'

/**
 * True when the endpoint host is a known multi-region Appwrite cloud host
 * (e.g. cloud.appwrite.io or stage.cloud.appwrite.io). For those hosts we
 * build regional URLs by prefixing the region subdomain; for single-node or
 * custom hosts we do not add a region subdomain.
 */
function isMultiRegionSupported(url: URL): boolean {
  const host = url.hostname.toLowerCase()
  return host === 'cloud.appwrite.io' || host.endsWith('.cloud.appwrite.io')
}

/**
 * Single source of truth for API endpoints.
 * - Debug override (from debug menu) takes precedence when set.
 * - No region: returns base endpoint (override, VITE_APPWRITE_ENDPOINT, or current host).
 * - With region: when the base is a multi-region cloud host, returns
 *   region-specific endpoint by prefixing the region subdomain to the base
 *   host (e.g. base https://stage.cloud.appwrite.io/v1 → https://fra.stage.cloud.appwrite.io/v1).
 *   Follows the same pattern as the reference Console (getApiEndpoint + getSubdomain).
 */
export function getApiEndpoint(region?: string): string {
  let baseEndpoint: string

  if (typeof window !== 'undefined') {
    const debugBase = getDebugEndpointBaseUrl()
    if (debugBase) {
      baseEndpoint = debugBase
    } else {
      baseEndpoint =
        import.meta.env.VITE_APPWRITE_ENDPOINT ||
        `${window.location.protocol}//${window.location.host}/v1`
    }
  } else {
    baseEndpoint =
      import.meta.env.VITE_APPWRITE_ENDPOINT ||
      (typeof window !== 'undefined'
        ? `${window.location.protocol}//${window.location.host}/v1`
        : '')
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

  const subdomain =
    region &&
    region.trim().toLowerCase() !== 'unknown' &&
    isMultiRegionSupported(url)
      ? `${region.trim().toLowerCase().replace(/\s+/g, '')}.`
      : ''

  return `${protocol}//${subdomain}${hostname}/v1`
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

// Create Console SDK instance
function createConsoleSdk(client: Client) {
  return {
    client,
    account: new Account(client),
    avatars: new Avatars(client),
    functions: new Functions(client),
    health: new Health(client),
    locale: new Locale(client),
    projects: new Projects(client),
    teams: new Teams(client),
    users: new Users(client),
    migrations: new Migrations(client),
    console: new Console(client),
    assistant: new Assistant(client),
    sites: new Sites(client),
    domains: new Domains(client),
    storage: new Storage(client),
    organizations: new Organizations(client),
  }
}

// Initialize clients
const endpoint = getApiEndpoint()

const clientConsole = new Client()
const clientProject = new Client()

// Configure Console client
clientConsole.setEndpoint(endpoint).setProject('console')

// Configure Project client (will be set per-project)
clientProject.setEndpoint(endpoint).setMode('admin')

// When debug endpoint override changes, re-apply base endpoint to both clients
if (typeof window !== 'undefined') {
  subscribeToDebugEndpointChange(() => {
    const base = getApiEndpoint()
    clientConsole.setEndpoint(base)
    clientProject.setEndpoint(base)
  })
}

// Realtime instances: one per client (console vs project).
// For project subscriptions, call sdk.forProject(projectId) before subscribing
// so the project client has the correct project ID.
const realtimeConsole = new Realtime(clientConsole)
const realtimeProject = new Realtime(clientProject)

// Create Project SDK instance
const sdkForProject = {
  client: clientProject,
  account: new Account(clientProject),
  avatars: new Avatars(clientProject),
  backups: new Backups(clientProject),
  functions: new Functions(clientProject),
  health: new Health(clientProject),
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
  tablesDB: new TablesDB(clientProject),
  console: new Console(clientProject), // for suggestions API
}

// Export SDK instances
export const sdk = {
  // Console SDK - for managing console-level resources
  forConsole: createConsoleSdk(clientConsole),

  // Console SDK for specific region - for managing console-level resources in a specific region
  forConsoleIn(region: string) {
    const regionEndpoint = getApiEndpoint(region)
    const regionClient = new Client()
    regionClient.setEndpoint(regionEndpoint).setProject('console')
    return createConsoleSdk(regionClient)
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
export type ConsoleSdk = ReturnType<typeof createConsoleSdk>
export type ProjectSdk = typeof sdkForProject
