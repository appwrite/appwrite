import { describe, expect, test } from 'bun:test'
import {
  functionEmailAddress,
  functionUpdateBody,
  isFunctionEmailEnabled,
  putFunction,
  suggestFunctionEmailLabel,
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

describe('suggestFunctionEmailLabel', () => {
  test('turns the function name into a label with the random part', () => {
    expect(suggestFunctionEmailLabel('Order Mailer!', 'x7k2ab')).toBe(
      'order-mailer-x7k2ab',
    )
  })

  test('falls back to a generic base for a name without label characters', () => {
    expect(suggestFunctionEmailLabel('', 'x7k2ab')).toBe('mail-x7k2ab')
    expect(suggestFunctionEmailLabel(null, 'x7k2ab')).toBe('mail-x7k2ab')
    expect(suggestFunctionEmailLabel('--__--', 'x7k2ab')).toBe('mail-x7k2ab')
  })

  test('keeps the label within 63 characters and never ends a part with -', () => {
    const label = suggestFunctionEmailLabel(`${'a'.repeat(55)} b`, 'x7k2ab')
    expect(label.length).toBeLessThanOrEqual(63)
    expect(label).toBe(`${'a'.repeat(55)}-x7k2ab`)
  })

  test('adds a fresh random part by default', () => {
    const first = suggestFunctionEmailLabel('mailer')
    expect(first.startsWith('mailer-')).toBe(true)
    expect(first.length).toBe('mailer-'.length + 6)
    expect(first.slice('mailer-'.length)).toMatch(/^[a-z0-9]{6}$/)
  })
})

describe('functionEmailAddress', () => {
  test('shows the domain with the contact local part', () => {
    expect(functionEmailAddress('orders-x7k2ab.appwrite.email')).toBe(
      'contact@orders-x7k2ab.appwrite.email',
    )
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
