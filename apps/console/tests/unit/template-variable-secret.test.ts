import { describe, expect, test } from 'bun:test'
import { isTemplateVariableSecret } from '@/lib/template-variables'

describe('isTemplateVariableSecret', () => {
  test('treats a password input as secret (REDIS_PASSWORD)', () => {
    expect(isTemplateVariableSecret({ secret: false, type: 'password' })).toBe(
      true,
    )
  })

  test('treats a password input as secret when the server omits the flag (STRIPE_SECRET_KEY)', () => {
    expect(isTemplateVariableSecret({ type: 'password' })).toBe(true)
  })

  test('keeps a plain text input readable (REDIS_HOST)', () => {
    expect(isTemplateVariableSecret({ secret: false, type: 'text' })).toBe(
      false,
    )
  })

  test('honours a catalog that marks a non-password input secret', () => {
    expect(isTemplateVariableSecret({ secret: true, type: 'text' })).toBe(true)
  })
})
