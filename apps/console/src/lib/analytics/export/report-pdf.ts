import { AnalyticsDimension } from '@appwrite.io/console'
import {
  formatDuration,
  formatNumber,
  formatPercent,
  formatRatio,
} from '@/components/pages/projects/$projectId/analytics/_components/format'
import { isCustomAnalyticsEvent } from '@/lib/react-query/hooks'
import { isKnownBreakdownValue } from '@/lib/analytics/breakdown-values'
import {
  ANALYTICS_BOT_FALLBACK_COLOR,
  ANALYTICS_HUMAN_COLOR,
  analyticsBotColor,
} from '@/lib/analytics/palette'
import { PdfDocument, hex, tint, type DocumentFonts, type Rgb } from './pdf'
import type { AnalyticsExportData, ExportBreakdown } from './collect'

// ─── Design tokens ──────────────────────────────────────────────────────────

const COLORS = {
  pink: '#FD366E',
  ink: '#19191C',
  muted: '#6C6C71',
  subtle: '#97979B',
  border: '#EDEDF0',
  surface: '#FAFAFB',
  good: '#0E9F6E',
  bad: '#D97706',
}
/** Same brand palette as the console card: humans purple, bot types peach, mint, pink, then grey. */
const HUMAN_COLOR = ANALYTICS_HUMAN_COLOR

/**
 * Full Appwrite logo (public/appwrite-light.svg, viewBox 0 0 132 24): the
 * wordmark in ink, the logomark in brand pink.
 */
const LOGO_VIEWBOX = { width: 132, height: 24 }
const LOGO_WORDMARK_PATHS = [
  'M38.5573 19.4953C40.7162 19.4953 41.8075 18.3821 42.282 17.6242H42.4955C42.5904 18.4295 43.1598 19.1874 44.2749 19.1874H46.3864V16.8188H45.8407C45.4611 16.8188 45.2713 16.6057 45.2713 16.2741V6.77602H42.4718V8.29191H42.2583C41.7126 7.53397 40.5738 6.4681 38.4861 6.4681C35.1646 6.4681 32.6973 9.21567 32.6973 12.9817C32.6973 16.7478 35.2121 19.4953 38.5573 19.4953ZM39.0555 16.7952C37.0863 16.7952 35.5442 15.3503 35.5442 13.0054C35.5442 10.7079 37.0389 9.14461 39.0317 9.14461C40.9297 9.14461 42.5193 10.5421 42.5193 13.0054C42.5193 15.1135 41.167 16.7952 39.0555 16.7952Z',
  'M48.0392 24.0001H50.8387V17.6242H51.0522C51.5742 18.3821 52.6893 19.4953 54.8956 19.4953C58.2171 19.4953 60.637 16.7004 60.637 12.9817C60.637 9.23935 58.051 6.4681 54.7058 6.4681C52.5706 6.4681 51.5267 7.62871 51.0285 8.26823H50.815V6.77602H48.0392V24.0001ZM54.3025 16.8662C52.3808 16.8662 50.7913 15.4451 50.7913 12.9817C50.7913 10.8737 52.1436 9.09724 54.2551 9.09724C56.2242 9.09724 57.7663 10.6368 57.7663 12.9817C57.7663 15.2793 56.2717 16.8662 54.3025 16.8662Z',
  'M62.0816 24.0001H64.8811V17.6242H65.0946C65.6165 18.3821 66.7316 19.4953 68.938 19.4953C72.2594 19.4953 74.4487 16.7004 74.4487 12.9817C74.4487 9.23935 72.0934 6.4681 68.7482 6.4681C66.613 6.4681 65.5691 7.62871 65.0709 8.26823H64.8573V6.77602H62.0816V24.0001ZM68.3449 16.8662C66.4232 16.8662 64.8336 15.4451 64.8336 12.9817C64.8336 10.8737 66.1859 9.09724 68.2974 9.09724C70.2666 9.09724 71.8087 10.6368 71.8087 12.9817C71.8087 15.2793 70.314 16.8662 68.3449 16.8662Z',
  'M78.1493 19.4756H82.1114L84.3652 9.74073H84.5076L86.7614 19.4756H90.6997L93.8533 7.06423H91.0318L88.778 16.8228H88.5645L86.3106 7.06423H82.5858L80.3083 16.8228H80.0948L77.8647 7.06423H74.8754L78.1493 19.4756Z',
  'M95.2716 19.4756H98.0711V13.341C98.0711 10.9961 99.1624 9.55125 101.203 9.55125H102.436V6.75631H101.511C99.9216 6.75631 98.7117 7.84586 98.2372 8.88804H98.0474V7.06422H95.2716V19.4756Z',
  'M116.329 19.4756H118.512V16.9886H116.353C115.499 16.9886 115.143 16.6096 115.143 15.7333V9.52756H118.654V7.06422H115.143V3.5824H112.486V7.06422H110.161V9.52756H112.32V15.757C112.32 18.3861 113.909 19.4756 116.329 19.4756Z',
  'M126.022 19.4953C128.608 19.4953 130.886 18.2163 131.692 15.6345L129.13 15.0187C128.679 16.3925 127.375 17.1031 125.999 17.1031C123.958 17.1031 122.606 15.7767 122.582 13.6923H132.001V12.9107C132.001 9.21567 129.7 6.4681 125.904 6.4681C122.558 6.4681 119.688 9.09724 119.688 13.0054C119.688 16.7952 122.226 19.4953 126.022 19.4953ZM122.606 11.6553C122.772 10.1631 124.124 8.90775 125.904 8.90775C127.612 8.90775 129.012 9.97361 129.154 11.6553H122.606Z',
  'M108.916 19.4756H106.116V9.52756H103.934V7.06422H108.916V19.4756Z',
  'M107.309 5.34169C108.329 5.34169 109.088 4.58374 109.088 3.58893C109.088 2.61781 108.329 1.85986 107.309 1.85986C106.288 1.85986 105.529 2.61781 105.529 3.58893C105.529 4.58374 106.288 5.34169 107.309 5.34169Z',
]
const LOGO_MARK_PATHS = [
  'M24.4429 16.4322V21.9096H10.7519C6.76318 21.9096 3.28044 19.7067 1.4171 16.4322C1.14622 15.9561 0.909137 15.4567 0.710264 14.9383C0.319864 13.9225 0.0744552 12.8325 0 11.6952V10.2143C0.0161646 9.96089 0.0416361 9.70942 0.0749451 9.46095C0.143032 8.95105 0.245898 8.45211 0.381093 7.96711C1.66006 3.36909 5.81877 0 10.7519 0C15.6851 0 19.8433 3.36909 21.1223 7.96711H15.2682C14.3072 6.4683 12.6437 5.4774 10.7519 5.4774C8.86017 5.4774 7.19668 6.4683 6.23562 7.96711C5.9427 8.42274 5.71542 8.92516 5.56651 9.46095C5.43425 9.93599 5.36371 10.4369 5.36371 10.9548C5.36371 12.5248 6.01324 13.94 7.05463 14.9383C8.01961 15.865 9.32061 16.4322 10.7519 16.4322H24.4429Z',
  'M24.4429 9.46094V14.9383H14.4492C15.4906 13.94 16.1401 12.5248 16.1401 10.9548C16.1401 10.4369 16.0696 9.93598 15.9373 9.46094H24.4429Z',
]

const MARGIN = 48
const FOOTER_Y = 810

const c = (color: string): Rgb => hex(color)

/** Draw the full logo with its top-left at (x, y), `height` points tall. */
function drawLogo(doc: PdfDocument, x: number, y: number, height: number) {
  const scale = height / LOGO_VIEWBOX.height
  for (const path of LOGO_WORDMARK_PATHS) doc.svgPath(path, x, y, scale, c(COLORS.ink))
  for (const path of LOGO_MARK_PATHS) doc.svgPath(path, x, y, scale, c(COLORS.pink))
  return (LOGO_VIEWBOX.width * height) / LOGO_VIEWBOX.height
}

// ─── Formatting ─────────────────────────────────────────────────────────────

function formatDate(value: Date, withYear = true) {
  return value.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    ...(withYear ? { year: 'numeric' } : {}),
  })
}

function formatRange(startAt: string, endAt: string) {
  const start = new Date(startAt)
  const end = new Date(endAt)
  const sameYear = start.getFullYear() === end.getFullYear()
  return `${formatDate(start, !sameYear)} – ${formatDate(end)}`
}

function percentChange(current?: number | null, previous?: number | null) {
  if (current == null || previous == null) return undefined
  if (previous <= 0) return current > 0 ? 100 : 0
  return ((current - previous) / previous) * 100
}

function classifyTraffic(value?: string) {
  const v = value?.trim().toLowerCase() ?? ''
  if (!v) return 'unclassified'
  return v.includes('human') ? 'human' : 'bot'
}

function humanizeCategory(value: string) {
  return value
    .split(/[_\-\s]+/)
    .filter(Boolean)
    .map((word, index) =>
      ['ai', 'seo', 'api', 'rss', 'llm'].includes(word.toLowerCase())
        ? word.toUpperCase()
        : index === 0
          ? word.charAt(0).toUpperCase() + word.slice(1).toLowerCase()
          : word.toLowerCase(),
    )
    .join(' ')
}

/** Small uppercase label (meta row, table column headers). */
function label(doc: PdfDocument, text: string, x: number, y: number, align: 'left' | 'right' = 'left') {
  doc.text(text.toUpperCase(), x, y, {
    size: 6.5,
    font: 'bold',
    color: c(COLORS.subtle),
    align,
    tracking: 0.5,
  })
}

// ─── Sections ───────────────────────────────────────────────────────────────

/** Logo, title, one line of context, separator. */
function drawHeader(doc: PdfDocument, data: AnalyticsExportData) {
  drawLogo(doc, MARGIN, 44, 20)

  doc.text(data.property.name || 'Analytics property', MARGIN, 112, {
    size: 26,
    font: 'heading',
    color: c(COLORS.ink),
    maxWidth: doc.width - MARGIN * 2,
  })
  const subtitle = [data.property.domain, formatRange(data.range.startAt, data.range.endAt)]
    .filter(Boolean)
    .join('  ·  ')
  doc.text(subtitle, MARGIN, 132, {
    size: 10,
    color: c(COLORS.muted),
    maxWidth: doc.width - MARGIN * 2,
  })

  doc.line(MARGIN, 152, doc.width - MARGIN, 152, { color: c(COLORS.border), width: 0.75 })
  return 152
}

function drawMeta(doc: PdfDocument, data: AnalyticsExportData, y: number) {
  const items: [string, string][] = [
    [
      'Compared with',
      data.comparisonRange
        ? formatRange(data.comparisonRange.startAt, data.comparisonRange.endAt)
        : 'No comparison',
    ],
    ['Granularity', data.interval === '1h' ? 'Hourly' : 'Daily'],
    ['Filters', data.filterLabels.length ? data.filterLabels.join(', ') : 'None'],
  ]
  const widths = [0.3, 0.2, 0.5].map((share) => (doc.width - MARGIN * 2) * share)
  let x = MARGIN
  items.forEach(([title, value], index) => {
    label(doc, title, x, y)
    doc.text(value, x, y + 14, {
      size: 9,
      color: c(COLORS.ink),
      maxWidth: widths[index] - 12,
    })
    x += widths[index]
  })
  return y + 30
}

type Kpi = {
  label: string
  value: string
  change?: number
  invert?: boolean
}

function drawKpis(doc: PdfDocument, data: AnalyticsExportData, y: number) {
  const stats = data.stats
  const prev = data.comparisonStats
  const kpis: Kpi[] = stats
    ? [
        { label: 'Unique visitors', value: formatNumber(stats.visitors), change: percentChange(stats.visitors, prev?.visitors) },
        { label: 'Visits', value: formatNumber(stats.visits), change: percentChange(stats.visits, prev?.visits) },
        { label: 'Pageviews', value: formatNumber(stats.pageviews), change: percentChange(stats.pageviews, prev?.pageviews) },
        { label: 'Views per visit', value: formatRatio(stats.viewsPerVisit), change: percentChange(stats.viewsPerVisit, prev?.viewsPerVisit) },
        { label: 'Bounce rate', value: formatPercent(stats.bounceRate), change: percentChange(stats.bounceRate, prev?.bounceRate), invert: true },
        { label: 'Visit duration', value: formatDuration(stats.visitDuration), change: percentChange(stats.visitDuration, prev?.visitDuration) },
        { label: 'Engagement time', value: formatDuration(stats.engagementTime), change: percentChange(stats.engagementTime, prev?.engagementTime) },
        { label: 'Scroll depth', value: formatPercent(stats.scrollDepth), change: percentChange(stats.scrollDepth, prev?.scrollDepth) },
      ]
    : []

  if (kpis.length === 0) {
    doc.rect(MARGIN, y, doc.width - MARGIN * 2, 40, { fill: c(COLORS.surface), stroke: c(COLORS.border), radius: 6 })
    doc.text('Summary metrics are not available with a page or event filter.', MARGIN + 14, y + 24, {
      size: 9,
      color: c(COLORS.muted),
    })
    return y + 52
  }

  const gap = 10
  const columns = 4
  const tileWidth = (doc.width - MARGIN * 2 - gap * (columns - 1)) / columns
  const tileHeight = 60
  kpis.forEach((kpi, index) => {
    const x = MARGIN + (index % columns) * (tileWidth + gap)
    const top = y + Math.floor(index / columns) * (tileHeight + gap)
    doc.rect(x, top, tileWidth, tileHeight, { fill: c(COLORS.surface), stroke: c(COLORS.border), radius: 6 })
    doc.text(kpi.label, x + 12, top + 19, { size: 8, color: c(COLORS.muted), maxWidth: tileWidth - 24 })
    doc.text(kpi.value, x + 12, top + 45, { size: 18, font: 'heading', color: c(COLORS.ink) })
    if (data.comparisonRange && kpi.change !== undefined) {
      const rounded = Math.round(kpi.change * 10) / 10
      const good = kpi.invert ? rounded < 0 : rounded > 0
      const bad = kpi.invert ? rounded > 0 : rounded < 0
      doc.text(`${rounded > 0 ? '+' : ''}${rounded}%`, x + tileWidth - 12, top + 45, {
        size: 8.5,
        font: 'bold',
        color: c(good ? COLORS.good : bad ? COLORS.bad : COLORS.subtle),
        align: 'right',
      })
    }
  })
  return y + Math.ceil(kpis.length / columns) * (tileHeight + gap) + 4
}

function sectionTitle(doc: PdfDocument, text: string, x: number, y: number) {
  doc.text(text, x, y, { size: 12, font: 'heading', color: c(COLORS.ink) })
}

function niceMax(value: number) {
  if (value <= 0) return 1
  const exponent = Math.pow(10, Math.floor(Math.log10(value)))
  const fraction = value / exponent
  const nice = fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10
  return nice * exponent
}

function drawChart(doc: PdfDocument, data: AnalyticsExportData, y: number) {
  const width = doc.width - MARGIN * 2
  sectionTitle(doc, 'Visitors over time', MARGIN, y + 10)

  // Legend, right-aligned.
  let legendX = doc.width - MARGIN
  if (data.comparisonRange) {
    const text = data.compareLabel ?? 'Comparison'
    doc.text(text, legendX, y + 10, { size: 8, color: c(COLORS.muted), align: 'right' })
    legendX -= doc.measure(text, 8) + 6
    doc.line(legendX - 12, y + 7, legendX, y + 7, { color: c(COLORS.subtle), width: 1.2, dash: [2.5, 2] })
    legendX -= 24
  }
  doc.text('Visitors', legendX, y + 10, { size: 8, color: c(COLORS.muted), align: 'right' })
  legendX -= doc.measure('Visitors', 8) + 6
  doc.rect(legendX - 7, y + 4, 7, 7, { fill: c(COLORS.pink), radius: 2 })

  const top = y + 26
  const height = 150
  const axisWidth = 30
  const plotLeft = MARGIN + axisWidth
  const plotWidth = width - axisWidth
  const series = data.series
  const previous = data.comparisonSeries
  const max = niceMax(
    Math.max(
      ...series.map((point) => point.visitors),
      ...previous.map((point) => point.visitors),
      0,
    ),
  )

  // Grid + Y axis.
  for (let step = 0; step <= 4; step++) {
    const gy = top + height - (height * step) / 4
    doc.line(plotLeft, gy, plotLeft + plotWidth, gy, {
      color: c(step === 0 ? COLORS.border : '#F3F3F5'),
      width: 0.6,
    })
    doc.text(formatNumber((max * step) / 4), plotLeft - 6, gy + 3, {
      size: 7,
      color: c(COLORS.subtle),
      align: 'right',
    })
  }

  if (series.length === 0) {
    doc.text('No data in this range', plotLeft + plotWidth / 2, top + height / 2, {
      size: 9,
      color: c(COLORS.subtle),
      align: 'center',
    })
    return top + height + 30
  }

  const xAt = (index: number) =>
    plotLeft + (series.length === 1 ? plotWidth / 2 : (plotWidth * index) / (series.length - 1))
  const yAt = (value: number) => top + height - (height * value) / max

  const points = series.map((point, index): [number, number] => [xAt(index), yAt(point.visitors)])
  doc.polygon(
    [[points[0][0], top + height], ...points, [points[points.length - 1][0], top + height]],
    tint(COLORS.pink, 0.9),
  )
  if (previous.length > 0) {
    doc.polyline(
      previous
        .slice(0, series.length)
        .map((point, index): [number, number] => [xAt(index), yAt(point.visitors)]),
      { color: c(COLORS.subtle), width: 1.1, dash: [3, 2.5] },
    )
  }
  doc.polyline(points, { color: c(COLORS.pink), width: 1.6 })

  // X labels: up to 6, evenly spaced.
  const tickCount = Math.min(6, series.length)
  for (let tick = 0; tick < tickCount; tick++) {
    const index =
      tickCount === 1 ? 0 : Math.round((tick * (series.length - 1)) / (tickCount - 1))
    doc.text(series[index].date, xAt(index), top + height + 14, {
      size: 7,
      color: c(COLORS.subtle),
      align: tick === 0 ? 'left' : tick === tickCount - 1 ? 'right' : 'center',
    })
  }
  return top + height + 34
}

function drawTrafficSplit(doc: PdfDocument, data: AnalyticsExportData, y: number) {
  const trafficTypes = data.breakdowns.find((b) => b.dimension === AnalyticsDimension.TrafficType)
  const categories = data.breakdowns.find((b) => b.dimension === AnalyticsDimension.BotCategory)
  if (!trafficTypes || trafficTypes.skipped) return y

  const totals = { human: 0, bot: 0, unclassified: 0 }
  for (const row of trafficTypes.rows) totals[classifyTraffic(row.value)] += row.visitors
  // Unclassified traffic is left out, as on the dashboard.
  const total = totals.human + totals.bot
  if (total <= 0) return y

  const width = doc.width - MARGIN * 2
  sectionTitle(doc, 'Humans and bots', MARGIN, y + 10)
  const humanShare = (totals.human / total) * 100
  const botShare = (totals.bot / total) * 100
  doc.text(`Humans ${humanShare.toFixed(1)}%`, MARGIN, y + 30, { size: 9, font: 'bold', color: c(HUMAN_COLOR) })
  doc.text(`Bots ${botShare.toFixed(1)}%`, MARGIN + width, y + 30, {
    size: 9,
    font: 'bold',
    color: c(COLORS.muted),
    align: 'right',
  })

  const segments: { label: string; value: number; color: string }[] = [
    { label: 'Humans', value: totals.human, color: HUMAN_COLOR },
  ]
  let assigned = 0
  ;(categories?.rows ?? [])
    .filter((row) => isKnownBreakdownValue(row.value) && row.visitors > 0)
    .sort((a, b) => b.visitors - a.visitors)
    .forEach((row, index) => {
      const amount = Math.min(row.visitors, Math.max(0, totals.bot - assigned))
      if (amount <= 0) return
      assigned += amount
      segments.push({
        label: humanizeCategory(row.value!),
        value: amount,
        color: analyticsBotColor(index),
      })
    })
  if (totals.bot - assigned > 0) {
    segments.push({ label: 'Other bots', value: totals.bot - assigned, color: ANALYTICS_BOT_FALLBACK_COLOR })
  }

  const barTop = y + 38
  const barHeight = 8
  doc.rect(MARGIN, barTop, width, barHeight, { fill: c(COLORS.border), radius: 4 })
  let x = MARGIN
  segments.forEach((segment, index) => {
    const w = Math.max(1.5, (segment.value / total) * width)
    const isFirst = index === 0
    const isLast = index === segments.length - 1
    doc.rect(x, barTop, Math.min(w, MARGIN + width - x), barHeight, {
      fill: c(segment.color),
      // Only the bar's outer ends are round; joins between segments are flat.
      radius: 4,
      roundLeft: isFirst,
      roundRight: isLast,
    })
    x += w
  })

  // Legend for the bot side.
  let legendX = MARGIN
  const legendY = barTop + barHeight + 16
  for (const segment of segments.slice(1)) {
    const text = `${segment.label} ${((segment.value / total) * 100).toFixed(1)}%`
    const itemWidth = doc.measure(text, 8) + 18
    if (legendX + itemWidth > MARGIN + width) break
    doc.rect(legendX, legendY - 6.5, 7, 7, { fill: c(segment.color), radius: 1.5 })
    doc.text(text, legendX + 11, legendY, { size: 8, color: c(COLORS.muted) })
    legendX += itemWidth
  }
  return legendY + 20
}

const TABLE_ROWS = 10
const ROW_HEIGHT = 15

function tableHeight(rows: number) {
  return 36 + Math.max(1, rows) * ROW_HEIGHT + 10
}

function drawTable(
  doc: PdfDocument,
  breakdown: ExportBreakdown,
  x: number,
  y: number,
  width: number,
  formatLabel: (dimension: AnalyticsDimension, value: string) => string,
) {
  const rows = breakdown.rows.slice(0, TABLE_ROWS)
  const height = tableHeight(rows.length)
  doc.rect(x, y, width, height, { stroke: c(COLORS.border), radius: 6, fill: [1, 1, 1] })
  doc.text(breakdown.label, x + 12, y + 20, { size: 10.5, font: 'heading', color: c(COLORS.ink) })
  label(doc, 'Visitors', x + width - 12, y + 20, 'right')
  label(doc, 'Share', x + width - 64, y + 20, 'right')

  const total = breakdown.rows.reduce((sum, row) => sum + row.visitors, 0)
  // Not rows[0]: some tables (events) aren't ranked by visitors, and a row
  // bigger than the first would draw past the table edge.
  const max = rows.reduce((m, row) => Math.max(m, row.visitors), 0)
  if (rows.length === 0) {
    doc.text('No data in this range', x + 12, y + 46, { size: 8.5, color: c(COLORS.subtle) })
    return height
  }
  rows.forEach((row, index) => {
    const rowTop = y + 30 + index * ROW_HEIGHT
    const maxBarWidth = width - 16
    const barWidth = max > 0 ? (maxBarWidth * row.visitors) / max : 0
    doc.rect(x + 8, rowTop, Math.min(maxBarWidth, Math.max(2, barWidth)), ROW_HEIGHT - 2, {
      fill: tint(COLORS.pink, 0.94),
      radius: 3,
    })
    const text = row.value ? formatLabel(breakdown.dimension, row.value) : 'Unknown'
    doc.text(text, x + 12, rowTop + 9.5, { size: 8, color: c(COLORS.ink), maxWidth: width - 100 })
    doc.text(
      total > 0 ? `${Math.round((row.visitors / total) * 100)}%` : '0%',
      x + width - 64,
      rowTop + 9.5,
      { size: 8, color: c(COLORS.muted), align: 'right' },
    )
    doc.text(formatNumber(row.visitors), x + width - 12, rowTop + 9.5, {
      size: 8,
      font: 'bold',
      color: c(COLORS.ink),
      align: 'right',
    })
  })
  return height
}

/** Tables in the report, in reading order (the CSV export has all of them). */
const REPORT_TABLES: AnalyticsDimension[] = [
  AnalyticsDimension.ReferrerSource,
  AnalyticsDimension.Channel,
  AnalyticsDimension.Page,
  AnalyticsDimension.EntryPage,
  AnalyticsDimension.Country,
  AnalyticsDimension.City,
  AnalyticsDimension.Browser,
  AnalyticsDimension.Device,
  AnalyticsDimension.OperatingSystem,
  AnalyticsDimension.EventName,
  AnalyticsDimension.UtmCampaign,
  AnalyticsDimension.BotName,
]

/** Hairline, export timestamp and page number on every page. */
function drawFooters(doc: PdfDocument, data: AnalyticsExportData) {
  const total = doc.pageCount
  const exported = data.generatedAt.toLocaleString('en-US', {
    dateStyle: 'medium',
    timeStyle: 'short',
  })
  for (let page = 0; page < total; page++) {
    doc.usePage(page)
    doc.line(MARGIN, FOOTER_Y - 14, doc.width - MARGIN, FOOTER_Y - 14, { color: c(COLORS.border), width: 0.6 })
    doc.text(`Exported ${exported}`, MARGIN, FOOTER_Y, { size: 7.5, color: c(COLORS.subtle) })
    doc.text(`${page + 1} / ${total}`, doc.width - MARGIN, FOOTER_Y, {
      size: 7.5,
      color: c(COLORS.subtle),
      align: 'right',
    })
  }
}

/** Compact header on continuation pages: logo, context, separator. */
function drawContinuationHeader(doc: PdfDocument, data: AnalyticsExportData) {
  drawLogo(doc, MARGIN, 40, 14)
  doc.text(
    `${data.property.name || 'Analytics property'}  ·  ${formatRange(data.range.startAt, data.range.endAt)}`,
    doc.width - MARGIN,
    51,
    { size: 8.5, color: c(COLORS.muted), align: 'right', maxWidth: doc.width - MARGIN * 2 - 120 },
  )
  doc.line(MARGIN, 68, doc.width - MARGIN, 68, { color: c(COLORS.border), width: 0.75 })
  return 88
}

export async function buildAnalyticsReportPdf(
  data: AnalyticsExportData,
  formatLabel: (dimension: AnalyticsDimension, value: string) => string = (_d, v) => v,
  fonts: DocumentFonts = {},
): Promise<Blob> {
  const doc = new PdfDocument(
    `${data.property.name} · Analytics · ${formatRange(data.range.startAt, data.range.endAt)}`,
    fonts,
  )

  let y = drawHeader(doc, data)
  y = drawMeta(doc, data, y + 26)
  y = drawKpis(doc, data, y + 8)
  y = drawChart(doc, data, y + 14)
  y = drawTrafficSplit(doc, data, y)

  // Two-column flow of top-10 tables.
  const gap = 14
  const columnWidth = (doc.width - MARGIN * 2 - gap) / 2
  const bottomLimit = FOOTER_Y - 30
  const tables = REPORT_TABLES.map((dimension) =>
    data.breakdowns.find((breakdown) => breakdown.dimension === dimension),
  )
    // Same rules as the dashboard cards: no unknown rows, and custom events
    // only (automatic tracker events are covered by the metrics). The CSV
    // export keeps every row.
    .map((breakdown) =>
      breakdown
        ? {
            ...breakdown,
            ...(breakdown.dimension === AnalyticsDimension.EventName
              ? { label: 'Custom events' }
              : {}),
            rows: breakdown.rows.filter(
              (row) =>
                isKnownBreakdownValue(row.value) &&
                (breakdown.dimension !== AnalyticsDimension.EventName ||
                  isCustomAnalyticsEvent(row.value)),
            ),
          }
        : breakdown,
    )
    .filter(
    (breakdown): breakdown is ExportBreakdown =>
      !!breakdown && !breakdown.skipped && breakdown.rows.length > 0,
  )

  // Page 1 is the overview; breakdowns always start on their own page, so the
  // break reads as deliberate instead of leaving a gap mid-page.
  if (tables.length > 0) {
    doc.addPage()
    y = drawContinuationHeader(doc, data)
    sectionTitle(doc, 'Top breakdowns', MARGIN, y + 4)
    y += 20
  }
  for (let index = 0; index < tables.length; index += 2) {
    const pair = tables.slice(index, index + 2)
    const rowHeight = Math.max(
      ...pair.map((table) => tableHeight(Math.min(TABLE_ROWS, table.rows.length))),
    )
    if (y + rowHeight > bottomLimit) {
      doc.addPage()
      y = drawContinuationHeader(doc, data)
    }
    pair.forEach((table, column) => {
      drawTable(doc, table, MARGIN + column * (columnWidth + gap), y, columnWidth, formatLabel)
    })
    y += rowHeight + gap
  }

  drawFooters(doc, data)
  return await doc.toBlob()
}
