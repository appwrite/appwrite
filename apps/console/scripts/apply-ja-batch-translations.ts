/**
 * Apply batch Japanese translations to ja dictionary files.
 * Usage: bun run scripts/apply-ja-batch-translations.ts
 */
import { readFileSync, writeFileSync } from 'fs'
import { join } from 'path'
import { jaPricingBatch } from './ja-translations/pricing-batch'
import { jaSitesBatch } from './ja-translations/sites-batch'
import { jaProductPagesBatch } from './ja-translations/product-pages-batch'
import { jaFunctionsBatch } from './ja-translations/functions-batch'
import { jaAccountGlobalBatch } from './ja-translations/account-global-batch'

const JA_DIR = join(import.meta.dirname, '../src/lib/i18n/dictionaries/ja')

const BATCHES: Record<string, Record<string, string>> = {
  'pricing.ts': jaPricingBatch,
  'sites.ts': jaSitesBatch,
  'product-pages.ts': jaProductPagesBatch,
  'functions.ts': jaFunctionsBatch,
  'account-global.ts': jaAccountGlobalBatch,
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

function applyBatch(filename: string, translations: Record<string, string>) {
  const path = join(JA_DIR, filename)
  let content = readFileSync(path, 'utf8')
  let applied = 0
  let missing = 0

  for (const [key, value] of Object.entries(translations)) {
    const keyPattern = escapeKey(key)
    const placeholderPattern = new RegExp(
      `(${keyPattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}:\\s*)${keyPattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(,?)`,
    )
    if (placeholderPattern.test(content)) {
      const escaped = escapeValue(value)
      content = content.replace(placeholderPattern, (_match, prefix, suffix) => `${prefix}${escaped}${suffix}`)
      applied++
    } else {
      missing++
      console.warn(`  missing placeholder for key in ${filename}: ${key.slice(0, 60)}...`)
    }
  }

  writeFileSync(path, content)
  console.log(`${filename}: applied ${applied}, not found ${missing}`)
  return applied
}

let total = 0
for (const [file, batch] of Object.entries(BATCHES)) {
  total += applyBatch(file, batch)
}
console.log(`Total applied: ${total}`)
