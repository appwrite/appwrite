import { describe, expect, test } from 'bun:test'
import { getNavigableRedirect, isWebRedirect } from '@/lib/oauth2/redirect'

describe('getNavigableRedirect', () => {
  test('keeps web and native app redirects', () => {
    for (const uri of [
      'https://app.example/callback?code=abc&state=x',
      'http://127.0.0.1:8123/callback?code=abc',
      'cursor://anysphere.cursor-retrieval/oauth/callback?code=abc',
      'vscode://publisher.extension/callback?code=abc',
      'com.example.app:/oauth2redirect?code=abc',
    ]) {
      expect(getNavigableRedirect(uri)).toBe(uri)
    }
  })

  test('drops schemes the browser would run or render itself', () => {
    for (const uri of [
      'javascript:alert(document.domain)',
      'JavaScript:alert(1)',
      ' javascript:alert(1)',
      'java\tscript:alert(1)',
      'data:text/html,<p>x</p>',
      'vbscript:msgbox(1)',
      'blob:https://cloud.appwrite.io/0b6b6c3e',
      'file:///etc/passwd',
      'filesystem:https://cloud.appwrite.io/temporary/x',
      'about:blank',
      '/relative/path',
      '',
      undefined,
    ]) {
      expect(getNavigableRedirect(uri)).toBeUndefined()
    }
  })

  test('agrees with isWebRedirect on web URLs', () => {
    const uri = 'https://app.example/callback'
    expect(isWebRedirect(uri)).toBe(true)
    expect(getNavigableRedirect(uri)).toBe(uri)
  })
})
