/**
 * Bootstrap Japanese dictionary files from Hebrew dictionary structure.
 * Copies English keys from he/*.ts files; values are translated separately.
 *
 * Usage: bun run scripts/bootstrap-ja-from-he.ts
 */
import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'fs'
import { join } from 'path'

const HE_DIR = join(import.meta.dirname, '../src/lib/i18n/dictionaries/he')
const JA_DIR = join(import.meta.dirname, '../src/lib/i18n/dictionaries/ja')

const FILE_MAP: Record<string, string> = {
  'account-global.ts': 'jaAccountGlobalDictionary',
  'auth-storage.ts': 'jaAuthStorageDictionary',
  'databases.ts': 'jaDatabasesDictionary',
  'functions.ts': 'jaFunctionsDictionary',
  'marketing.ts': 'jaMarketingDictionary',
  'organizations.ts': 'jaOrganizationsDictionary',
  'pricing.ts': 'jaPricingDictionary',
  'product-pages.ts': 'jaProductPagesDictionary',
  'project-misc.ts': 'jaProjectMiscDictionary',
  'shared-ui.ts': 'jaSharedUiDictionary',
  'sites.ts': 'jaSitesDictionary',
}

function parseDictionaryEntries(content: string): Array<{ key: string; comment?: string }> {
  const entries: Array<{ key: string; comment?: string }> = []
  const lines = content.split('\n')
  let pendingComment: string | undefined

  for (const line of lines) {
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

function generateJaFile(
  heFilename: string,
  exportName: string,
  entries: Array<{ key: string; comment?: string }>,
): string {
  const headerComment = heFilename.replace('.ts', '')
  const lines = [
    '/**',
    ` * Japanese translations for ${headerComment.replace(/-/g, ' ')}.`,
    ' * Keys are the exact English source strings (English is the source of truth).',
    ' */',
    `export const ${exportName}: Record<string, string> = {`,
  ]

  for (const { key, comment } of entries) {
    if (comment) lines.push(`  ${comment}`)
    lines.push(`  ${escapeKey(key)}: ${escapeKey(key)},`)
  }

  lines.push('}', '')
  return lines.join('\n')
}

mkdirSync(JA_DIR, { recursive: true })

for (const [filename, exportName] of Object.entries(FILE_MAP)) {
  const hePath = join(HE_DIR, filename)
  const jaPath = join(JA_DIR, filename)
  if (existsSync(jaPath)) {
    console.log(`skip ${filename} (already exists)`)
    continue
  }

  const heContent = readFileSync(hePath, 'utf8')
  const entries = parseDictionaryEntries(heContent)
  const jaContent = generateJaFile(filename, exportName, entries)
  writeFileSync(jaPath, jaContent)
  console.log(`created ${filename} (${entries.length} keys)`)
}

const indexPath = join(JA_DIR, 'index.ts')
if (!existsSync(indexPath)) {
  writeFileSync(
    indexPath,
    `import { jaDatabasesDictionary } from './databases'
import { jaSitesDictionary } from './sites'
import { jaFunctionsDictionary } from './functions'
import { jaAuthStorageDictionary } from './auth-storage'
import { jaProjectMiscDictionary } from './project-misc'
import { jaOrganizationsDictionary } from './organizations'
import { jaAccountGlobalDictionary } from './account-global'
import { jaSharedUiDictionary } from './shared-ui'
import { jaMarketingDictionary } from './marketing'
import { jaProductPagesDictionary } from './product-pages'
import { jaPricingDictionary } from './pricing'

/**
 * Merged Japanese dictionary keyed by English source strings.
 * Later entries override earlier ones on key collisions.
 */
export const jaDictionary: Record<string, string> = {
  ...jaMarketingDictionary,
  ...jaProductPagesDictionary,
  ...jaPricingDictionary,
  ...jaSharedUiDictionary,
  ...jaAccountGlobalDictionary,
  ...jaOrganizationsDictionary,
  ...jaProjectMiscDictionary,
  ...jaAuthStorageDictionary,
  ...jaFunctionsDictionary,
  ...jaSitesDictionary,
  ...jaDatabasesDictionary,
}
`,
  )
  console.log('created index.ts')
}
