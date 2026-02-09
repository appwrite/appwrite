/**
 * Parses eslint output and fixes no-unused-vars by prefixing with _
 * Run: bun run lint 2>&1 | node scripts/fix-unused-vars.cjs
 */
const fs = require('fs')
const path = require('path')

const input = fs.readFileSync(0, 'utf-8')
const cwd = process.cwd()

const errors = []
let currentFile = null

for (const line of input.split('\n')) {
  if (line.startsWith('/') && line.includes('vibes/')) {
    currentFile = line.trim()
    continue
  }
  if (!currentFile) continue
  const match = line.match(
    /^\s*(\d+):\d+\s+error\s+'([^']+)' is (defined but never used|assigned a value but never used)/,
  )
  if (match) {
    const [, lineNum, varName] = match
    errors.push({ file: currentFile, line: parseInt(lineNum, 10), varName })
  }
}

const byFile = {}
for (const e of errors) {
  if (!byFile[e.file]) byFile[e.file] = []
  byFile[e.file].push(e)
}

function escapeRegex(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

for (const [filePath, fileErrors] of Object.entries(byFile)) {
  const relPath = filePath.replace(cwd + '/', '').replace(cwd + path.sep, '')
  if (!fs.existsSync(relPath)) continue
  const lines = fs.readFileSync(relPath, 'utf-8').split('\n')

  for (const { line: lineNum, varName } of fileErrors) {
    const idx = lineNum - 1
    if (idx < 0 || idx >= lines.length) continue
    let line = lines[idx]
    const catchRe = new RegExp(
      `\\bcatch\\s*\\(\\s*${escapeRegex(varName)}\\s*\\)`,
      'g',
    )
    if (catchRe.test(line)) {
      lines[idx] = line.replace(catchRe, `catch (_${varName})`)
    } else {
      const re = new RegExp(`\\b${escapeRegex(varName)}\\b`, 'g')
      lines[idx] = line.replace(re, `_${varName}`)
    }
  }

  fs.writeFileSync(relPath, lines.join('\n'))
}

console.error(
  `Fixed ${errors.length} unused variable(s) in ${Object.keys(byFile).length} files`,
)
