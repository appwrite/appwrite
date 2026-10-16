import type { Models } from '@appwrite.io/console'
import type { ArchiveEntry } from './archive'
import type { AnalyticsExportData } from './collect'

function cell(value: unknown): string {
  if (value === null || value === undefined) return ''
  const text = typeof value === 'number' ? formatNumberCell(value) : String(value)
  // Quote anything that could break a row, and neutralise spreadsheet
  // formula injection (=, +, -, @ at the start of a cell).
  const guarded = /^[=+\-@]/.test(text) && typeof value !== 'number' ? `'${text}` : text
  return /[",\n\r]/.test(guarded) ? `"${guarded.replace(/"/g, '""')}"` : guarded
}

function formatNumberCell(value: number): string {
  if (!Number.isFinite(value)) return ''
  return Number.isInteger(value) ? String(value) : value.toFixed(2)
}

/** UTF-8 byte-order mark so Excel opens UTF-8 (accented cities, non-Latin pages) correctly. */
const UTF8_BOM = String.fromCharCode(0xfeff)

export function toCsv(header: string[], rows: unknown[][]): string {
  return (
    UTF8_BOM +
    [header, ...rows].map((row) => row.map(cell).join(',')).join('\r\n') +
    '\r\n'
  )
}

function change(current?: number | null, previous?: number | null) {
  if (current == null || previous == null || previous === 0) return null
  return ((current - previous) / previous) * 100
}

const SUMMARY_METRICS: { key: keyof Models.AnalyticsMetric; label: string }[] = [
  { key: 'visitors', label: 'Unique visitors' },
  { key: 'visits', label: 'Visits' },
  { key: 'sessions', label: 'Sessions' },
  { key: 'pageviews', label: 'Pageviews' },
  { key: 'events', label: 'Events' },
  { key: 'viewsPerVisit', label: 'Views per visit' },
  { key: 'bounceRate', label: 'Bounce rate (%)' },
  { key: 'visitDuration', label: 'Visit duration (s)' },
  { key: 'engagementTime', label: 'Engagement time (s)' },
  { key: 'scrollDepth', label: 'Scroll depth (%)' },
]

export function slugify(value: string): string {
  return (
    value
      .normalize('NFKD')
      .replace(/[^\w\s-]/g, '')
      .trim()
      .replace(/[\s_]+/g, '-')
      .toLowerCase() || 'property'
  )
}

/** One CSV per dataset, plus a README describing the export. */
export function buildCsvEntries(
  data: AnalyticsExportData,
  folder: string,
): ArchiveEntry[] {
  const entries: ArchiveEntry[] = []
  const comparing = !!data.comparisonRange

  entries.push({
    path: `${folder}/README.txt`,
    content: [
      `Analytics data export`,
      ``,
      `Property:   ${data.property.name} (${data.property.$id})`,
      `Domain:     ${data.property.domain || '-'}`,
      `Range:      ${data.range.startAt} to ${data.range.endAt}`,
      `Interval:   ${data.interval === '1h' ? 'hourly' : 'daily'}`,
      `Comparison: ${
        data.comparisonRange
          ? `${data.compareLabel ?? 'custom'} (${data.comparisonRange.startAt} to ${data.comparisonRange.endAt})`
          : 'none'
      }`,
      `Filters:    ${data.filterLabels.length ? data.filterLabels.join('; ') : 'none'}`,
      `Generated:  ${data.generatedAt.toISOString()}`,
      ``,
      `Files`,
      `  summary.csv           Property-wide totals${comparing ? ' with comparison' : ''}`,
      `  timeseries.csv        Visitors, sessions and events per bucket`,
      `  breakdowns/*.csv      Top 100 values per dimension, ranked by visitors`,
      ``,
      `Times are ISO 8601 in UTC. Visitors and sessions in breakdowns are`,
      `unique within each row; they don't sum to the property total.`,
      `events.csv lists every event, including automatic ones (pageview,`,
      `outbound_link, file_download, scroll_depth, engagement_time, and`,
      `Flutter's screen_view, app_backgrounded, app_foregrounded).`,
      ...(data.breakdowns.some((breakdown) => breakdown.skipped)
        ? [
            ``,
            `Not included`,
            ...data.breakdowns
              .filter((breakdown) => breakdown.skipped)
              .map(
                (breakdown) =>
                  `  ${breakdown.label}: ${
                    breakdown.skipped === 'unsupported'
                      ? 'not available with a page or event filter'
                      : 'could not be loaded'
                  }`,
              ),
          ]
        : []),
    ].join('\n'),
  })

  if (data.stats) {
    entries.push({
      path: `${folder}/summary.csv`,
      content: toCsv(
        comparing
          ? ['Metric', 'Value', 'Comparison value', 'Change (%)']
          : ['Metric', 'Value'],
        SUMMARY_METRICS.map(({ key, label }) => {
          const value = data.stats?.[key] as number | undefined
          if (!comparing) return [label, value]
          const previous = data.comparisonStats?.[key] as number | undefined
          return [label, value, previous, change(value, previous)]
        }),
      ),
    })
  }

  entries.push({
    path: `${folder}/timeseries.csv`,
    content: toCsv(
      comparing
        ? [
            'Bucket start',
            'Visitors',
            'Sessions',
            'Events',
            'Comparison bucket start',
            'Comparison visitors',
            'Comparison sessions',
            'Comparison events',
          ]
        : ['Bucket start', 'Visitors', 'Sessions', 'Events'],
      data.series.map((point, index) => {
        const row: unknown[] = [
          point.day.toISOString(),
          point.visitors,
          point.sessions,
          point.events,
        ]
        if (comparing) {
          const previous = data.comparisonSeries[index]
          row.push(
            previous?.day.toISOString(),
            previous?.visitors,
            previous?.sessions,
            previous?.events,
          )
        }
        return row
      }),
    ),
  })

  for (const breakdown of data.breakdowns) {
    if (breakdown.skipped) continue
    const total = breakdown.rows.reduce((sum, row) => sum + row.visitors, 0)
    entries.push({
      path: `${folder}/breakdowns/${slugify(breakdown.label)}.csv`,
      content: toCsv(
        [breakdown.label, 'Visitors', 'Share of visitors (%)', 'Sessions', 'Events'],
        breakdown.rows.map((row) => [
          row.value || 'Unknown',
          row.visitors,
          total > 0 ? (row.visitors / total) * 100 : 0,
          row.sessions,
          row.events,
        ]),
      ),
    })
  }

  return entries
}
