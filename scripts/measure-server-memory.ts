/**
 * Stress-test the production server and print RSS after each phase.
 * Usage: BUN_PID=<server-pid> bun run scripts/measure-server-memory.ts
 */
const base = process.env.SERVER_URL ?? 'http://127.0.0.1:3001'
const port = new URL(base).port || '3001'
const pid =
  Number(process.env.BUN_PID) ||
  Number(
    Bun.spawnSync([
      'sh',
      '-c',
      `lsof -ti:${port} | while read p; do ps -p "$p" -o comm= 2>/dev/null | grep -qE '^(bun|node)$' && echo "$p" && break; done`,
    ]).stdout
      .toString()
      .trim(),
  ) ||
  Number(
    Bun.spawnSync(['sh', '-c', `lsof -ti:${port} | tail -1`]).stdout
      .toString()
      .trim(),
  )
if (!pid) {
  console.error('Set BUN_PID or start the server matching SERVER_URL')
  process.exit(1)
}

async function hit(path: string) {
  const res = await fetch(base + path)
  const buf = await res.arrayBuffer()
  return { status: res.status, bytes: buf.byteLength }
}

function rssMb(): string {
  const proc = Bun.spawnSync(['ps', '-o', 'rss=', '-p', String(pid)])
  return (Number(proc.stdout.toString().trim()) / 1024).toFixed(1)
}

console.log(`Server PID ${pid}, baseline RSS: ${rssMb()} MB`)

const paths = [
  '/',
  '/home',
  '/init',
  '/docs',
  '/docs/products/databases',
  '/pricing',
  '/llms.txt',
  '/llms-full.txt',
  '/docs/quick-starts/react',
]

for (const p of paths) {
  const r = await hit(p)
  console.log(`${p} -> ${r.status} (${(r.bytes / 1024 / 1024).toFixed(2)} MB)`)
}
console.log(`After warm SSR RSS: ${rssMb()} MB`)

for (let i = 0; i < 50; i++) await hit('/home')
console.log(`After 50x /home RSS: ${rssMb()} MB`)

for (let i = 0; i < 20; i++) await hit('/llms-full.txt')
console.log(`After 20x llms-full RSS: ${rssMb()} MB`)

const mixed = [
  '/docs/products/databases',
  '/home',
  '/init',
  '/pricing',
  '/llms-full.txt',
  '/docs/quick-starts/react',
]
for (let i = 0; i < 200; i++) await hit(mixed[i % mixed.length]!)
console.log(`After 200 mixed RSS: ${rssMb()} MB`)

for (let i = 0; i < 500; i++) await hit('/home')
console.log(`After 500x /home RSS: ${rssMb()} MB`)

for (let i = 0; i < 770; i++) await hit('/home')
console.log(`After batch 2 (770x /home) RSS: ${rssMb()} MB`)

for (let i = 0; i < 200; i++) await hit('/init')
console.log(`After 200x /init RSS: ${rssMb()} MB`)

await new Promise((r) => setTimeout(r, 10000))
console.log(`After 10s idle RSS: ${rssMb()} MB`)
