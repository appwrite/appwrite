import { afterAll, describe, expect, mock, test } from 'bun:test'
import * as actualSdk from '@/lib/appwrite/sdk'
import { createConsoleProject } from '@/lib/appwrite/console-projects'
import { isWildcardHostname } from '@/lib/utils/platform'

/**
 * The seeded web app is the project's origin boundary: the API folds platform
 * hostnames into the allowed-hostname list it uses for CORS with credentials
 * and for the origin check. A seeded `*` would let any website call the new
 * project's client API with a signed-in user's session cookie.
 */
describe('createConsoleProject', () => {
  // Copy the real module now: the namespace import is live and points at the mock once it is set.
  const realSdk = { ...actualSdk }

  afterAll(() => {
    mock.module('@/lib/appwrite/sdk', () => realSdk)
  })

  function mockSdk() {
    const webPlatforms: Array<{ name: string; hostname: string }> = []

    mock.module('@/lib/appwrite/sdk', () => ({
      ...actualSdk,
      sdk: {
        forConsole: {
          organization: () => ({
            createProject: async ({
              projectId,
              name,
            }: {
              projectId: string
              name: string
            }) => ({ $id: projectId, name, region: 'default' }),
          }),
        },
        forProject: () => ({
          project: {
            createWebPlatform: async (platform: {
              name: string
              hostname: string
            }) => {
              webPlatforms.push(platform)
              return platform
            },
          },
        }),
      },
    }))

    return webPlatforms
  }

  test('seeds a web app for local development, never for any hostname', async () => {
    const webPlatforms = mockSdk()

    await createConsoleProject({
      projectId: 'proj0000000000000000001',
      name: 'Launch',
      teamId: 'org00000000000000000001',
    })

    expect(webPlatforms).toHaveLength(1)
    expect(isWildcardHostname(webPlatforms[0].hostname)).toBe(false)
    expect(webPlatforms[0].hostname).toBe('localhost')
  })
})
