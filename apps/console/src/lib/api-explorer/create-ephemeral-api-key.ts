import type { ProjectKeyScopes } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

const EPHEMERAL_KEY_TTL_SECONDS = 60 * 60

export type EphemeralApiKeyResult = {
  keyId: string
  secret: string
  expire: string
  scopes: string[]
}

export async function createEphemeralApiKeyForExplorer(
  projectId: string,
  scopes: string[],
): Promise<EphemeralApiKeyResult> {
  if (scopes.length === 0) {
    throw new Error('This endpoint has no listed scopes for an ephemeral key.')
  }

  const result = await sdk.forProject(projectId).project.createEphemeralKey({
    scopes: scopes as ProjectKeyScopes[],
    duration: EPHEMERAL_KEY_TTL_SECONDS,
  })

  if (!result.secret?.trim()) {
    throw new Error('Failed to create ephemeral API key: empty secret')
  }

  return {
    keyId: result.$id,
    secret: result.secret,
    expire: result.expire,
    scopes: result.scopes,
  }
}
