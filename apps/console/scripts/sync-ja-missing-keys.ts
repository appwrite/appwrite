/**
 * Add missing dictionary keys from he to ja, including unquoted identifier keys.
 * Uses runtime imports for accurate key sets. New entries use English key as placeholder.
 *
 * Usage: bun run scripts/sync-ja-missing-keys.ts
 */
import { writeFileSync, readFileSync } from 'fs'
import { join } from 'path'

const JA_DIR = join(import.meta.dirname, '../src/lib/i18n/dictionaries/ja')

const FILE_EXPORTS: Record<string, { he: string; ja: string }> = {
  'account-global.ts': { he: 'heAccountGlobalDictionary', ja: 'jaAccountGlobalDictionary' },
  'auth-storage.ts': { he: 'heAuthStorageDictionary', ja: 'jaAuthStorageDictionary' },
  'databases.ts': { he: 'heDatabasesDictionary', ja: 'jaDatabasesDictionary' },
  'functions.ts': { he: 'heFunctionsDictionary', ja: 'jaFunctionsDictionary' },
  'marketing.ts': { he: 'heMarketingDictionary', ja: 'jaMarketingDictionary' },
  'organizations.ts': { he: 'heOrganizationsDictionary', ja: 'jaOrganizationsDictionary' },
  'pricing.ts': { he: 'hePricingDictionary', ja: 'jaPricingDictionary' },
  'product-pages.ts': { he: 'heProductPagesDictionary', ja: 'jaProductPagesDictionary' },
  'project-misc.ts': { he: 'heProjectMiscDictionary', ja: 'jaProjectMiscDictionary' },
  'shared-ui.ts': { he: 'heSharedUiDictionary', ja: 'jaSharedUiDictionary' },
  'sites.ts': { he: 'heSitesDictionary', ja: 'jaSitesDictionary' },
}

function escapeKey(key: string): string {
  if (/^[A-Za-z_][A-Za-z0-9_]*$/.test(key)) return key
  if (key.includes("'") && !key.includes('"')) {
    return `"${key.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`
  }
  return `'${key.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`
}

function escapeValue(value: string): string {
  return escapeKey(value)
}

function formatValue(value: string): string {
  // Always quote values so reserved words (for, or, of, etc.) stay valid.
  if (value.includes("'") && !value.includes('"')) {
    return `"${value.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`
  }
  return `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`
}

async function main() {
  const heModules = {
    'account-global.ts': await import('../src/lib/i18n/dictionaries/he/account-global.ts'),
    'auth-storage.ts': await import('../src/lib/i18n/dictionaries/he/auth-storage.ts'),
    'databases.ts': await import('../src/lib/i18n/dictionaries/he/databases.ts'),
    'functions.ts': await import('../src/lib/i18n/dictionaries/he/functions.ts'),
    'marketing.ts': await import('../src/lib/i18n/dictionaries/he/marketing.ts'),
    'organizations.ts': await import('../src/lib/i18n/dictionaries/he/organizations.ts'),
    'pricing.ts': await import('../src/lib/i18n/dictionaries/he/pricing.ts'),
    'product-pages.ts': await import('../src/lib/i18n/dictionaries/he/product-pages.ts'),
    'project-misc.ts': await import('../src/lib/i18n/dictionaries/he/project-misc.ts'),
    'shared-ui.ts': await import('../src/lib/i18n/dictionaries/he/shared-ui.ts'),
    'sites.ts': await import('../src/lib/i18n/dictionaries/he/sites.ts'),
  }

  const jaModules = {
    'account-global.ts': await import('../src/lib/i18n/dictionaries/ja/account-global.ts'),
    'auth-storage.ts': await import('../src/lib/i18n/dictionaries/ja/auth-storage.ts'),
    'databases.ts': await import('../src/lib/i18n/dictionaries/ja/databases.ts'),
    'functions.ts': await import('../src/lib/i18n/dictionaries/ja/functions.ts'),
    'marketing.ts': await import('../src/lib/i18n/dictionaries/ja/marketing.ts'),
    'organizations.ts': await import('../src/lib/i18n/dictionaries/ja/organizations.ts'),
    'pricing.ts': await import('../src/lib/i18n/dictionaries/ja/pricing.ts'),
    'product-pages.ts': await import('../src/lib/i18n/dictionaries/ja/product-pages.ts'),
    'project-misc.ts': await import('../src/lib/i18n/dictionaries/ja/project-misc.ts'),
    'shared-ui.ts': await import('../src/lib/i18n/dictionaries/ja/shared-ui.ts'),
    'sites.ts': await import('../src/lib/i18n/dictionaries/ja/sites.ts'),
  }

  let totalAdded = 0

  for (const [file, exports] of Object.entries(FILE_EXPORTS)) {
    const heDict = heModules[file as keyof typeof heModules][exports.he] as Record<
      string,
      string
    >
    const jaDict = jaModules[file as keyof typeof jaModules][exports.ja] as Record<
      string,
      string
    >
    const missing = Object.keys(heDict).filter((k) => !(k in jaDict))
    if (missing.length === 0) continue

    const jaPath = join(JA_DIR, file)
    let jaContent = readFileSync(jaPath, 'utf8')
    const insertLines = missing.map(
      (key) => `  ${escapeKey(key)}: ${formatValue(key)},`,
    )
    jaContent = jaContent.replace(/\n}\s*$/, `\n${insertLines.join('\n')}\n}\n`)
    writeFileSync(jaPath, jaContent)
    totalAdded += missing.length
    console.log(`${file}: added ${missing.length} keys`)
  }

  console.log(`Total added: ${totalAdded}`)
}

main()
