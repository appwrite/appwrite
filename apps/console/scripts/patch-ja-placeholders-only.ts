/**
 * Patch remaining placeholder entries (value === key) in ja dictionary files using
 * translations from scripts/ja-translations/*.ts. Only touches lines that are still
 * placeholders and have a matching translation; every other line is left untouched.
 *
 * Usage: bun run scripts/patch-ja-placeholders-only.ts
 */
import { readFileSync, writeFileSync } from 'fs'
import { join } from 'path'
import { jaSharedUiTranslations } from './ja-translations/shared-ui'
import { jaOrganizationsTranslations } from './ja-translations/organizations'
import { jaMarketingTranslations } from './ja-translations/marketing'

const JA_DIR = join(import.meta.dirname, '../src/lib/i18n/dictionaries/ja')

const ENTRY_RE = /^(\s*)(['"`])((?:\\.|(?!\2).)*)\2(\s*:\s*)(['"`])((?:\\.|(?!\5).)*)\5(\s*,?\s*)$/

function unescape(s: string): string {
  return s.replace(/\\'/g, "'").replace(/\\"/g, '"').replace(/\\\\/g, '\\')
}

function escapeValue(value: string): string {
  if (value.includes("'") && !value.includes('"')) {
    return `"${value.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`
  }
  return `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`
}

function patchFile(filename: string, translations: Record<string, string>) {
  const path = join(JA_DIR, filename)
  const content = readFileSync(path, 'utf8')
  const lines = content.split('\n')
  let patched = 0
  let remaining = 0

  const outLines = lines.map((line) => {
    const m = line.match(ENTRY_RE)
    if (!m) return line
    const [, indent, kq, rawKey, sep, , rawVal, trail] = m
    const key = unescape(rawKey)
    const currentVal = unescape(rawVal)
    if (currentVal !== key) return line // not a placeholder, never touch

    const translated = translations[key]
    if (translated === undefined) {
      remaining++
      return line
    }
    patched++
    return `${indent}${kq}${rawKey}${kq}${sep}${escapeValue(translated)}${trail}`
  })

  writeFileSync(path, outLines.join('\n'))
  console.log(`${filename}: patched ${patched}, still placeholder ${remaining}`)
}

patchFile('shared-ui.ts', jaSharedUiTranslations)
patchFile('organizations.ts', jaOrganizationsTranslations)
patchFile('marketing.ts', jaMarketingTranslations)
