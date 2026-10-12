/**
 * Hammers /init and samples the server process RSS between checkpoints.
 *
 * Usage (production server must already be listening):
 *   bun run build && bun run start &
 *   bun run scripts/benchmark-init-server-memory.ts
 *
 * Options:
 *   --url=http://localhost:3000
 *   --requests=200
 *   --og            also fetch /og/init.png each request
 *   --pid=12345     server PID (auto-detected from port when omitted)
 */
import { spawnSync } from 'node:child_process'

const DEFAULT_URL = 'http://localhost:3000'
const DEFAULT_REQUESTS = 200
const WARMUP_REQUESTS = 10

type MemorySample = {
  label: string
  rssMb: number
}

function parseArgs() {
  const url =
    process.argv.find((arg) => arg.startsWith('--url='))?.slice(6) ??
    DEFAULT_URL
  const requests = Number(
    process.argv.find((arg) => arg.startsWith('--requests='))?.slice(11) ??
      DEFAULT_REQUESTS,
  )
  const pidArg = process.argv.find((arg) => arg.startsWith('--pid='))?.slice(6)
  const includeOg = process.argv.includes('--og')
  const parsed = new URL(url)
  return {
    baseUrl: url.replace(/\/$/, ''),
    port: parsed.port || (parsed.protocol === 'https:' ? '443' : '80'),
    requests: Number.isFinite(requests) ? requests : DEFAULT_REQUESTS,
    pid: pidArg ? Number(pidArg) : null,
    includeOg,
  }
}

function readProcessRssMb(pid: number): number | null {
  const result = spawnSync('ps', ['-o', 'rss=', '-p', String(pid)], {
    encoding: 'utf8',
  })
  const kb = Number(result.stdout.trim())
  return Number.isFinite(kb) ? kb / 1024 : null
}

function detectServerPid(port: string): number | null {
  if (process.platform === 'darwin') {
    const result = spawnSync('lsof', ['-ti', `tcp:${port}`, '-sTCP:LISTEN'], {
      encoding: 'utf8',
    })
    const pid = Number(result.stdout.trim().split('\n')[0])
    return Number.isFinite(pid) ? pid : null
  }

  const result = spawnSync('lsof', ['-ti', `:${port}`, '-sTCP:LISTEN'], {
    encoding: 'utf8',
  })
  const pid = Number(result.stdout.trim().split('\n')[0])
  return Number.isFinite(pid) ? pid : null
}

async function fetchPath(baseUrl: string, path: string): Promise<void> {
  const response = await fetch(`${baseUrl}${path}`, {
    headers: { Accept: 'text/html,application/xhtml+xml,*/*' },
  })
  await response.arrayBuffer()
}

async function main() {
  const { baseUrl, port, requests, includeOg, pid: pidArg } = parseArgs()
  const initPath = '/init'
  const ogPath = '/og/init.png?v=bench'

  const pid = pidArg ?? detectServerPid(port)
  if (!pid) {
    console.error(`Could not detect server PID on port ${port}. Pass --pid=`)
    process.exit(1)
  }

  console.log('\nInit page server memory benchmark')
  console.log(`Server PID: ${pid}`)
  console.log(`URL: ${baseUrl}${initPath}`)
  console.log(`Requests: ${requests}${includeOg ? ' (+ OG image each cycle)' : ''}\n`)

  const samples: MemorySample[] = []

  for (let i = 0; i < WARMUP_REQUESTS; i++) {
    await fetchPath(baseUrl, initPath)
    if (includeOg) await fetchPath(baseUrl, ogPath)
  }

  const warmupRss = readProcessRssMb(pid)
  if (warmupRss == null) {
    console.error(`Could not read RSS for PID ${pid}`)
    process.exit(1)
  }
  samples.push({ label: 'after-warmup', rssMb: warmupRss })

  const checkpointEvery = Math.max(1, Math.floor(requests / 4))
  for (let i = 1; i <= requests; i++) {
    await fetchPath(baseUrl, initPath)
    if (includeOg) await fetchPath(baseUrl, ogPath)

    if (i % checkpointEvery === 0 || i === requests) {
      const rss = readProcessRssMb(pid)
      if (rss != null) {
        samples.push({ label: `${i} requests`, rssMb: rss })
      }
    }
  }

  const baseline = samples[0]?.rssMb ?? 0
  const final = samples[samples.length - 1]?.rssMb ?? 0
  const growth = final - baseline

  for (const sample of samples) {
    const delta = sample.rssMb - baseline
    console.log(
      `${sample.label.padEnd(16)} RSS ${sample.rssMb.toFixed(1)} MB (${delta >= 0 ? '+' : ''}${delta.toFixed(1)} MB)`,
    )
  }

  console.log(`\nRSS growth (warmup → final): ${growth >= 0 ? '+' : ''}${growth.toFixed(1)} MB`)

  const thresholdMb = 40
  if (growth > thresholdMb) {
    console.error(`\nFAIL: server RSS grew more than ${thresholdMb} MB`)
    process.exit(1)
  }

  console.log('\nPASS: server RSS growth within threshold')
}

main().catch((error) => {
  console.error(error)
  process.exit(1)
})
