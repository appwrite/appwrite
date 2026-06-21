import { ID, type ProjectKeyScopes } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

const EPHEMERAL_KEY_TTL_MS = 60 * 60 * 1000

export type EphemeralApiKeyResult = {
  keyId: string
  secret: string
  expire: string
  scopes: string[]
}

export async function createEphemeralApiKeyForExplorer(
  projectId: string,
  scopes: string[],
  methodLabel: string,
): Promise<EphemeralApiKeyResult> {
  if (scopes.length === 0) {
    throw new Error('This endpoint has no listed scopes for an ephemeral key.')
  }

  const expireDate = new Date(Date.now() + EPHEMERAL_KEY_TTL_MS)
  const expire = expireDate.toISOString()
  const name = `[Explorer] ${methodLabel} (${expireDate.toLocaleString()})`

  const result = await sdk.forProject(projectId).project.createKey({
    keyId: ID.unique(),
    name,
    scopes: scopes as ProjectKeyScopes[],
    expire,
  })

  if (!result.secret?.trim()) {
    throw new Error('Failed to create API key: empty secret')
  }

  return {
    keyId: result.$id,
    secret: result.secret,
    expire,
    scopes,
  }
}
