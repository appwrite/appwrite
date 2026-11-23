import { MARKETING_SITE_ORIGIN } from '@/lib/marketing/urls'
import {
  DATABASE_PRICING_COMPARISON_ROWS,
  DEDICATED_DATABASE_PRICING_TIERS,
} from './dedicated-databases'
import { comparisonTables } from './comparison-data'
import {
  getPricingPlanColumns,
  getVisiblePricingPlans,
  START_PLAN_ELIGIBLE_COUNTRIES,
  withStartComparisonValues,
} from './start-plan'
import type { ComparisonCell, PricingPlan } from './types'

function cell(value: ComparisonCell | undefined, origin: string): string {
  if (value === undefined) return '-'
  if (value === true) return 'Yes'
  if (typeof value === 'string') {
    return value.trim().replaceAll('|', '\\|').replaceAll('\n', ' ')
  }
  const href = value.href.startsWith('/')
    ? `${origin}${value.href}`
    : value.href
  return `[${value.text}](${href})`
}

function plan(entry: PricingPlan): string[] {
  const price = [entry.pricePrefix, entry.price, entry.priceSuffix]
    .filter(Boolean)
    .join(' ')
    .replace(' /', '/')
  const lines = [`### ${entry.name}: ${price}`, '', entry.description, '']
  if (entry.callout) lines.push(entry.callout, '')
  if (entry.featuresIntro) lines.push(entry.featuresIntro, '')
  lines.push(...entry.features.map((feature) => `- ${feature}`), '')
  if (entry.footnote) lines.push(entry.footnote, '')
  return lines
}

/** Markdown twin of /pricing, built from the same data as the page. */
export function buildPricingMarkdown(
  origin: string = MARKETING_SITE_ORIGIN,
): string {
  const columns = getPricingPlanColumns(true)
  const header = `| Feature | ${columns.map((column) => column.label).join(' | ')} |`
  const divider = `| --- | ${columns.map(() => '---').join(' | ')} |`

  const lines = [
    '# Appwrite Cloud pricing',
    '',
    `> Plans and usage pricing for Appwrite Cloud. Prices are in USD. Human-readable page: ${origin}/pricing`,
    '',
    '## Plans',
    '',
    `The Start plan is available in ${START_PLAN_ELIGIBLE_COUNTRIES.join(', ')} (ISO 3166-1 country codes).`,
    '',
    ...getVisiblePricingPlans(true).flatMap(plan),
    '## Compare plans',
    '',
  ]

  for (const table of withStartComparisonValues(comparisonTables)) {
    lines.push(`### ${table.title}`, '', header, divider)
    for (const row of table.rows) {
      const values = columns.map((column) => cell(row[column.id], origin))
      lines.push(`| ${cell(row.title, origin)} | ${values.join(' | ')} |`)
    }
    lines.push('')
  }

  lines.push(
    '## Database pricing',
    '',
    '| | Serverless | Dedicated compute |',
    '| --- | --- | --- |',
    ...DATABASE_PRICING_COMPARISON_ROWS.map(
      (row) =>
        `| ${cell(row.label, origin)} | ${cell(row.serverless, origin)} | ${cell(row.dedicated, origin)} |`,
    ),
    '',
    '### Dedicated compute tiers',
    '',
    '| Tier | CPU | Memory | Storage | Connections | Price |',
    '| --- | --- | --- | --- | --- | --- |',
    ...DEDICATED_DATABASE_PRICING_TIERS.map(
      (tier) =>
        `| ${tier.label} | ${tier.cpu} | ${tier.memory} | ${tier.storage} | ${tier.connections} | ${tier.price} |`,
    ),
    '',
    '## Learn more',
    '',
    `- [Billing documentation](${origin}/docs/advanced/billing)`,
    `- [Contact sales for Enterprise](${origin}/enterprise)`,
    '',
  )

  return lines.join('\n')
}
