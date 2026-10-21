/**
 * Hebrew (`he`) translation quality audit.
 * Scans dictionary values for glossary violations and common awkward calques.
 *
 * Each supported language should have its own script: scripts/i18n-audit-<lang>.ts
 * Usage: bun run i18n:audit:he
 *
 * Exit code 1 when issues are found (for CI). Exit 0 when clean.
 */
import { readFileSync, readdirSync } from 'fs'
import { join } from 'path'

const DICT_DIR = join(import.meta.dirname, '../src/lib/i18n/dictionaries/he')

type AuditRule = {
  id: string
  description: string
  /** Match Hebrew translation values */
  pattern: RegExp
  /** Optional: only flag when English key matches */
  keyPattern?: RegExp
  /** Optional: skip when English key matches (false positives) */
  keyExclude?: RegExp
}

const RULES: AuditRule[] = [
  {
    id: 'logs-yomanim',
    description: "Use 'לוגים', never 'יומנים' (Logs glossary)",
    pattern: /יומנים/,
    keyExclude: /blog/i,
  },
  {
    id: 'token-asimon',
    description: "Use 'טוקן/טוקנים', never 'אסימון/אסימונים' (Token glossary)",
    pattern: /אסימון|אסימונים/,
  },
  {
    id: 'bucket-dli',
    description: "Use 'באקט', never 'דלי' (Bucket glossary)",
    pattern: /\bדלי\b/,
  },
  {
    id: 'please-ana',
    description: "Drop 'אנא' from product copy (voice/register)",
    pattern: /\bאנא\b/,
  },
  {
    id: 'scale-skiil',
    description: "Use 'צמיחה'/'קנה מידה', never 'סקייל' (Scale glossary)",
    pattern: /סקייל/,
  },
  {
    id: 'social-login-calque',
    description: "Use 'OAuth' / 'ספקי OAuth', never 'התחברות חברתית'",
    pattern: /התחברות חברתית|ספקי התחברות חברתית|ספקים חברתיים/,
  },
  {
    id: 'tenant-calque',
    description: "Never translate tenant as 'דייר'. Use 'לקוח', 'ארגון', or keep Multi-tenancy",
    pattern: /דייר|דיירים|ריבוי דיירים/,
    keyExclude: /social media/i,
  },
  {
    id: 'credentials-as-login',
    description: "DB/API 'Credentials' → 'פרטי גישה', not 'פרטי התחברות'",
    pattern: /פרטי התחברות/,
    keyPattern: /credentials/i,
  },
  {
    id: 'at-rest-literal',
    description: "Use 'הצפנה במצב מנוחה', not bare 'הצפנה במנוחה'",
    pattern: /הצפנה במנוחה/,
  },
  {
    id: 'mfa-sign-in-method',
    description: "MFA copy should say 'אימות דו-שלבי', not 'שיטת התחברות שנייה'",
    pattern: /שיטת התחברות שנייה/,
  },
  {
    id: 'execution-haflaot',
    description: "Function executions → 'הרצות', not 'הפעלות' (Session glossary)",
    pattern: /הפעלות/,
    keyPattern: /execution|invocation/i,
  },
]

function extractEntries(filePath: string): Array<{ key: string; value: string }> {
  const content = readFileSync(filePath, 'utf8')
  const entries: Array<{ key: string; value: string }> = []

  // Match 'key': 'value' and 'key':\n    'value' (multiline values)
  const singleLine = /'((?:[^'\\]|\\.)*)':\s*'((?:[^'\\]|\\.)*)'/g
  let match: RegExpExecArray | null
  while ((match = singleLine.exec(content))) {
    entries.push({
      key: match[1].replace(/\\'/g, "'"),
      value: match[2].replace(/\\'/g, "'"),
    })
  }

  const multiLine = /'((?:[^'\\]|\\.)*)':\s*\n\s*'((?:[^'\\]|\\.)*)'/g
  while ((match = multiLine.exec(content))) {
    const key = match[1].replace(/\\'/g, "'")
    const value = match[2].replace(/\\'/g, "'")
    if (!entries.some((e) => e.key === key && e.value === value)) {
      entries.push({ key, value })
    }
  }

  return entries
}

type Issue = {
  file: string
  rule: AuditRule
  key: string
  value: string
}

const issues: Issue[] = []

for (const file of readdirSync(DICT_DIR).filter((f) => f.endsWith('.ts') && f !== 'index.ts')) {
  const filePath = join(DICT_DIR, file)
  for (const { key, value } of extractEntries(filePath)) {
    for (const rule of RULES) {
      if (rule.keyPattern && !rule.keyPattern.test(key)) continue
      if (rule.keyExclude && rule.keyExclude.test(key)) continue
      if (rule.pattern.test(value)) {
        issues.push({ file, rule, key, value })
      }
    }
  }
}

if (issues.length === 0) {
  console.log('Hebrew i18n audit: no issues found.')
  process.exit(0)
}

console.log(`Hebrew i18n audit: ${issues.length} issue(s)\n`)

const byRule = new Map<string, Issue[]>()
for (const issue of issues) {
  const list = byRule.get(issue.rule.id) ?? []
  list.push(issue)
  byRule.set(issue.rule.id, list)
}

for (const [ruleId, ruleIssues] of byRule) {
  const rule = ruleIssues[0].rule
  console.log(`[${ruleId}] ${rule.description} (${ruleIssues.length})`)
  for (const issue of ruleIssues.slice(0, 5)) {
    const preview =
      issue.value.length > 80 ? `${issue.value.slice(0, 77)}...` : issue.value
    console.log(`  ${issue.file}: "${issue.key}"`)
    console.log(`    → ${preview}`)
  }
  if (ruleIssues.length > 5) {
    console.log(`  … and ${ruleIssues.length - 5} more`)
  }
  console.log()
}

process.exit(1)
