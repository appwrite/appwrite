import { describe, expect, test } from 'bun:test'
import {
  buildFunctionEmailAddress,
  functionUpdateBody,
  isFunctionEmailEnabled,
  putFunction,
  type FunctionUpdateClient,
  type FunctionWithEmail,
} from '@/lib/function-email'

describe('isFunctionEmailEnabled', () => {
  test('is on only when emailEnabled is true', () => {
    expect(
      isFunctionEmailEnabled({ emailEnabled: true } as FunctionWithEmail),
    ).toBe(true)
    expect(
      isFunctionEmailEnabled({ emailEnabled: false } as FunctionWithEmail),
    ).toBe(false)
    expect(isFunctionEmailEnabled({} as FunctionWithEmail)).toBe(false)
    expect(isFunctionEmailEnabled(null)).toBe(false)
  })
})

describe('buildFunctionEmailAddress', () => {
  test('puts function and project in the host and lowercases them', () => {
    expect(
      buildFunctionEmailAddress('MyProject', 'FnAbc', 'Appwrite.Email'),
    ).toBe('contact@fnabc-myproject.appwrite.email')
  })

  test('keeps hyphens in the project ID', () => {
    expect(
      buildFunctionEmailAddress('my-app', 'sendmail', 'appwrite.email'),
    ).toBe('contact@sendmail-my-app.appwrite.email')
  })

  test('uses the earlier form for a hyphenated function ID', () => {
    // `foo-bar` in `baz` and `foo` in `bar-baz` would otherwise share a host.
    expect(buildFunctionEmailAddress('baz', 'foo-bar', 'appwrite.email')).toBe(
      'foo-bar@baz.appwrite.email',
    )
    expect(buildFunctionEmailAddress('bar-baz', 'foo', 'appwrite.email')).toBe(
      'contact@foo-bar-baz.appwrite.email',
    )
  })

  test('falls back to the function ID local part when the host cannot hold it', () => {
    expect(buildFunctionEmailAddress('proj', 'Fn.Abc', 'appwrite.email')).toBe(
      'Fn.Abc@proj.appwrite.email',
    )
    expect(buildFunctionEmailAddress('proj', 'fn_abc', 'appwrite.email')).toBe(
      'fn_abc@proj.appwrite.email',
    )
    const longFn = 'f'.repeat(36)
    const longProject = 'p'.repeat(36)
    expect(
      buildFunctionEmailAddress(longProject, longFn, 'appwrite.email'),
    ).toBe(`${longFn}@${longProject}.appwrite.email`)
  })

  test('returns null when any part is missing', () => {
    expect(buildFunctionEmailAddress('p', 'f', undefined)).toBeNull()
    expect(buildFunctionEmailAddress('p', '', 'appwrite.email')).toBeNull()
    expect(buildFunctionEmailAddress(null, 'f', 'appwrite.email')).toBeNull()
  })
})

describe('functionUpdateBody', () => {
  test('keeps emailEnabled and drops functionId and undefined values', () => {
    expect(
      functionUpdateBody({
        functionId: 'fn',
        name: 'Hello',
        timeout: undefined,
        emailEnabled: false,
      }),
    ).toEqual({ name: 'Hello', emailEnabled: false })
  })
})

describe('putFunction', () => {
  test('PUTs the JSON body to the function', async () => {
    const calls: unknown[][] = []
    const client: FunctionUpdateClient = {
      config: { endpoint: 'https://cloud.example/v1', project: 'proj' },
      call: async (...args) => {
        calls.push(args)
        return { $id: 'fn', emailEnabled: true }
      },
    }

    const result = await putFunction(client, {
      functionId: 'fn',
      name: 'Hello',
      emailEnabled: true,
    })

    expect(result.emailEnabled).toBe(true)
    const [method, url, headers, body] = calls[0]
    expect(method).toBe('put')
    expect(String(url)).toBe('https://cloud.example/v1/functions/fn')
    expect(headers).toMatchObject({
      'X-Appwrite-Project': 'proj',
      'content-type': 'application/json',
    })
    expect(body).toEqual({ name: 'Hello', emailEnabled: true })
  })
})
