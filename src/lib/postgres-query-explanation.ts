import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import type { DedicatedDatabaseQueryExplanation } from '@/lib/databases/dedicated-engine'
import {
  formatPostgresExecutionCellValue,
  normalizePostgresExecutionResult,
} from '@/lib/postgres-execution-values'
import { executionResultRows, peelLeadingPostgresSqlComments } from '@/lib/postgres-sql'

export function preparePostgresQueryForExplanation(sql: string): {
  query: string
  analyze: boolean
} {
  const { leadingComments, sqlWithoutLeadingComments } =
    peelLeadingPostgresSqlComments(sql.trim())
  let text = sqlWithoutLeadingComments.trim()
  let analyze = false

  const explainMatch = text.match(/^explain\s+/i)
  if (!explainMatch) {
    return {
      query: leadingComments
        ? `${leadingComments}\n${text}`.trim()
        : text,
      analyze: false,
    }
  }

  text = text.slice(explainMatch[0].length).trimStart()

  const parenMatch = text.match(/^\(([^)]*)\)\s*/i)
  if (parenMatch) {
    const options = parenMatch[1].toLowerCase()
    analyze = /\banalyze\b/.test(options)
    text = text.slice(parenMatch[0].length).trimStart()
  } else {
    if (/^analyze\b/i.test(text)) {
      analyze = true
      text = text.replace(/^analyze\b/i, '').trimStart()
    }

    text = text.replace(/^verbose\b/i, '').trimStart()
    text = text.replace(/^format\s+\w+\b/i, '').trimStart()
  }

  const query = text.trim()
  return {
    query: leadingComments ? `${leadingComments}\n${query}`.trim() : query,
    analyze,
  }
}

function buildPostgresExplainSql(query: string, analyze?: boolean): string {
  const prepared = preparePostgresQueryForExplanation(query)
  const shouldAnalyze = analyze ?? prepared.analyze
  const options = ['FORMAT JSON']
  if (shouldAnalyze) {
    options.push('ANALYZE', 'VERBOSE', 'BUFFERS')
  }
  return `EXPLAIN (${options.join(', ')}) ${prepared.query}`
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function parseExplainPlanValue(value: unknown): Record<string, unknown>[] {
  if (Array.isArray(value)) {
    return value.filter(isPlainObject)
  }

  if (typeof value === 'string') {
    const trimmed = value.trim()
    if (!trimmed) return []
    try {
      const parsed: unknown = JSON.parse(trimmed)
      return parseExplainPlanValue(parsed)
    } catch {
      return []
    }
  }

  if (isPlainObject(value)) {
    return [value]
  }

  return []
}

function parseExplainExecution(
  execution: Models.DedicatedDatabaseExecution,
): DedicatedDatabaseQueryExplanation {
  const rows = executionResultRows<Record<string, unknown>>(execution)
  if (rows.length === 0) {
    throw new Error('No query plan returned.')
  }

  const firstRow = rows[0]
  const planColumnKey = Object.keys(firstRow).find(
    (key) => key.toLowerCase() === 'query plan',
  )
  const planValue = planColumnKey ? firstRow[planColumnKey] : Object.values(firstRow)[0]
  const plan = parseExplainPlanValue(planValue)
  const raw =
    typeof planValue === 'string'
      ? planValue
      : plan.length > 0
        ? JSON.stringify(plan, null, 2)
        : formatPostgresExecutionCellValue(planValue)

  if (plan.length === 0 && !raw.trim()) {
    throw new Error('No query plan returned.')
  }

  return { plan, raw }
}

export async function explainPostgresDatabaseQuery(
  projectId: string,
  databaseId: string,
  query: string,
  analyze?: boolean,
): Promise<DedicatedDatabaseQueryExplanation> {
  const execution = await sdk.forProject(projectId).postgresql.createExecution({
    databaseId,
    sql: buildPostgresExplainSql(query, analyze),
  })

  return parseExplainExecution(normalizePostgresExecutionResult(execution))
}
