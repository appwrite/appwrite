import { describe, expect, test } from 'bun:test'
import { getAppLinkHref } from '@/lib/oauth2/app-links'

describe('getAppLinkHref', () => {
  test('keeps web and mail links', () => {
    expect(getAppLinkHref('https://app.example/terms')).toBe(
      'https://app.example/terms',
    )
    expect(getAppLinkHref(' http://app.example/privacy ')).toBe(
      'http://app.example/privacy',
    )
    expect(getAppLinkHref('mailto:support@app.example')).toBe(
      'mailto:support@app.example',
    )
  })

  test('drops links that are not web or mail links', () => {
    for (const url of [
      'javascript:alert(document.domain)',
      'JAVASCRIPT:alert(1)',
      'java\nscript:alert(1)',
      'data:text/html,<p>x</p>',
      'vbscript:msgbox(1)',
      'cursor://app/callback',
      'app.example/terms',
      '',
      null,
      undefined,
    ]) {
      expect(getAppLinkHref(url)).toBeUndefined()
    }
  })
})
