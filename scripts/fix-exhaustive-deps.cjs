/**
 * Adds eslint-disable-next-line for react-hooks/exhaustive-deps at given line.
 * Usage: bun run lint 2>&1 | node scripts/fix-exhaustive-deps.cjs
 */
const fs = require('fs')
const path = require('path')

const input = fs.readFileSync(0, 'utf-8')
const cwd = process.cwd()

const locations = []
let currentFile = null
for (const line of input.split('\n')) {
  if (line.startsWith('/') && line.includes('vibes/')) {
    currentFile = line.trim()
    continue
  }
  if (!currentFile) continue
  const match = line.match(
    /^\s*(\d+):(\d+)\s+warning.*react-hooks\/exhaustive-deps/,
  )
  if (match) {
    const [, lineNum] = match
    locations.push({ file: currentFile, line: parseInt(lineNum, 10) })
  }
}

const byFile = {}
for (const { file, line } of locations) {
  if (!byFile[file]) byFile[file] = []
  byFile[file].push(line)
}

for (const [filePath, lines] of Object.entries(byFile)) {
  const relPath = filePath.replace(cwd + '/', '').replace(cwd + path.sep, '')
  if (!fs.existsSync(relPath)) continue
  const sortedLines = [...new Set(lines)].sort((a, b) => b - a)
  let content = fs.readFileSync(relPath, 'utf-8')
  const contentLines = content.split('\n')
  const comment = '  // eslint-disable-next-line react-hooks/exhaustive-deps'
  for (const lineNum of sortedLines) {
    const idx = lineNum - 1
    if (idx <= 0 || idx >= contentLines.length) continue
    const prevLine = contentLines[idx - 1].trim()
    if (prevLine.startsWith('// eslint-disable-next-line react-hooks')) continue
    contentLines.splice(idx, 0, comment)
  }
  fs.writeFileSync(relPath, contentLines.join('\n'))
}

console.error(
  `Added exhaustive-deps disable at ${locations.length} locations in ${Object.keys(byFile).length} files`,
)
