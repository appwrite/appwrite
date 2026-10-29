/**
 * Japanese (`ja`) translation quality audit.
 * Scans dictionary values for glossary violations and common awkward calques.
 *
 * Usage: bun run i18n:audit:ja
 *
 * Exit code 1 when issues are found (for CI). Exit 0 when clean.
 */
import { readFileSync, readdirSync } from 'fs'
import { join } from 'path'

const DICT_DIR = join(import.meta.dirname, '../src/lib/i18n/dictionaries/ja')

type AuditRule = {
  id: string
  description: string
  pattern: RegExp
  keyPattern?: RegExp
  keyExclude?: RegExp
}

const RULES: AuditRule[] = [
  {
    id: 'social-login-calque',
    description: "Use 'OAuth' / 'OAuth プロバイダー', never 'ソーシャルログイン'",
    pattern: /ソーシャルログイン|ソーシャルプロバイダー/,
  },
  {
    id: 'bucket-wrong-term',
    description: "Use 'バケット', avoid literal '桶' or 'バケツ'",
    pattern: /(?:^|[^ァ-ヴ])桶|バケツ/,
  },
  {
    id: 'token-wrong-term',
    description: "Use 'トークン', never '代金' or '証票' for API/session tokens",
    pattern: /(?:API|セッション|アクセス).*証票|代金トークン/,
  },
  {
    id: 'logs-wrong-term',
    description: "Use 'ログ', never '日誌' for developer logs",
    pattern: /(?:実行|関数|アクセス|エラー).*日誌|日誌を表示/,
    keyExclude: /blog|changelog/i,
  },
  {
    id: 'credentials-as-login',
    description: "DB/API credentials → 'アクセス資格情報', not 'ログイン情報'",
    pattern: /ログイン情報/,
    keyPattern: /credentials/i,
  },
  {
    id: 'tenant-calque',
    description: "Never translate tenant as 'テナント' in user-facing copy; use 組織/顧客",
    pattern: /テナント|マルチテナント/,
    keyExclude: /Multi-tenancy/i,
  },
  {
    id: 'please-overformal',
    description: "Drop 'ください' filler at start of validation copy when redundant",
    pattern: /^ください/,
  },
  {
    id: 'scale-product-name',
    description: "Plan name 'Scale' should stay Latin when referring to the plan",
    pattern: /スケールプラン|スケール料金/,
    keyPattern: /Scale plan|plan.*Scale/i,
  },
]

function extractEntries(
  filePath: string,
): Array<{ key: string; value: string; line: number }> {
  const content = readFileSync(filePath, 'utf8')
  const entries: Array<{ key: string; value: string; line: number }> = []
  const lines = content.split('\n')

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i]
    const match = line.match(/^\s*(['"`])((?:\\.|(?!\1).)*)\1\s*:\s*(['"`])((?:\\.|(?!\3).)*)\3/)
    if (!match) continue

    const key = match[2]
      .replace(/\\'/g, "'")
      .replace(/\\"/g, '"')
      .replace(/\\n/g, '\n')
      .replace(/\\\\/g, '\\')
    const value = match[4]
      .replace(/\\'/g, "'")
      .replace(/\\"/g, '"')
      .replace(/\\n/g, '\n')
      .replace(/\\\\/g, '\\')

    entries.push({ key, value, line: i + 1 })
  }

  return entries
}

const issues: Array<{
  file: string
  line: number
  key: string
  value: string
  rule: AuditRule
}> = []

for (const file of readdirSync(DICT_DIR).filter((f) => f.endsWith('.ts') && f !== 'index.ts')) {
  const filePath = join(DICT_DIR, file)
  for (const entry of extractEntries(filePath)) {
    for (const rule of RULES) {
      if (rule.keyPattern && !rule.keyPattern.test(entry.key)) continue
      if (rule.keyExclude && rule.keyExclude.test(entry.key)) continue
      if (rule.pattern.test(entry.value)) {
        issues.push({ file, line: entry.line, key: entry.key, value: entry.value, rule })
      }
    }
  }
}

if (issues.length === 0) {
  console.log('i18n audit (ja): no issues found')
  process.exit(0)
}

console.error(`i18n audit (ja): ${issues.length} issue(s)\n`)
for (const issue of issues) {
  console.error(
    `[${issue.rule.id}] ${issue.file}:${issue.line}\n  ${issue.rule.description}\n  key: ${JSON.stringify(issue.key)}\n  value: ${JSON.stringify(issue.value)}\n`,
  )
}
process.exit(1)
