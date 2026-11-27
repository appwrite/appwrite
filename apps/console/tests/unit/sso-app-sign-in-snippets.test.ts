import { describe, expect, test } from 'bun:test'
import { buildSSOSignInGuide } from '@/lib/sso-app-sign-in/snippets'

const input = {
  endpoint: 'https://fra.cloud.appwrite.io/v1',
  projectId: 'storefront',
  exampleEmail: 'jane@acme.com',
}

/** The JSON body of the HTTP tab's example answer to the provider lookup. */
function lookupAnswer(name: string) {
  const guide = buildSSOSignInGuide('http', {
    ...input,
    exampleProvider: { $id: 'provider-okta', name },
  })
  const code = guide.steps.find((step) => step.id === 'find')!.code
  return JSON.parse(code.slice(code.lastIndexOf('\n\n{') + 2))
}

describe('SSO sign-in snippets', () => {
  test("the HTTP example answer is valid JSON whatever the provider's name", () => {
    for (const name of ['Okta', 'Acme "Prod" login', 'Back\\slash', 'Ünïcode ✓']) {
      expect(lookupAnswer(name)).toEqual({
        total: 1,
        providers: [{ $id: 'provider-okta', name }],
      })
    }
  })
})
