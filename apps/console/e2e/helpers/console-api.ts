import fs from 'node:fs'
import path from 'node:path'
import { env } from '../config/env'
import { CONSOLE_SESSION_COOKIE_NAME } from '../../src/lib/console-session-cookie'

const AUTH_STATE_PATH = path.join('e2e', '.auth', 'auth.json')
const CONSOLE_PROJECT_ID = 'console'
const REQUEST_TIMEOUT_MS = 30_000

type StorageCookie = { name?: string; value?: string }
type StorageOrigin = {
  origin?: string
  localStorage?: Array<{ name?: string; value?: string }>
}
type StorageState = {
  cookies?: StorageCookie[]
  origins?: StorageOrigin[]
}

export type ConsoleApiProject = {
  $id: string
  name: string
  $createdAt?: string
}

function queryString(method: string, values?: unknown[], attribute?: string) {
  return JSON.stringify({
    method,
    ...(attribute !== undefined ? { attribute } : {}),
    ...(values !== undefined ? { values } : {}),
  })
}

function consoleEndpoint(): string {
  return env.VITE_APPWRITE_ENDPOINT.replace(/\/+$/, '')
}

function parseStorageState(raw: string): StorageState {
  try {
    return JSON.parse(raw) as StorageState
  } catch {
    return JSON.parse(
      Buffer.from(raw, 'base64').toString('utf-8'),
    ) as StorageState
  }
}

function fallbackCookiesFromState(state: StorageState): string | null {
  for (const origin of state.origins ?? []) {
    const fallback = origin.localStorage?.find(
      (item) => item.name === 'cookieFallback',
    )?.value
    if (typeof fallback === 'string' && fallback.trim()) {
      return fallback.trim()
    }
  }

  const session = (state.cookies ?? []).find(
    (cookie) => cookie.name === CONSOLE_SESSION_COOKIE_NAME,
  )?.value
  if (typeof session === 'string' && session.trim()) {
    return JSON.stringify({ [CONSOLE_SESSION_COOKIE_NAME]: session.trim() })
  }

  return null
}

function readAuthState(): StorageState | null {
  try {
    return parseStorageState(fs.readFileSync(AUTH_STATE_PATH, 'utf-8'))
  } catch {
    return null
  }
}

/**
 * Console sessions live on localhost; Appwrite APIs are cross-origin, so the
 * SDK sends `cookieFallback` as `X-Fallback-Cookies`. Reconstruct that from
 * Playwright storage state or E2E_TEST_SESSION_SECRET.
 */
export function getConsoleFallbackCookies(): string | null {
  const fromFile = readAuthState()
  if (fromFile) {
    const cookies = fallbackCookiesFromState(fromFile)
    if (cookies) return cookies
  }

  const secret = env.E2E_TEST_SESSION_SECRET
  if (!secret) return null
  try {
    return fallbackCookiesFromState(parseStorageState(secret))
  } catch {
    return null
  }
}

async function consoleFetch(
  method: string,
  apiPath: string,
  options?: {
    organizationId?: string
    searchParams?: URLSearchParams
    okStatuses?: number[]
  },
): Promise<{ status: number; body: unknown }> {
  const fallbackCookies = getConsoleFallbackCookies()
  if (!fallbackCookies) {
    throw new Error(
      `Missing console session in ${AUTH_STATE_PATH}. Run e2e setup first.`,
    )
  }

  const url = new URL(`${consoleEndpoint()}${apiPath}`)
  if (options?.searchParams) {
    url.search = options.searchParams.toString()
  }

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Appwrite-Project': CONSOLE_PROJECT_ID,
    'X-Fallback-Cookies': fallbackCookies,
  }
  if (method !== 'GET') {
    headers['Content-Type'] = 'application/json'
  }
  const organizationId = options?.organizationId?.trim()
  if (organizationId) {
    headers['X-Appwrite-Organization'] = organizationId
  }

  const response = await fetch(url, {
    method,
    headers,
    signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
    ...(method !== 'GET' ? { body: '{}' } : {}),
  })

  const text = await response.text()
  let body: unknown = text
  if (text) {
    try {
      body = JSON.parse(text) as unknown
    } catch {
      body = text
    }
  }

  const okStatuses = options?.okStatuses ?? [200, 201, 204]
  if (!okStatuses.includes(response.status)) {
    const snippet =
      typeof body === 'string'
        ? body.slice(0, 400)
        : JSON.stringify(body).slice(0, 400)
    throw new Error(
      `Console API ${method} ${apiPath} failed (${response.status}): ${snippet}`,
    )
  }

  return { status: response.status, body }
}

function readProjects(body: unknown): ConsoleApiProject[] {
  if (!body || typeof body !== 'object') return []
  const rows = (body as { projects?: unknown }).projects
  if (!Array.isArray(rows)) return []
  return rows.flatMap((row) => {
    if (!row || typeof row !== 'object') return []
    const project = row as {
      $id?: unknown
      name?: unknown
      $createdAt?: unknown
    }
    if (typeof project.$id !== 'string' || !project.$id) return []
    return [
      {
        $id: project.$id,
        name: typeof project.name === 'string' ? project.name : '',
        $createdAt:
          typeof project.$createdAt === 'string'
            ? project.$createdAt
            : undefined,
      },
    ]
  })
}

export async function listOrganizationProjects(
  organizationId: string,
  options?: { startsWithName?: string; limit?: number },
): Promise<ConsoleApiProject[]> {
  const pageSize = options?.limit ?? 100
  const projects: ConsoleApiProject[] = []
  let offset = 0

  for (let page = 0; page < 50; page += 1) {
    const queries = [
      queryString('limit', [pageSize]),
      queryString('offset', [offset]),
    ]
    if (options?.startsWithName) {
      queries.unshift(
        queryString('startsWith', [options.startsWithName], 'name'),
      )
    }

    const searchParams = new URLSearchParams()
    queries.forEach((query, index) => {
      searchParams.append(`queries[${index}]`, query)
    })
    searchParams.set('total', 'false')

    try {
      const { body } = await consoleFetch('GET', '/organization/projects', {
        organizationId,
        searchParams,
      })
      const batch = readProjects(body)
      projects.push(...batch)
      if (batch.length < pageSize) break
      offset += pageSize
    } catch (error) {
      if (!options?.startsWithName || page > 0) throw error
      console.error(
        '[e2e] startsWith name query rejected; listing all organization projects',
      )
      return listOrganizationProjects(organizationId, { limit: pageSize })
    }
  }

  return projects
}

export async function deleteOrganizationProject(
  organizationId: string,
  projectId: string,
): Promise<void> {
  await consoleFetch(
    'DELETE',
    `/organization/projects/${encodeURIComponent(projectId)}`,
    {
      organizationId,
      okStatuses: [200, 204, 404],
    },
  )
}
