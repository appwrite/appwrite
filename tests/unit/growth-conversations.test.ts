import { afterEach, beforeEach, describe, expect, mock, test } from 'bun:test'
import { AppwriteException } from '@appwrite.io/console'
import * as profiles from '@/lib/console-profiles'
import * as sdk from '@/lib/appwrite/sdk'
import type { GrowthError as GrowthErrorType } from '@/lib/growth'

// The console's profile and endpoint, as the running console would report them.
// Null leaves the real value, so other test files see no change.
// jwt: the signed-in user's JWT; null means no session, so createJWT answers
// 401. jwtError: createJWT fails for a signed-in user.
const consoleState: {
  cloud: boolean | null
  endpoint: string | null
  jwt: string | null
  jwtError: AppwriteException | null
} = {
  cloud: null,
  endpoint: null,
  jwt: null,
  jwtError: null,
}
const { isCloudProfile } = profiles
const { getBaseEndpoint } = sdk

mock.module('@/lib/console-profiles', () => ({
  ...profiles,
  isCloudProfile: (...args: Parameters<typeof isCloudProfile>) =>
    consoleState.cloud ?? isCloudProfile(...args),
}))
mock.module('@/lib/appwrite/sdk', () => ({
  ...sdk,
  getBaseEndpoint: () => consoleState.endpoint ?? getBaseEndpoint(),
  sdk: {
    ...sdk.sdk,
    forConsole: {
      ...sdk.sdk.forConsole,
      account: {
        ...sdk.sdk.forConsole.account,
        createJWT: async () => {
          if (consoleState.jwtError) {
            throw consoleState.jwtError
          }
          if (consoleState.jwt === null) {
            throw new AppwriteException(
              'User (role: guests) missing scopes (["account"])',
              401,
              'general_unauthorized_scope',
            )
          }
          return { jwt: consoleState.jwt }
        },
      },
    },
  },
}))

const { createConversation, GrowthError } = await import('@/lib/growth')
const { submitDocsFeedback, submitFeedback } = await import('@/lib/feedback')
const {
  submitEnterpriseApplication,
  submitPartnerApplication,
  submitStartupsApplication,
} = await import('@/lib/marketing/growth-forms')
const { submitSupportTicket } = await import('@/lib/support')

type Call = { url: string; init: RequestInit }

const originalFetch = globalThis.fetch
let calls: Call[] = []
let reply: () => Response

function created(): Response {
  return new Response(
    JSON.stringify({ type: 'feedback', email: 'a@b.co', organizationId: '' }),
    { status: 201, headers: { 'Content-Type': 'application/json' } },
  )
}

function lastCall(): Call {
  const call = calls.at(-1)
  if (!call) throw new Error('fetch was not called')
  return call
}

function jsonBody(): Record<string, unknown> {
  return JSON.parse(lastCall().init.body as string)
}

function formBody(): FormData {
  const body = lastCall().init.body
  if (!(body instanceof FormData)) throw new Error('body is not FormData')
  return body
}

beforeEach(() => {
  calls = []
  reply = created
  globalThis.fetch = mock(
    async (url: string | URL | Request, init?: RequestInit) => {
      calls.push({ url: String(url), init: init ?? {} })
      return reply()
    },
  ) as unknown as typeof fetch
})

afterEach(() => {
  globalThis.fetch = originalFetch
  consoleState.cloud = null
  consoleState.endpoint = null
  consoleState.jwt = null
  consoleState.jwtError = null
})

function sentJwt(): string | undefined {
  return (lastCall().init.headers as Record<string, string>)['X-Appwrite-JWT']
}

describe('where conversations go', () => {
  test('Cloud consoles send to their own endpoint as the signed-in user, without cookies', async () => {
    consoleState.cloud = true
    consoleState.endpoint = 'https://fra.cloud.appwrite.io/v1/'
    consoleState.jwt = 'user-jwt'

    await submitFeedback({
      message: 'Nice',
      source: 'navbar',
      route: '/',
      email: 'a@b.co',
    })

    expect(lastCall().url).toBe(
      'https://fra.cloud.appwrite.io/v1/growth/conversations',
    )
    expect(lastCall().init.credentials).toBe('omit')
    expect(sentJwt()).toBe('user-jwt')
  })

  test('signed-out Cloud visitors send anonymously with their email', async () => {
    consoleState.cloud = true
    consoleState.endpoint = 'https://fra.cloud.appwrite.io/v1'

    await submitFeedback({
      message: 'Nice',
      source: 'navbar',
      route: '/',
      email: 'a@b.co',
    })

    expect(sentJwt()).toBeUndefined()
    expect(jsonBody()).toMatchObject({ email: 'a@b.co' })
  })

  test('a signed-in user whose JWT fails is not sent anonymously', async () => {
    consoleState.cloud = true
    consoleState.endpoint = 'https://fra.cloud.appwrite.io/v1'
    consoleState.jwtError = new AppwriteException('Server Error', 500)

    await expect(
      submitSupportTicket({
        email: 'a@b.co',
        name: 'Ada',
        subject: 'Deploy fails',
        message: 'Build error',
        organizationId: 'org1',
      }),
    ).rejects.toBeInstanceOf(GrowthError)
    expect(calls).toHaveLength(0)
  })

  test('Cloud forms that keep typed contact details leave the session out', async () => {
    consoleState.cloud = true
    consoleState.endpoint = 'https://fra.cloud.appwrite.io/v1'
    consoleState.jwt = 'user-jwt'

    await submitStartupsApplication({
      name: 'Walter',
      email: 'walter@acme.co',
      companyName: 'Acme',
      companyUrl: 'acme.co',
    })

    expect(lastCall().url).toBe(
      'https://fra.cloud.appwrite.io/v1/growth/conversations',
    )
    expect(sentJwt()).toBeUndefined()
  })

  test('self-hosted consoles send to Appwrite Cloud anonymously', async () => {
    consoleState.cloud = false
    consoleState.endpoint = 'https://appwrite.example.com/v1'
    consoleState.jwt = 'self-hosted-jwt'

    await submitSupportTicket({
      email: 'a@b.co',
      name: 'Ada',
      subject: 'Deploy fails',
      message: 'Build error',
      organizationId: 'org1',
    })

    expect(lastCall().url).toBe(
      'https://cloud.appwrite.io/v1/growth/conversations',
    )
    expect(sentJwt()).toBeUndefined()
  })
})

describe('createConversation', () => {
  test('sends one multipart request when there is an attachment', async () => {
    await createConversation({
      type: 'support',
      message: 'Broken',
      attributes: { country: 'NL' },
      attachment: new File(['log'], 'log.txt', { type: 'text/plain' }),
    })

    const { init } = lastCall()
    expect(init.headers).toEqual({ 'X-Appwrite-Project': 'console' })
    const form = formBody()
    expect(form.get('type')).toBe('support')
    expect(form.get('attributes')).toBe('{"country":"NL"}')
    expect((form.get('attachment') as File).name).toBe('log.txt')
  })

  test('surfaces rate limiting', async () => {
    reply = () => new Response('{}', { status: 429 })

    const error = await createConversation({
      type: 'docs',
      email: 'a@b.co',
      attributes: { rating: 'positive' },
    }).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(GrowthError)
    expect((error as GrowthErrorType).isRateLimited).toBe(true)
    expect((error as GrowthErrorType).message).toBe(
      'Too many requests. Try again in a few minutes.',
    )
  })

  test('surfaces the server message for invalid params', async () => {
    reply = () =>
      new Response(
        JSON.stringify({
          message: 'Attribute "companyUrl" is required.',
          code: 400,
        }),
        { status: 400 },
      )

    await expect(
      createConversation({
        type: 'startup',
        name: 'Walter',
        email: 'a@b.co',
        attributes: { companyName: 'Acme', companyUrl: '' },
      }),
    ).rejects.toThrow('Attribute "companyUrl" is required.')
  })

  test('resolves on 201 even when the body is empty', async () => {
    reply = () => new Response(null, { status: 201 })

    await expect(
      createConversation({ type: 'feedback', email: 'a@b.co', message: 'Hi' }),
    ).resolves.toBeUndefined()
  })

  test('treats the old 200 response as a failure', async () => {
    reply = () => new Response('{}', { status: 200 })

    await expect(
      createConversation({ type: 'feedback', message: 'Hi' }),
    ).rejects.toBeInstanceOf(GrowthError)
  })
})

describe('feedback from signed-out visitors', () => {
  test('is not sent without a valid email', async () => {
    for (const email of ['', '  ', 'not-an-email']) {
      await expect(
        submitFeedback({
          message: '[Positive feedback]\n\nNice',
          source: 'navbar',
          route: '/pricing',
          email,
        }),
      ).rejects.toBeInstanceOf(GrowthError)
    }
    expect(calls).toHaveLength(0)
  })

  test('is sent with the typed email', async () => {
    await submitFeedback({
      message: '[Positive feedback]\n\nNice',
      source: 'navbar',
      route: '/pricing',
      email: ' visitor@acme.co ',
    })

    expect(jsonBody()).toMatchObject({
      type: 'feedback',
      email: 'visitor@acme.co',
    })
  })
})

describe('call sites', () => {
  test('general feedback carries the page and source as attributes', async () => {
    await submitFeedback({
      message: '[Positive feedback]\n\nNice',
      source: 'command-center',
      route: '/project-123/overview',
      email: 'a@b.co',
      name: 'Ada',
      organizationId: 'org1',
      projectId: 'project1',
    })

    expect(jsonBody()).toEqual({
      type: 'feedback',
      email: 'a@b.co',
      name: 'Ada',
      message: '[Positive feedback]\n\nNice',
      organizationId: 'org1',
      projectId: 'project1',
      attributes: {
        route: '/project-123/overview',
        source: 'command-center',
      },
    })
  })

  test('docs feedback sends the rating and route', async () => {
    await submitDocsFeedback({
      type: 'negative',
      route: '/docs/products/auth',
      comment: 'Missing example',
      email: 'a@b.co',
    })

    expect(jsonBody()).toEqual({
      type: 'docs',
      email: 'a@b.co',
      message: 'Missing example',
      attributes: { rating: 'negative', route: '/docs/products/auth' },
    })
  })

  test('support tickets are multipart with organization and project params', async () => {
    await submitSupportTicket({
      email: 'a@b.co',
      name: 'Ada',
      subject: 'Deploy fails',
      message: 'Build error',
      organizationId: 'org1',
      projectId: 'project1',
      attachment: new File(['x'], 'x.png', { type: 'image/png' }),
    })

    const form = formBody()
    expect(form.get('type')).toBe('support')
    expect(form.get('subject')).toBe('Deploy fails')
    expect(form.get('organizationId')).toBe('org1')
    expect(form.get('projectId')).toBe('project1')
    expect(form.has('customFields')).toBe(false)
    expect(form.has('attributes')).toBe(false)
  })

  test('support tickets reject attachments over 5 MB before sending', async () => {
    const large = new File([new Uint8Array(5 * 1024 * 1024 + 1)], 'big.bin')

    await expect(
      submitSupportTicket({
        email: 'a@b.co',
        name: 'Ada',
        subject: 'Big',
        message: 'File',
        organizationId: 'org1',
        attachment: large,
      }),
    ).rejects.toThrow('Attachment must be 5 MB or less')
    expect(calls).toHaveLength(0)
  })

  test('startup applications send name and email without the session', async () => {
    await submitStartupsApplication({
      name: 'Walter',
      email: 'walter@acme.co',
      companyName: 'Acme',
      companyUrl: 'acme.co',
    })

    expect(lastCall().init.credentials).toBe('omit')
    expect(jsonBody()).toEqual({
      type: 'startup',
      email: 'walter@acme.co',
      name: 'Walter',
      attributes: { companyName: 'Acme', companyUrl: 'https://acme.co' },
    })
  })

  test('bare domains get https, even ones that start with "http"', async () => {
    await submitStartupsApplication({
      name: 'Walter',
      email: 'walter@acme.co',
      companyName: 'HTTPie',
      companyUrl: 'httpie.io',
    })

    expect(jsonBody()).toMatchObject({
      attributes: { companyUrl: 'https://httpie.io' },
    })
  })

  test('partner applications carry the message', async () => {
    await submitPartnerApplication({
      name: 'Walter',
      email: 'walter@acme.co',
      companyName: 'Acme',
      companyUrl: 'https://acme.co',
      message: 'We build apps',
    })

    expect(jsonBody()).toMatchObject({
      type: 'partner',
      message: 'We build apps',
      attributes: { companyName: 'Acme', companyUrl: 'https://acme.co' },
    })
  })

  test('enterprise applications join the name and keep the company attributes', async () => {
    await submitEnterpriseApplication({
      firstName: 'Ada',
      lastName: 'Lovelace',
      email: 'ada@acme.co',
      companyName: 'Acme',
      companySize: '51-200',
      companyWebsite: 'acme.co',
      preferredDeployment: null,
      timeline: 'Now',
      useCase: 'Migrate from Firebase',
      cloudEmail: 'ada@acme.co',
    })

    expect(lastCall().init.credentials).toBe('omit')
    expect(jsonBody()).toEqual({
      type: 'enterprise',
      email: 'ada@acme.co',
      name: 'Ada Lovelace',
      message: 'Migrate from Firebase',
      attributes: {
        companyName: 'Acme',
        companySize: '51-200',
        companyWebsite: 'https://acme.co',
        timeline: 'Now',
        cloudEmail: 'ada@acme.co',
        platform: 'appwrite',
      },
    })
  })
})
