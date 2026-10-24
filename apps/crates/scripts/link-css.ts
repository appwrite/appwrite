import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

const client = join(import.meta.dir, '../dist/client')
const css = readdirSync(join(client, 'assets')).find((file) => file.endsWith('.css'))
if (!css) throw new Error('No CSS file in dist/client/assets')

const href = `/assets/${css}`

function walk(dir: string) {
  for (const name of readdirSync(dir)) {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) {
      walk(path)
      continue
    }
    if (!name.endsWith('.html')) continue
    const html = readFileSync(path, 'utf8')
    const next = html.replace(/\/assets\/styles-[^"]+\.css/g, href)
    if (next !== html) writeFileSync(path, next)
  }
}

walk(client)
console.log(`Linked stylesheets to ${href}`)
