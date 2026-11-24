/**
 * The cards pages show when shared (Open Graph): one per library and one per
 * category, from the generated data/index.json, into public/og/<slug>.png and
 * public/og/categories/<id>.png. Laid out with satori, rasterised with resvg, in
 * the site's fonts (scripts/og/fonts) and colours. `bun run build` runs it before
 * prerendering; the site-wide cover (public/og.png) is drawn by hand.
 */
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { Resvg } from '@resvg/resvg-js'
import type { ReactNode } from 'react'
import satori from 'satori'
import { TILE, TRACES } from '../src/components/Circuit'

const root = (path: string) => new URL(`../${path}`, import.meta.url)

interface Library {
  slug: string
  title: string
  category: string
  description: string
  rust: { crate: string } | null
  php: { package: string } | null
  counts: { items: number; examples: number }
  size: { rust?: { total: number } } | null
}
interface Category {
  id: string
  title: string
  description: string
  libraries: string[]
}

if (!existsSync(root('data/index.json'))) {
  console.log('og: no data/index.json yet (run bin/compat crates); no cards rendered')
  process.exit(0)
}
const index = JSON.parse(readFileSync(root('data/index.json'), 'utf8')) as { libraries: Library[]; categories: Category[] }

const font = (file: string) => readFileSync(root(`scripts/og/fonts/${file}`))
const fonts = [
  { name: 'Archivo', data: font('Archivo-SemiBold-112.ttf'), weight: 600 as const, style: 'normal' as const },
  { name: 'Geist', data: font('Geist-Regular.ttf'), weight: 400 as const, style: 'normal' as const },
  { name: 'Geist Mono', data: font('GeistMono-Medium.ttf'), weight: 500 as const, style: 'normal' as const },
]

const svg = (markup: string) => `data:image/svg+xml;base64,${Buffer.from(markup).toString('base64')}`

/** The logo: the crate beside the wordmark, as in the design system (utopia-lockup-horizontal.svg). */
const LOCKUP = svg(readFileSync(root('scripts/og/lockup.svg'), 'utf8'))

/** The hero's circuitry, fading in from the left so the right side carries it; drawn once, as every card shares it. */
const circuit =
  `<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630"><defs>` +
    `<pattern id="t" width="${TILE}" height="${TILE}" patternUnits="userSpaceOnUse" x="20" y="-40" fill="none" stroke="#46433A">` +
    TRACES.map((t) => `<path d="${t.d}"/>${t.via ? `<circle cx="${t.via[0]}" cy="${t.via[1]}" r="3.5"/>` : ''}`).join('') +
    `</pattern><linearGradient id="f"><stop offset="0.5" stop-color="#000"/><stop offset="1" stop-color="#fff"/></linearGradient>` +
    `<mask id="m"><rect width="1200" height="630" fill="url(#f)"/></mask></defs>` +
    `<rect width="1200" height="630" fill="url(#t)" mask="url(#m)" opacity="0.7"/></svg>`
const CIRCUIT = `data:image/png;base64,${Buffer.from(new Resvg(circuit, { font: { loadSystemFonts: false } }).render().asPng()).toString('base64')}`

/** Lines of Rust as Size.tsx shows them: 5.9k, 12k. */
function shortLines(n: number) {
  return n >= 10_000 ? `${Math.round(n / 1000)}k` : n >= 1000 ? `${(n / 1000).toFixed(1)}k` : String(n)
}

/** Cut at a word so the description fits two lines. */
function clip(text: string, max = 118) {
  if (text.length <= max) return text
  const cut = text.slice(0, max)
  let words = cut.slice(0, cut.lastIndexOf(' '))
  while (words && ',;:.'.includes(words[words.length - 1])) words = words.slice(0, -1)
  return `${words}…`
}

const MONO = { fontFamily: 'Geist Mono', fontSize: 20, letterSpacing: 2, color: '#8A857A', textTransform: 'uppercase' as const }

function Card({ eyebrow, title, mono, description, meta }: { eyebrow: string; title: string; mono?: string; description: string; meta: string }) {
  const size = title.length > 18 ? 72 : title.length > 11 ? 88 : 108
  return (
    <div style={{ position: 'relative', display: 'flex', flexDirection: 'column', width: 1200, height: 630, boxSizing: 'border-box', padding: '56px 80px', background: '#11100D', color: '#ECE8DE', fontFamily: 'Geist' }}>
      <img src={CIRCUIT} width={1200} height={630} style={{ position: 'absolute', left: 0, top: 0 }} />
      <img src={LOCKUP} width={228} height={48} />
      <div style={{ flexGrow: 1 }} />
      <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 14, ...MONO }}>
          <div style={{ width: 10, height: 10, background: '#FF3D5F' }} />
          {eyebrow}
        </div>
        <div style={{ fontFamily: 'Archivo', fontWeight: 600, fontSize: size, lineHeight: 1, letterSpacing: -0.03 * size }}>{title}</div>
        {mono ? <div style={{ fontFamily: 'Geist Mono', fontSize: 26, color: '#8A857A' }}>{mono}</div> : null}
        <div style={{ maxWidth: 940, fontSize: 28, lineHeight: 1.4, color: '#BDB8AB' }}>{clip(description)}</div>
      </div>
      <div style={{ display: 'flex', flexShrink: 0, justifyContent: 'space-between', marginTop: 32, paddingTop: 20, borderTop: '1px solid #2A2822', ...MONO }}>
        <span>{meta}</span>
        <span>By Appwrite</span>
      </div>
    </div>
  )
}

async function render(path: string, card: ReactNode) {
  const markup = await satori(card, { width: 1200, height: 630, fonts })
  writeFileSync(root(path), new Resvg(markup, { fitTo: { mode: 'width', value: 1200 }, font: { loadSystemFonts: false } }).render().asPng())
}

mkdirSync(root('public/og/categories'), { recursive: true })
const categories = new Map(index.categories.map((c) => [c.id, c]))
for (const lib of index.libraries) {
  const lines = lib.size?.rust?.total
  await render(
    `public/og/${lib.slug}.png`,
    <Card
      eyebrow={categories.get(lib.category)?.title ?? 'Library'}
      title={lib.title}
      mono={lib.rust?.crate ?? lib.php?.package}
      description={lib.description}
      meta={lib.rust ? [`${lib.counts.items} items`, `${lib.counts.examples} examples`, lines ? `${shortLines(lines)} lines` : ''].filter(Boolean).join(' · ') : 'PHP · not in Rust yet'}
    />,
  )
}
const rust = new Set(index.libraries.filter((l) => l.rust).map((l) => l.slug))
for (const c of index.categories) {
  const crates = c.libraries.filter((s) => rust.has(s)).length
  await render(`public/og/categories/${c.id}.png`, <Card eyebrow="Category" title={c.title} description={c.description} meta={`${crates} ${crates === 1 ? 'crate' : 'crates'}`} />)
}
console.log(`og: ${index.libraries.length} library and ${index.categories.length} category cards in public/og`)
