/**
 * Turns the raw events of a code review into rows a person can read on the
 * review detail page: a label and a one-line summary per step, small facts as
 * pills, and each subagent's steps folded under the row that started it.
 *
 * Copy goes through the translator passed in, so the page can localize it;
 * values from the review (paths, patterns, commands) are shown as they are.
 */
import type { ReviewEvent } from '@/lib/appwrite/reviews'
import type { Translator } from '@/lib/i18n/translate'

export type TraceTone = 'default' | 'ok' | 'warn' | 'error' | 'muted'

export type TraceItem = {
  id: string
  type: string
  /** Short name of what happened, such as `Read file`. */
  label: string
  /** One line on what it touched or decided. Shown in monospace when `mono` is set. */
  summary: string
  mono: boolean
  /** Full text the summary cuts short, such as a multi-line command. */
  code?: string
  /** Small facts shown beside the summary, such as `exit 1` or `2.4s`. */
  meta: string[]
  tone: TraceTone
  /** Seconds since the first event of the review. */
  offset: number
  data: Record<string, unknown>
}

export type TraceSubagent = {
  kind: 'subagent'
  agent: string
  task: string
  paths: string[]
  start: TraceItem
  end?: TraceItem
  items: TraceItem[]
}

export type TraceEntry = { kind: 'event'; item: TraceItem } | TraceSubagent

const str = (value: unknown) => (typeof value === 'string' ? value : '')
const num = (value: unknown) => (typeof value === 'number' ? value : undefined)
const list = (value: unknown) => (Array.isArray(value) ? value : [])
const short = (sha: unknown) => str(sha).slice(0, 7)

/** Fills `{name}` placeholders in an already translated template. */
function fill(template: string, values: Record<string, string | number>) {
  return Object.entries(values).reduce(
    (text, [key, value]) => text.replaceAll(`{${key}}`, String(value)),
    template,
  )
}

/** Picks the singular or plural template by count, translates it and fills `{count}`. */
function count(
  t: Translator,
  n: number,
  singular: string,
  plural: string,
  values: Record<string, string | number> = {},
) {
  return fill(t(n === 1 ? singular : plural), { count: n, ...values })
}

export function formatMs(ms: number | undefined) {
  if (ms === undefined) return ''
  if (ms < 1000) return `${ms}ms`
  if (ms < 59_950) return `${(ms / 1000).toFixed(1)}s`
  // Round to whole seconds first so 119.6s reads 2m 0s, not 1m 60s.
  const seconds = Math.round(ms / 1000)
  return `${Math.floor(seconds / 60)}m ${seconds % 60}s`
}

export function formatOffset(seconds: number) {
  return seconds < 60
    ? `+${seconds}s`
    : `+${Math.floor(seconds / 60)}m${String(seconds % 60).padStart(2, '0')}s`
}

function range(start: unknown, end: unknown) {
  const from = num(start)
  const to = num(end)
  if (from === undefined) return ''
  return to === undefined || to === from ? `${from}` : `${from}-${to}`
}

function lines(path: string, start: unknown, end: unknown) {
  const span = range(start, end)
  return span ? `${path}:${span}` : path
}

/** `key: value` pairs of the plain fields, for event types without their own rendering. */
function fallbackSummary(data: Record<string, unknown>) {
  return Object.entries(data)
    .filter(
      ([key, value]) =>
        key !== 'agent' && value !== null && typeof value !== 'object',
    )
    .map(([key, value]) => `${key}: ${value}`)
    .join(' · ')
}

/** English labels by event type; translated where they are shown. */
const labels: Record<string, string> = {
  'review.mode': 'Review started',
  'review.merge_only': 'Merge only',
  'review.submitted': 'Model submitted',
  'review.completed': 'Review completed',
  'review.posted': 'Posted to GitHub',
  'config.invalid': 'Invalid config',
  'sandbox.ready': 'Sandbox ready',
  'sandbox.setup': 'Sandbox setup',
  'sandbox.skipped': 'No sandbox',
  'sandbox.failed': 'Sandbox failed',
  'files.filtered': 'Files filtered',
  'files.prompt': 'Prompt files',
  'tool.read_file': 'Read file',
  'tool.grep': 'Search',
  'tool.ast_search': 'AST search',
  'tool.file_history': 'File history',
  'tool.list_refs': 'List refs',
  'tool.path_in_refs': 'Path in refs',
  'tool.list_files': 'List files',
  'tool.run_command': 'Run command',
  'subagent.started': 'Subagent',
  'subagent.completed': 'Subagent done',
  'subagent.failed': 'Subagent failed',
  'subagent.skipped': 'Subagent skipped',
  'verify.completed': 'Verified',
  'finding.placement': 'Placement',
  'finding.relocated': 'Relocated',
  'anchoring.suspicious': 'Anchoring',
  'suggestion.removed': 'Suggestion removed',
  'chat.remember': 'Remembered',
  'chat.mark_finding': 'Marked finding',
}

type Described = Pick<TraceItem, 'summary'> &
  Partial<Omit<TraceItem, 'summary'>>

function tier(t: Translator, value: unknown) {
  return value ? fill(t('tier {tier}'), { tier: String(value) }) : ''
}

function describe(
  t: Translator,
  type: string,
  d: Record<string, unknown>,
): Described {
  switch (type) {
    case 'review.mode': {
      const openFindings = Number(d.openFindings ?? 0)
      const learnings = Number(d.learnings ?? 0)
      const meta = [
        openFindings
          ? count(
              t,
              openFindings,
              '{count} open finding',
              '{count} open findings',
            )
          : '',
        learnings
          ? count(t, learnings, '{count} learning', '{count} learnings')
          : '',
        ...list(d.linkedIssues).map((n) => `#${n}`),
        ...list(d.failedChecks).map((name) =>
          fill(t('failed: {name}'), { name: String(name) }),
        ),
      ]
      const sha = short(d.from)
      const summary = d.mergeOnly
        ? fill(t('Merge-only update since {sha}'), { sha })
        : d.incremental
          ? fill(t('Incremental review since {sha}'), { sha })
          : fill(t('Full review from base {sha}'), { sha })
      return { summary, meta }
    }
    case 'review.merge_only':
      return {
        summary: str(d.baseRef)
          ? fill(t('Only {ref} was merged in; previous review carried over'), {
              ref: str(d.baseRef),
            })
          : t(
              'Only the base branch was merged in; previous review carried over',
            ),
        meta: [str(d.verdict), tier(t, d.tier)],
      }
    case 'review.submitted': {
      const findings = Number(d.findings ?? 0)
      const fromSubagents = Number(d.fromSubagents ?? 0)
      const resolved = list(d.resolved).length
      return {
        summary: fromSubagents
          ? count(
              t,
              findings,
              '{count} finding ({subagents} from subagents)',
              '{count} findings ({subagents} from subagents)',
              { subagents: fromSubagents },
            )
          : count(t, findings, '{count} finding', '{count} findings'),
        meta: [
          resolved ? fill(t('{count} resolved'), { count: resolved }) : '',
          tier(t, d.tier),
        ],
      }
    }
    case 'review.completed':
      return {
        summary: count(
          t,
          Number(d.posted ?? 0),
          '{count} comment posted, {dropped} dropped, {open} still open',
          '{count} comments posted, {dropped} dropped, {open} still open',
          { dropped: Number(d.dropped ?? 0), open: Number(d.stillOpen ?? 0) },
        ),
        meta: [str(d.verdict), tier(t, d.tier)],
        tone: 'ok',
      }
    case 'review.posted':
      return {
        summary: str(d.summaryUrl) || t('Review posted'),
        mono: Boolean(str(d.summaryUrl)),
        meta: [
          d.submittedReview ? t('review submitted') : t('comment only'),
          d.headMoved ? t('head moved') : '',
          d.dismissedChangeRequests
            ? fill(t('{count} dismissed'), {
                count: Number(d.dismissedChangeRequests),
              })
            : '',
        ],
        tone: 'ok',
      }
    case 'config.invalid':
      return {
        summary:
          list(d.errors).map(String).join('; ') ||
          t('The repository config is invalid'),
        tone: 'error',
      }
    case 'sandbox.ready': {
      const omitted = Number(d.omitted ?? 0)
      return {
        summary: fill(t('Started in {time}'), { time: formatMs(num(d.ms)) }),
        meta: [
          omitted
            ? count(t, omitted, '{count} file omitted', '{count} files omitted')
            : '',
        ],
      }
    }
    case 'sandbox.setup':
      return commandItem(t, d)
    case 'sandbox.skipped':
      return { summary: str(d.reason), tone: 'muted' }
    case 'sandbox.failed':
      return { summary: str(d.error), tone: 'error' }
    case 'files.filtered':
      return {
        summary: count(
          t,
          list(d.included).length,
          '{count} file to review, {excluded} excluded',
          '{count} files to review, {excluded} excluded',
          { excluded: list(d.excluded).length },
        ),
      }
    case 'files.prompt': {
      const notShown = list(d.notShown).length
      return {
        summary: count(
          t,
          list(d.shown).length,
          '{count} file in the prompt',
          '{count} files in the prompt',
        ),
        meta: [
          notShown
            ? fill(t('{count} too large to show'), { count: notShown })
            : '',
        ],
        tone: notShown ? 'warn' : 'default',
      }
    }
    case 'tool.read_file':
      return {
        summary: lines(str(d.path), d.startLine, d.endLine),
        mono: true,
        meta: [d.ref === 'base' ? t('base') : ''],
      }
    case 'tool.grep':
      return { summary: str(d.pattern), mono: true, meta: [str(d.glob)] }
    case 'tool.ast_search':
      return {
        summary: str(d.pattern),
        mono: true,
        meta: [str(d.language), str(d.path)],
      }
    case 'tool.file_history':
      return {
        summary: str(d.path),
        mono: true,
        meta: [
          d.limit ? fill(t('last {count}'), { count: Number(d.limit) }) : '',
          d.patches ? t('with patches') : '',
        ],
      }
    case 'tool.list_refs':
      return { summary: str(d.pattern) || t('all refs'), mono: true }
    case 'tool.path_in_refs':
      return {
        summary: str(d.path),
        mono: true,
        meta: [count(t, Number(d.refs ?? 0), '{count} ref', '{count} refs')],
      }
    case 'tool.list_files':
      return { summary: str(d.path) || '.', mono: true }
    case 'tool.run_command':
      return commandItem(t, d)
    case 'subagent.started':
      return { summary: str(d.task) }
    case 'subagent.completed':
      return {
        summary: count(
          t,
          Number(d.findings ?? 0),
          '{count} finding',
          '{count} findings',
        ),
        meta: [formatMs(num(d.ms))],
        tone: 'ok',
      }
    case 'subagent.failed':
      return {
        summary: str(d.error),
        meta: [formatMs(num(d.ms))],
        tone: 'error',
      }
    case 'subagent.skipped':
      return { summary: str(d.task), meta: [str(d.reason)], tone: 'muted' }
    case 'verify.completed': {
      const verdicts = list(d.verdicts) as { keep?: boolean }[]
      const kept = verdicts.filter((v) => v.keep).length
      return {
        summary: count(
          t,
          verdicts.length,
          'Kept {kept} of {count} finding',
          'Kept {kept} of {count} findings',
          { kept },
        ),
        tone: kept < verdicts.length ? 'warn' : 'default',
      }
    }
    case 'finding.placement':
      return {
        summary: `${lines(str(d.path), d.startLine, d.endLine)} ${str(d.title)}`,
        mono: true,
        meta: [d.kept ? t('kept') : t('dropped'), str(d.reason)],
        tone: d.kept ? 'warn' : 'error',
      }
    case 'finding.relocated': {
      const from = list(d.from)
      const to = list(d.to)
      return {
        summary: `${str(d.path)} ${str(d.title)}`,
        mono: true,
        meta: [
          fill(t('lines {from} → {to}'), {
            from: range(from[0], from[1]),
            to: range(to[0], to[1]),
          }),
        ],
      }
    }
    case 'anchoring.suspicious':
      return {
        summary: count(
          t,
          Number(d.submitted ?? 0),
          '{unattached} of {count} finding could not be placed on the diff',
          '{unattached} of {count} findings could not be placed on the diff',
          { unattached: Number(d.unattached ?? 0) },
        ),
        tone: 'warn',
      }
    case 'suggestion.removed':
      return {
        summary: `${str(d.path)} ${str(d.title)}`,
        mono: true,
        meta: [str(d.reason)],
        tone: 'warn',
      }
    default:
      return { summary: fallbackSummary(d) }
  }
}

function commandItem(t: Translator, d: Record<string, unknown>): Described {
  const command = str(d.command)
  const [first = ''] = command.split('\n')
  const multiline = command.includes('\n')
  const exitCode = num(d.exitCode)
  const failed =
    Boolean(d.error) ||
    d.timedOut === true ||
    (exitCode !== undefined && exitCode !== 0)
  return {
    summary: multiline ? `${first} …` : first,
    mono: true,
    code: multiline || command.length > 120 ? command : undefined,
    meta: [
      d.skipped
        ? fill(t('skipped: {reason}'), { reason: String(d.skipped) })
        : '',
      d.timedOut
        ? t('timed out')
        : exitCode !== undefined
          ? fill(t('exit {code}'), { code: exitCode })
          : '',
      str(d.error),
      formatMs(num(d.ms)),
    ],
    tone: d.skipped ? 'muted' : failed ? 'error' : 'default',
  }
}

export function traceItem(
  t: Translator,
  event: ReviewEvent,
  startedAt: number,
): TraceItem {
  const data = event.data ?? {}
  const described = describe(t, event.type, data)
  const label = labels[event.type]
  // Any step that reports an error reads as failed, unless its own rendering says otherwise.
  const failed =
    Boolean(data.error) ||
    event.type.endsWith('.failed') ||
    event.type.endsWith('.unavailable')
  return {
    id: event.$id,
    type: event.type,
    label: label ? t(label) : event.type,
    mono: false,
    tone: failed ? 'error' : 'default',
    ...described,
    meta: (described.meta ?? []).filter(Boolean),
    offset: Math.max(
      0,
      Math.round((Date.parse(event.time) - startedAt) / 1000) || 0,
    ),
    data,
  }
}

/**
 * Rows in order, with each subagent's steps folded under the row that started
 * it. Subagents run side by side, so their steps interleave in the raw order;
 * grouping them by `agent` keeps each one readable.
 */
export function buildTrace(t: Translator, events: ReviewEvent[]): TraceEntry[] {
  const startedAt = events.length ? Date.parse(events[0].time) : 0
  const entries: TraceEntry[] = []
  const open = new Map<string, TraceSubagent>()
  for (const event of events) {
    const item = traceItem(t, event, startedAt)
    const agent = str(event.data?.agent)
    if (event.type === 'subagent.started' && agent) {
      const group: TraceSubagent = {
        kind: 'subagent',
        agent,
        task: str(event.data?.task),
        paths: list(event.data?.paths).map(String),
        start: item,
        items: [],
      }
      open.set(agent, group)
      entries.push(group)
      continue
    }
    const group = agent ? open.get(agent) : undefined
    if (!group) {
      entries.push({ kind: 'event', item })
      continue
    }
    if (
      event.type === 'subagent.completed' ||
      event.type === 'subagent.failed'
    ) {
      group.end = item
      open.delete(agent)
    } else {
      group.items.push(item)
    }
  }
  return entries
}
