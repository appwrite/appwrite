/**
 * Replaces explicit 'any' with 'unknown' to fix @typescript-eslint/no-explicit-any.
 * Run from repo root: node scripts/fix-explicit-any.cjs
 */
const fs = require('fs')
const path = require('path')

const srcDir = path.join(__dirname, '..', 'src')
const ignores = new Set(['routeTree.gen.ts', 'components/ui'])

function walk(dir, files = []) {
  const entries = fs.readdirSync(dir, { withFileTypes: true })
  for (const e of entries) {
    const full = path.join(dir, e.name)
    if (e.isDirectory()) {
      if (e.name === 'node_modules' || e.name === 'ui') continue
      walk(full, files)
    } else if (e.name.endsWith('.ts') || e.name.endsWith('.tsx')) {
      const rel = path.relative(__dirname + '/..', full)
      if (rel.includes('routeTree.gen.ts') || rel.includes('components/ui/'))
        continue
      files.push(full)
    }
  }
  return files
}

const files = walk(srcDir)
let total = 0

for (const file of files) {
  let content = fs.readFileSync(file, 'utf-8')
  const orig = content
  // Type annotations and casts - use word boundary so we don't replace "company" etc.
  content = content.replace(/: any\b/g, ': unknown')
  content = content.replace(/\bas any\b/g, 'as unknown')
  content = content.replace(/<any>/g, '<unknown>')
  content = content.replace(/\bany\[\]/g, 'unknown[]')
  content = content.replace(/\(any\)/g, '(unknown)')
  content = content.replace(/Record<string,\s*any>/g, 'Record<string, unknown>')
  content = content.replace(/,\s*any\s*>/g, ', unknown>')
  content = content.replace(/<\s*any\s*,/g, '<unknown,')
  content = content.replace(/\[\s*any\s*\]/g, '[unknown]')
  if (content !== orig) {
    fs.writeFileSync(file, content)
    const n = (orig.match(/: any\b|\bas any\b|<any>|\bany\[\]|\(any\)/g) || [])
      .length
    total += n
  }
}

console.error(
  `Replaced 'any' with 'unknown' in ${files.length} files (${total} occurrences)`,
)
