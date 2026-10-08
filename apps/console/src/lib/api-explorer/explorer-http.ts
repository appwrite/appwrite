import { sdk } from '@/lib/appwrite/sdk'

/**
 * Fetch for API explorer test requests, which target arbitrary endpoints the
 * user builds. Browser session cookies (e.g. a_session_{projectId} from the
 * user's app) are never attached.
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
  const { jwt } = await sdk.forProject(projectId).users.createJWT({ userId })
  if (!jwt?.trim()) {
    throw new Error('Failed to create JWT: empty response')
  }
  return jwt
}
