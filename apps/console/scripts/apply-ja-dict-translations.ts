/**
 * Apply Japanese translations to ja dictionary files.
 * Usage: bun run scripts/apply-ja-dict-translations.ts
 */
import { readFileSync, writeFileSync } from 'fs'
import { join } from 'path'
import { jaDatabasesTranslations } from './ja-translations/databases'
import { jaProjectMiscTranslations } from './ja-translations/project-misc'

const JA_DIR = join(import.meta.dirname, '../src/lib/i18n/dictionaries/ja')

type Entry = { key: string; comment?: string; pragma?: string }

function parseDictionaryFile(content: string): Entry[] {
  const entries: Entry[] = []
  let pendingComment: string | undefined

  for (const line of content.split('\n')) {
    const commentMatch = line.match(/^\s*\/\/(.*)$/)
    if (commentMatch) {
      pendingComment = line.trim()
      continue
    }

    const entryMatch = line.match(/^\s*(['"`])((?:\\.|(?!\1).)*)\1\s*:\s*['"`]/)
    if (entryMatch) {
      const key = entryMatch[2]
        .replace(/\\'/g, "'")
        .replace(/\\"/g, '"')
        .replace(/\\n/g, '\n')
        .replace(/\\\\/g, '\\')
      entries.push({ key, comment: pendingComment })
      pendingComment = undefined
      continue
    }

    if (line.trim() && !line.trim().startsWith('//')) {
      pendingComment = undefined
    }
  }

  return entries
}

function escapeKey(key: string): string {
  if (key.includes("'") && !key.includes('"')) {
    return `"${key.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`
  }
  return `'${key.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`
}

function escapeValue(value: string): string {
  if (value.includes("'") && !value.includes('"')) {
    return `"${value.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`
  }
  return `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`
}

function generateFile(
  domain: string,
  exportName: string,
  entries: Entry[],
  translations: Record<string, string>,
): string {
  const lines = [
    '/**',
    ` * Japanese translations for ${domain}.`,
    ' * Keys are the exact English source strings (English is the source of truth).',
    ' */',
    `export const ${exportName}: Record<string, string> = {`,
  ]

  let missing = 0
  for (const { key, comment } of entries) {
    if (comment) lines.push(`  ${comment}`)
    const value = translations[key]
    if (!value) {
      missing++
      lines.push(`  ${escapeKey(key)}: ${escapeKey(key)},`)
      continue
    }
    lines.push(`  ${escapeKey(key)}: ${escapeValue(value)},`)
  }

  lines.push('}', '')
  if (missing > 0) console.warn(`  missing ${missing} translations`)
  return lines.join('\n')
}

function apply(filename: string, exportName: string, domain: string, translations: Record<string, string>) {
  const path = join(JA_DIR, filename)
  const content = readFileSync(path, 'utf8')
  const entries = parseDictionaryFile(content)
  const output = generateFile(domain, exportName, entries, translations)
  writeFileSync(path, output)
  console.log(`${filename}: ${entries.length} keys, ${Object.keys(translations).length} translations`)
}

apply('databases.ts', 'jaDatabasesDictionary', 'databases', jaDatabasesTranslations)
apply('project-misc.ts', 'jaProjectMiscDictionary', 'project misc', jaProjectMiscTranslations)
// For partial translation batches use scripts/patch-ja-placeholders-only.ts instead.
// This script regenerates entire files and requires a complete translations map.
