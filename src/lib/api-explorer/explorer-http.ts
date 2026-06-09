import { getProjectApiEndpoint } from '@/lib/appwrite/sdk'

/**
 * Fetch for API explorer test requests. Intentionally bypasses the Appwrite SDK so
 * browser session cookies (e.g. a_session_{projectId} from the user's app) are
 * never attached. The SDK always uses credentials: "include" and has no option
 * to disable cookies.
 */
export async function explorerFetch(
  url: string,
  init: RequestInit = {},
): Promise<Response> {
  return fetch(url, {
    ...init,
    credentials: 'omit',
    mode: 'cors',
    cache: 'no-store',
    headers: init.headers,
  })
}

/** Admin-only call to create a user JWT. Uses the console operator session, separate from explorer test requests. */
export async function createUserJwtForExplorer(
  projectId: string,
  userId: string,
): Promise<string> {
  const endpoint = getProjectApiEndpoint(projectId).replace(/\/$/, '')
  const url = `${endpoint}/users/${encodeURIComponent(userId)}/jwts`

  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Appwrite-Project': projectId,
      'X-Appwrite-Mode': 'admin',
    },
    credentials: 'include',
    body: JSON.stringify({}),
  })

  const text = await response.text()
  let data: { jwt?: string; message?: string } = {}
  if (text.trim()) {
    try {
      data = JSON.parse(text) as { jwt?: string; message?: string }
    } catch {
      data = { message: text }
    }
  }

  if (!response.ok) {
    throw new Error(data.message || `Failed to create JWT (${response.status})`)
  }

  if (!data.jwt?.trim()) {
    throw new Error('Failed to create JWT: empty response')
  }

  return data.jwt
}
