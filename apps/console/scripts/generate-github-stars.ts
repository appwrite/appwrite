/**
 * Fetches GitHub star count + weekly history and writes
 * src/lib/generated/github-stars.json.
 * Run: bun run generate:github-stars
 */
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import {
  buildCumulativeStarHistory,
  downsampleStarHistoryMonthly,
  type GitHubStarHistoryPoint,
  type GitHubStarWeek,
} from '../src/lib/marketing/github-stars-history.ts'

const __dirname = dirname(fileURLToPath(import.meta.url))
const outFile = join(__dirname, '../src/lib/generated/github-stars.json')
const GITHUB_REPO = 'https://api.github.com/repos/appwrite/appwrite'
const GITHUB_HISTORY = `${GITHUB_REPO}/stargazers/history`
const OSS_INSIGHT_HISTORY =
  'https://api.ossinsight.io/v1/repos/appwrite/appwrite/stargazers/history/?per=month&from=2019-01-01&to=2099-01-01'
const HISTORY_PAGE_SIZE = 30
const HISTORY_MAX_PAGES = 20

type GeneratedStarsFile = {
  stars: number
  fetchedAt: string
  history: GitHubStarHistoryPoint[]
}

function githubHeaders(): HeadersInit {
  const headers: Record<string, string> = {
    Accept: 'application/vnd.github+json',
    'X-GitHub-Api-Version': '2026-03-10',
    'User-Agent': 'appwrite-console',
  }
  const token = process.env.GITHUB_TOKEN
  if (token) {
    headers.Authorization = `Bearer ${token}`
  }
  return headers
}

async function readExisting(): Promise<GeneratedStarsFile | null> {
  try {
    const raw = await readFile(outFile, 'utf8')
    const parsed = JSON.parse(raw) as Partial<GeneratedStarsFile>
    if (typeof parsed.stars !== 'number') return null
    return {
      stars: parsed.stars,
      fetchedAt:
        typeof parsed.fetchedAt === 'string'
          ? parsed.fetchedAt
          : new Date().toISOString(),
      history: Array.isArray(parsed.history) ? parsed.history : [],
    }
  } catch {
    return null
  }
}

async function fetchJson(url: string, headers?: HeadersInit): Promise<unknown> {
  const res = await fetch(url, { headers })
  if (!res.ok) {
    throw new Error(`${url} failed: ${res.status} ${res.statusText}`)
  }
  return res.json()
}

function parseStarWeeks(payload: unknown): GitHubStarWeek[] {
  if (!Array.isArray(payload)) return []
  const weeks: GitHubStarWeek[] = []
  for (const item of payload) {
    if (!item || typeof item !== 'object') continue
    const week = (item as { week?: unknown }).week
    const total = (item as { total?: unknown }).total
    if (typeof week !== 'number' || typeof total !== 'number') continue
    weeks.push({ week, total })
  }
  return weeks
}

async function fetchGitHubHistory(): Promise<GitHubStarWeek[]> {
  const weeks: GitHubStarWeek[] = []
  for (let page = 1; page <= HISTORY_MAX_PAGES; page += 1) {
    const url = `${GITHUB_HISTORY}?per_page=${HISTORY_PAGE_SIZE}&page=${page}`
    const payload = await fetchJson(url, githubHeaders())
    const batch = parseStarWeeks(payload)
    weeks.push(...batch)
    if (batch.length < HISTORY_PAGE_SIZE) break
  }
  return weeks
}

function parseOssInsightHistory(payload: unknown): GitHubStarHistoryPoint[] {
  const rows =
    payload &&
    typeof payload === 'object' &&
    'data' in payload &&
    payload.data &&
    typeof payload.data === 'object' &&
    'rows' in payload.data &&
    Array.isArray((payload.data as { rows?: unknown }).rows)
      ? ((payload.data as { rows: unknown[] }).rows ?? [])
      : []

  const points: GitHubStarHistoryPoint[] = []
  for (const row of rows) {
    if (!row || typeof row !== 'object') continue
    const date = (row as { date?: unknown }).date
    const stargazers = (row as { stargazers?: unknown }).stargazers
    const stars =
      typeof stargazers === 'number' ? stargazers : Number(stargazers)
    if (typeof date !== 'string' || !Number.isFinite(stars)) continue
    points.push({ date, stars: Math.max(0, Math.round(stars)) })
  }
  return downsampleStarHistoryMonthly(points)
}

async function fetchOssInsightHistory(): Promise<GitHubStarHistoryPoint[]> {
  const payload = await fetchJson(OSS_INSIGHT_HISTORY, {
    Accept: 'application/json',
    'User-Agent': 'appwrite-console',
  })
  return parseOssInsightHistory(payload)
}

function withCurrentCount(
  history: GitHubStarHistoryPoint[],
  stars: number,
  fetchedAt: Date,
): GitHubStarHistoryPoint[] {
  const today = fetchedAt.toISOString().slice(0, 10)
  const withoutToday = history.filter((point) => point.date !== today)
  return downsampleStarHistoryMonthly([...withoutToday, { date: today, stars }])
}

async function writeStarsFile(data: GeneratedStarsFile): Promise<void> {
  await mkdir(dirname(outFile), { recursive: true })
  await writeFile(outFile, `${JSON.stringify(data, null, 2)}\n`)
}

async function run() {
  const existing = await readExisting()
  const fetchedAt = new Date()

  try {
    const repo = (await fetchJson(GITHUB_REPO, githubHeaders())) as {
      stargazers_count?: unknown
    }
    const stars =
      typeof repo.stargazers_count === 'number' ? repo.stargazers_count : 0

    let history: GitHubStarHistoryPoint[] = []
    try {
      const weeks = await fetchGitHubHistory()
      history = downsampleStarHistoryMonthly(
        buildCumulativeStarHistory(stars, weeks, fetchedAt),
      )
    } catch (err) {
      console.warn(
        'GitHub star history unavailable, trying OSS Insight.',
        err instanceof Error ? err.message : err,
      )
      history = withCurrentCount(
        await fetchOssInsightHistory(),
        stars,
        fetchedAt,
      )
    }

    if (history.length < 2 && existing?.history?.length) {
      history = withCurrentCount(existing.history, stars, fetchedAt)
    }

    await writeStarsFile({
      stars,
      fetchedAt: fetchedAt.toISOString(),
      history,
    })
  } catch (err) {
    if (existing) {
      console.warn(`Using existing ${outFile}. Reason:`, err)
      return
    }
    throw err
  }
}

await run()
