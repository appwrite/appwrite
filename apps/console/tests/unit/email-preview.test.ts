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
  test('does not show the bold tokens the mail worker consumes', () => {
    const doc = emailTemplatePreviewDocument('Hi {{b}}{{project}}{{/b}} team')
    expect(doc).not.toMatch(/\{\{\/?b\}\}/)
    expect(doc).toContain('{{project}}')
  })
})
