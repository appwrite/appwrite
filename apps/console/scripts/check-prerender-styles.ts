import { join } from 'node:path'

// Check emitted HTML, not hydrated DOM: JavaScript can hide missing SSR styles.
const clientDir = join(import.meta.dir, '..', 'dist', 'client')
let checked = 0
for await (const path of new Bun.Glob('**/*.html').scan(clientDir)) {
  const html = await Bun.file(join(clientDir, path)).text()
  const links = [...html.matchAll(/<link\b[^>]*>/g)]
    .map(([tag]) => tag)
    .filter((tag) => /\brel="stylesheet"/.test(tag))
  if (!links.length) {
    throw new Error(`${path}: initial HTML has no stylesheet link`)
  }
  for (const tag of links) {
    const href = tag.match(/\bhref="([^"]+)"/)?.[1]
    if (!href) throw new Error(`${path}: stylesheet has no href`)
    const pathname = new URL(href, `https://local.invalid/${path}`).pathname
    const css = Bun.file(join(clientDir, decodeURIComponent(pathname)))
    if (!(await css.exists()) || css.size === 0) {
      throw new Error(
        `${path}: stylesheet ${href} is absent from client output`,
      )
    }
  }
  checked++
}
if (!checked) throw new Error('No prerendered HTML found to check')
console.log(`Verified initial stylesheet links in ${checked} prerendered pages`)
