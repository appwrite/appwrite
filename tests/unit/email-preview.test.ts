import { describe, expect, test } from 'bun:test'
import {
  emailPreviewDocument,
  emailTemplatePreviewDocument,
} from '@/lib/email-preview'

describe('emailPreviewDocument', () => {
  test('renders an HTML body as sent', () => {
    const body = '<p style="color:red">Hello <b>there</b></p>'
    expect(emailPreviewDocument(body, true)).toBe(body)
  })

  test('escapes a plain-text body so tags show verbatim', () => {
    const doc = emailPreviewDocument('Use <b> & </b> for bold', false)
    expect(doc).toContain('Use &lt;b&gt; &amp; &lt;/b&gt; for bold')
    expect(doc).not.toContain('<b>')
  })
})

describe('emailTemplatePreviewDocument', () => {
  test('renders the bold tokens the mail worker substitutes', () => {
    const doc = emailTemplatePreviewDocument('Hi {{b}}{{project}}{{/b}} team')
    expect(doc).toContain('Hi <strong>{{project}}</strong> team')
  })
})
