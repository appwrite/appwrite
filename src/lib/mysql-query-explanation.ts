import type { Models } from '@appwrite.io/console' // pragma: allowlist secret
import { sdk } from '@/lib/appwrite/sdk' // pragma: allowlist secret
import type { DedicatedDatabaseQueryExplanation } from '@/lib/databases/dedicated-engine'
import {
  formatMysqlExecutionCellValue,
  normalizeMysqlExecutionResult,
} from '@/lib/mysql-execution-values'
import { executionResultRows, peelLeadingMysqlSqlComments } from '@/lib/mysql-sql'

export function prepareMysqlQueryForExplanation(sql: string): {
  query: string
  analyze: boolean
} {
  const { leadingComments, sqlWithoutLeadingComments } =
    peelLeadingMysqlSqlComments(sql.trim())
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

  if (/^analyze\b/i.test(text)) {
    analyze = true
    text = text.replace(/^analyze\b/i, '').trimStart()
  }

  // Strip optional FORMAT clause left over from a pasted EXPLAIN.
  text = text.replace(/^format\s*=?\s*\w+\b/i, '').trimStart()

  const query = text.trim()
  return {
    query: leadingComments ? `${leadingComments}\n${query}`.trim() : query,
    analyze,
  }
}

function buildMysqlExplainSql(query: string, analyze?: boolean): string {
  const prepared = prepareMysqlQueryForExplanation(query)
  const shouldAnalyze = analyze ?? prepared.analyze
  // MySQL 8+: EXPLAIN FORMAT=JSON. ANALYZE returns a different shape; prefer JSON plan.
  if (shouldAnalyze) {
    return `EXPLAIN ANALYZE FORMAT=JSON ${prepared.query}`
  }
  return `EXPLAIN FORMAT=JSON ${prepared.query}`
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
    // MySQL FORMAT=JSON wraps the plan as { "query_block": ... }
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
  const planColumnKey = Object.keys(firstRow).find((key) => {
    const lower = key.toLowerCase()
    return (
      lower === 'query plan' ||
      lower === 'explain' ||
      lower === 'json' ||
      lower.includes('explain')
    )
  })
  const planValue = planColumnKey ? firstRow[planColumnKey] : Object.values(firstRow)[0]
  const plan = parseExplainPlanValue(planValue)
  const raw =
    typeof planValue === 'string'
      ? planValue
      : plan.length > 0
        ? JSON.stringify(plan, null, 2)
        : formatMysqlExecutionCellValue(planValue)

  if (plan.length === 0 && !raw.trim()) {
    throw new Error('No query plan returned.')
  }

  return { plan, raw }
}

export async function explainMysqlDatabaseQuery(
  projectId: string,
  databaseId: string,
  query: string,
  analyze?: boolean,
): Promise<DedicatedDatabaseQueryExplanation> {
  const execution = await sdk.forProject(projectId).mysql.createExecution({
    databaseId,
    sql: buildMysqlExplainSql(query, analyze),
  })

  return parseExplainExecution(normalizeMysqlExecutionResult(execution))
}
