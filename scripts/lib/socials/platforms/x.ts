import { mkdir, writeFile } from 'node:fs/promises'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { parseArgs } from 'node:util'

import { type SocialPlatform } from '../cli'
import {
  fetchRecentPosts,
  fetchUserTimeline,
  parseSinceArg,
  resolveUser,
  resolveUserId,
} from './x-api'
import {
  APPWRITE_COMPETITOR_ACCOUNTS,
  type AccountTimelineExport,
  type CompetitiveScanExport,
} from './x-competitors'

const __dirname = dirname(fileURLToPath(import.meta.url))
const DEFAULT_SCAN_OUTPUT = join(__dirname, '..', '..', '..', 'output', 'social', 'x-competitive-scan.json')

function getStringOption(value: string | boolean | undefined, fallback?: string): string | undefined {
  return typeof value === 'string' ? value : fallback
}

function formatTweet(tweet: { id: string; text: string; created_at?: string; public_metrics?: object }): string {
  const preview = tweet.text.replace(/\s+/g, ' ').trim()
  const metrics = tweet.public_metrics as
    | { like_count: number; retweet_count: number; reply_count: number }
    | undefined
  const metricLine = metrics
    ? `\n  likes: ${metrics.like_count}  retweets: ${metrics.retweet_count}  replies: ${metrics.reply_count}`
    : ''
  const date = tweet.created_at ? `\n  ${tweet.created_at}` : ''

  return `${tweet.id}${date}\n  ${preview}${metricLine}`
}

async function runPostsCommand(args: string[]): Promise<void> {
  const { values } = parseArgs({
    args,
    options: {
      'user-id': { type: 'string' },
      username: { type: 'string' },
      limit: { type: 'string', default: '10' },
    },
    strict: false,
  })

  const limit = Number.parseInt(getStringOption(values.limit, '10')!, 10)
  if (!Number.isFinite(limit) || limit < 1) {
    throw new Error('--limit must be a positive number')
  }

  const userId = await resolveUserId(getStringOption(values['user-id']), getStringOption(values.username))
  const posts = await fetchRecentPosts(userId, limit)

  if (posts.length === 0) {
    console.log('No posts found.')
    return
  }

  console.log(`Fetched ${posts.length} post(s) from user ${userId}:\n`)
  for (const post of posts) {
    console.log(formatTweet(post))
    console.log('')
  }
}

async function runTimelineCommand(args: string[]): Promise<void> {
  const { values } = parseArgs({
    args,
    options: {
      'user-id': { type: 'string' },
      username: { type: 'string' },
      since: { type: 'string', default: '6' },
      output: { type: 'string' },
    },
    strict: false,
  })

  const sinceRange = parseSinceArg(getStringOption(values.since, '6')!)
  const userId = await resolveUserId(getStringOption(values['user-id']), getStringOption(values.username))
  const username = getStringOption(values.username) ?? userId

  process.stderr.write(
    `Fetching @${username} tweets since ${sinceRange.since.toISOString()} (${sinceRange.label})...\n`,
  )

  const tweets = await fetchUserTimeline({
    userId,
    since: sinceRange.since,
    onPage: (page, count) => {
      process.stderr.write(`  page ${page}: ${count} tweets\n`)
    },
  })

  const output = getStringOption(values.output)
  if (output) {
    await mkdir(dirname(output), { recursive: true })
    const payload = {
      username,
      userId,
      since: sinceRange.since.toISOString(),
      fetchedAt: new Date().toISOString(),
      tweetCount: tweets.length,
      tweets,
    }
    await writeFile(output, JSON.stringify(payload, null, 2))
    console.log(`Wrote ${tweets.length} tweets to ${output}`)
    return
  }

  console.log(`Fetched ${tweets.length} tweet(s) since ${sinceRange.since.toISOString()}:\n`)
  for (const post of tweets) {
    console.log(formatTweet(post))
    console.log('')
  }
}

async function runScanCommand(args: string[]): Promise<void> {
  const { values } = parseArgs({
    args,
    options: {
      since: { type: 'string', default: '6' },
      output: { type: 'string', default: DEFAULT_SCAN_OUTPUT },
      username: { type: 'string' },
      accounts: { type: 'string' },
    },
    strict: false,
  })

  const sinceRange = parseSinceArg(getStringOption(values.since, '6')!)
  const outputPath = getStringOption(values.output, DEFAULT_SCAN_OUTPUT)!
  const usernameOption = getStringOption(values.username)
  const accountsOption = getStringOption(values.accounts)

  const accountList = usernameOption
    ? (() => {
        const username = usernameOption.trim().replace(/^@/, '')
        return [{ username, label: username, isAppwrite: username.toLowerCase() === 'appwrite' }]
      })()
    : accountsOption
      ? accountsOption.split(',').map((entry) => {
          const username = entry.trim().replace(/^@/, '')
          return { username, label: username, isAppwrite: username.toLowerCase() === 'appwrite' }
        })
      : APPWRITE_COMPETITOR_ACCOUNTS.map((account) => ({ ...account }))

  const result: CompetitiveScanExport = {
    scannedAt: new Date().toISOString(),
    since: sinceRange.since.toISOString(),
    sinceLabel: sinceRange.label,
    sinceUnit: sinceRange.unit,
    sinceAmount: sinceRange.amount,
    durationDays: sinceRange.durationDays,
    accounts: [],
    errors: [],
  }

  process.stderr.write(`Scan window: ${sinceRange.label}\n`)

  for (const account of accountList) {
    process.stderr.write(`\nScanning @${account.username}...\n`)

    try {
      const user = await resolveUser(account.username)
      const tweets = await fetchUserTimeline({
        userId: user.id,
        since: sinceRange.since,
        onPage: (page, count) => {
          process.stderr.write(`  page ${page}: ${count} tweets\n`)
        },
      })

      const entry: AccountTimelineExport = {
        username: user.username,
        label: account.label,
        isAppwrite: account.isAppwrite,
        userId: user.id,
        name: user.name,
        public_metrics: user.public_metrics,
        fetchedAt: new Date().toISOString(),
        since: sinceRange.since.toISOString(),
        tweetCount: tweets.length,
        tweets,
      }

      result.accounts.push(entry)
      process.stderr.write(`  done: ${tweets.length} tweets\n`)
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error)
      result.errors.push({ username: account.username, error: message })
      process.stderr.write(`  failed: ${message}\n`)
    }
  }

  await mkdir(dirname(outputPath), { recursive: true })
  await writeFile(outputPath, JSON.stringify(result, null, 2))

  const totalTweets = result.accounts.reduce((sum, account) => sum + account.tweetCount, 0)
  console.log(`\nScan complete: ${result.accounts.length} accounts, ${totalTweets} tweets`)
  if (result.errors.length > 0) {
    console.log(`Errors: ${result.errors.length} (${result.errors.map((e) => `@${e.username}`).join(', ')})`)
  }
  console.log(`Output: ${outputPath}`)
}

export const xPlatform: SocialPlatform = {
  description: 'X (Twitter) account tools',
  commands: {
    posts: {
      description: 'Fetch recent posts from an X account',
      run: runPostsCommand,
    },
    timeline: {
      description: 'Fetch paginated posts (--since=6, 6m, 4w, or ISO date)',
      run: runTimelineCommand,
    },
    scan: {
      description: 'Scan X account(s) (--username=appwrite, --accounts=a,b, or all competitors)',
      run: runScanCommand,
    },
  },
}
