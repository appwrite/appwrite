import { afterEach, beforeEach, describe, expect, mock, test } from 'bun:test'
import {
  createConversation,
  GrowthError,
  resolveGrowthTarget,
} from '@/lib/growth'
import { submitDocsFeedback, submitFeedback } from '@/lib/feedback'
import {
  submitEnterpriseApplication,
  submitPartnerApplication,
  submitStartupsApplication,
} from '@/lib/marketing/growth-forms'
import { submitSupportTicket } from '@/lib/support'

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
})

describe('resolveGrowthTarget', () => {
  test('uses the console endpoint with the session on Cloud', () => {
    expect(
      resolveGrowthTarget(true, 'https://fra.cloud.appwrite.io/v1/', true),
    ).toEqual({
      endpoint: 'https://fra.cloud.appwrite.io/v1',
      credentials: 'include',
    })
  })

  test('leaves the session out when the form keeps typed contact details', () => {
    expect(
      resolveGrowthTarget(true, 'https://cloud.appwrite.io/v1', false),
    ).toEqual({
      endpoint: 'https://cloud.appwrite.io/v1',
      credentials: 'omit',
    })
  })

  test('sends self-hosted consoles to Appwrite Cloud anonymously', () => {
    expect(
      resolveGrowthTarget(false, 'https://appwrite.example.com/v1', true),
    ).toEqual({
      endpoint: 'https://cloud.appwrite.io/v1',
      credentials: 'omit',
    })
  })
})

describe('createConversation', () => {
  test('posts JSON to the conversations route and drops empty values', async () => {
    const conversation = await createConversation({
      type: 'feedback',
      email: 'a@b.co',
      name: '',
      message: 'Great console',
      organizationId: '',
      attributes: { route: '/console', source: '' },
    })

    expect(conversation.type).toBe('feedback')
    const { url, init } = lastCall()
    expect(url.endsWith('/v1/growth/conversations')).toBe(true)
    expect(init.method).toBe('POST')
    expect(init.credentials).toBe('include')
    expect(init.headers).toEqual({
      'X-Appwrite-Project': 'console',
      'Content-Type': 'application/json',
    })
    expect(jsonBody()).toEqual({
      type: 'feedback',
      email: 'a@b.co',
      message: 'Great console',
      attributes: { route: '/console' },
    })
  })

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
    expect((error as GrowthError).isRateLimited).toBe(true)
    expect((error as GrowthError).message).toBe(
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

  test('treats the old 200 response as a failure', async () => {
    reply = () => new Response('{}', { status: 200 })

    await expect(
      createConversation({ type: 'feedback', message: 'Hi' }),
    ).rejects.toBeInstanceOf(GrowthError)
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
