import type { DedicatedDatabaseQueryExplanation } from '@/lib/databases/dedicated-engine'

export type PostgresQueryPlanNodeVariant =
  | 'index'
  | 'scan'
  | 'join'
  | 'sort'
  | 'aggregate'
  | 'detail'
  | 'other'

export type PostgresQueryPlanMetric = {
  label: string
  value: string
}

export type PostgresQueryPlanNode = {
  id: string
  label: string
  nodeType: string
  details: string[]
  metrics: PostgresQueryPlanMetric[]
  children: PostgresQueryPlanNode[]
  variant: PostgresQueryPlanNodeVariant
}

let planNodeId = 0

function nextPlanNodeId(): string {
  planNodeId += 1
  return `plan-node-${planNodeId}`
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function tryParseJsonValue(value: string): unknown | null {
  const trimmed = value.trim()
  if (!trimmed.startsWith('{') && !trimmed.startsWith('[')) return null
  try {
    return JSON.parse(trimmed) as unknown
  } catch {
    return null
  }
}

function isPsqlExplainWrapper(raw: string): boolean {
  return /^QUERY PLAN\s*$/m.test(raw) || /\(\d+\s+rows?\)\s*$/im.test(raw)
}

function stripPsqlExplainLine(line: string): string {
  return line
    .replace(/\s*\+\s*$/, '')
    .replace(/^\s*\|/, '')
    .replace(/\|\s*$/, '')
    .trim()
}

function extractJsonFromPsqlExplainRaw(raw: string): unknown | null {
  const trimmed = raw.trim()
  if (!trimmed) return null

  const direct = tryParseJsonValue(trimmed)
  if (direct != null) return direct

  const payload = trimmed
    .split('\n')
    .map(stripPsqlExplainLine)
    .filter((line) => {
      if (!line) return false
      if (line === 'QUERY PLAN') return false
      if (/^[-─]+$/.test(line)) return false
      if (/^\(\d+\s+rows?\)$/i.test(line)) return false
      return true
    })
    .join('')

  if (payload) {
    const parsed = tryParseJsonValue(payload)
    if (parsed != null) return parsed
  }

  const start = trimmed.search(/[\[{]/)
  const end = Math.max(trimmed.lastIndexOf(']'), trimmed.lastIndexOf('}'))
  if (start >= 0 && end > start) {
    return tryParseJsonValue(trimmed.slice(start, end + 1))
  }

  return null
}

function looksLikeTextExplainPlan(raw: string): boolean {
  if (isPsqlExplainWrapper(raw)) return false
  return (
    /\bcost=[\d.]+\.\.[\d.]+/i.test(raw) &&
    /(?:^|\n)\s*(?:->\s*)?(?:Limit|Seq Scan|Index(?: Only)? Scan|Bitmap|Hash Join|Merge Join|Nested Loop|Sort|Aggregate|Subquery Scan)\b/i.test(
      raw,
    )
  )
}

function resolveExplainPayload(
  explanation: DedicatedDatabaseQueryExplanation,
): unknown | null {
  const structured = extractJsonPlanRoots(explanation.plan)
  if (structured.length > 0) {
    return explanation.plan ?? null
  }

  const raw = explanation.raw?.trim()
  if (!raw) return explanation.plan ?? null

  return extractJsonFromPsqlExplainRaw(raw) ?? explanation.plan ?? null
}

function classifyNodeType(nodeType: string): PostgresQueryPlanNodeVariant {
  const normalized = nodeType.toLowerCase()
  if (normalized.includes('index')) return 'index'
  if (normalized.includes('scan')) return 'scan'
  if (
    normalized.includes('join') ||
    normalized.includes('nested loop') ||
    normalized.includes('hash') ||
    normalized.includes('merge')
  ) {
    return 'join'
  }
  if (normalized.includes('sort')) return 'sort'
  if (
    normalized.includes('aggregate') ||
    normalized.includes('group') ||
    normalized.includes('materialize') ||
    normalized.includes('limit')
  ) {
    return 'aggregate'
  }
  return 'other'
}

function parseCostMetrics(text: string): PostgresQueryPlanMetric[] {
  const metrics: PostgresQueryPlanMetric[] = []
  const costMatch = text.match(
    /\(cost=([\d.]+)\.\.([\d.]+)(?:\s+rows=(\d+))?(?:\s+width=(\d+))?\)/i,
  )
  if (costMatch) {
    metrics.push({
      label: 'Cost',
      value: `${costMatch[1]}..${costMatch[2]}`,
    })
    if (costMatch[3]) {
      metrics.push({ label: 'Rows', value: costMatch[3] })
    }
    if (costMatch[4]) {
      metrics.push({ label: 'Width', value: costMatch[4] })
    }
  }

  const actualTimeMatch = text.match(
    /actual time=([\d.]+)\.\.([\d.]+)\s+rows=(\d+)/i,
  )
  if (actualTimeMatch) {
    metrics.push({
      label: 'Time',
      value: `${actualTimeMatch[1]}..${actualTimeMatch[2]} ms`,
    })
    metrics.push({
      label: 'Actual rows',
      value: actualTimeMatch[3],
    })
  }

  return metrics
}

function stripCostSuffix(text: string): string {
  return text.replace(/\s*\([^)]*\)\s*$/, '').trim()
}

function isExplainMetaLine(text: string): boolean {
  const trimmed = text.trim()
  return (
    trimmed === 'QUERY PLAN' ||
    /^─+$/.test(trimmed) ||
    trimmed.length === 0
  )
}

function isJsonExplainLine(text: string): boolean {
  const trimmed = text.trim()
  return (
    trimmed.startsWith('{') ||
    trimmed.startsWith('[') ||
    trimmed.startsWith('}') ||
    trimmed.startsWith(']') ||
    trimmed === ',' ||
    /^"[^"]+":/.test(trimmed)
  )
}

function isNoiseExplainLine(text: string): boolean {
  const trimmed = text.trim()
  if (!trimmed) return true
  if (trimmed === '(' || trimmed === ')' || trimmed === '+' || trimmed === '[' || trimmed === ']') {
    return true
  }
  if (/^[-─]+$/.test(trimmed)) return true
  return isExplainMetaLine(trimmed) || isJsonExplainLine(trimmed)
}

function isDetailLine(text: string): boolean {
  return /^(Filter|Hash Cond|Merge Cond|Join Filter|Sort Key|Group Key|Output|Batches|Rewritten|Index Cond|Recheck Cond|Sort Method|Sort Space|Planning Time|Execution Time):/i.test(
    text,
  )
}

function parseExplainTextLine(line: string): { indent: number; text: string } {
  const arrowMatch = line.match(/^(\s*)->\s*(.*)$/)
  if (arrowMatch) {
    return {
      indent: arrowMatch[1].length + 2,
      text: arrowMatch[2].trim(),
    }
  }

  const spaceMatch = line.match(/^(\s*)(.+)$/)
  return {
    indent: spaceMatch?.[1].length ?? 0,
    text: spaceMatch?.[2].trim() ?? '',
  }
}

function createTextPlanNode(text: string): PostgresQueryPlanNode {
  const metrics = parseCostMetrics(text)
  const label = stripCostSuffix(text)
  const nodeType = label.split(/\s+on\s+|\s+using\s+/i)[0]?.trim() || label

  return {
    id: nextPlanNodeId(),
    label,
    nodeType,
    details: [],
    metrics,
    children: [],
    variant: isDetailLine(label) ? 'detail' : classifyNodeType(nodeType),
  }
}

function parseExplainTextTree(raw: string): PostgresQueryPlanNode[] {
  const roots: PostgresQueryPlanNode[] = []
  const stack: { indent: number; node: PostgresQueryPlanNode }[] = []

  for (const line of raw.split('\n')) {
    const { indent, text } = parseExplainTextLine(line)
    if (!text || isNoiseExplainLine(text)) continue

    const node = createTextPlanNode(text)

    while (stack.length > 0 && stack[stack.length - 1].indent >= indent) {
      stack.pop()
    }

    if (stack.length === 0) {
      roots.push(node)
    } else {
      stack[stack.length - 1].node.children.push(node)
    }

    if (!isDetailLine(text)) {
      stack.push({ indent, node })
    }
  }

  return roots
}

function readJsonMetric(
  raw: Record<string, unknown>,
  keys: string[],
): string | undefined {
  for (const key of keys) {
    const value = raw[key]
    if (value !== undefined && value !== null) {
      return String(value)
    }
  }
  return undefined
}

function buildJsonPlanMetrics(
  raw: Record<string, unknown>,
): PostgresQueryPlanMetric[] {
  const metrics: PostgresQueryPlanMetric[] = []
  const startupCost = readJsonMetric(raw, ['Startup Cost', 'startup_cost'])
  const totalCost = readJsonMetric(raw, ['Total Cost', 'total_cost'])
  if (startupCost && totalCost) {
    metrics.push({ label: 'Cost', value: `${startupCost}..${totalCost}` })
  }

  const planRows = readJsonMetric(raw, ['Plan Rows', 'plan_rows'])
  if (planRows) metrics.push({ label: 'Rows', value: planRows })

  const planWidth = readJsonMetric(raw, ['Plan Width', 'plan_width'])
  if (planWidth) metrics.push({ label: 'Width', value: planWidth })

  const actualTime = readJsonMetric(raw, [
    'Actual Total Time',
    'actual_total_time',
  ])
  if (actualTime) metrics.push({ label: 'Time', value: `${actualTime} ms` })

  const actualRows = readJsonMetric(raw, ['Actual Rows', 'actual_rows'])
  if (actualRows) metrics.push({ label: 'Actual rows', value: actualRows })

  const actualLoops = readJsonMetric(raw, ['Actual Loops', 'actual_loops'])
  if (actualLoops) metrics.push({ label: 'Loops', value: actualLoops })

  return metrics
}

function buildJsonPlanDetails(raw: Record<string, unknown>): string[] {
  const detailKeys = [
    'Filter',
    'Hash Cond',
    'Merge Cond',
    'Join Filter',
    'Sort Key',
    'Group Key',
    'Index Cond',
    'Recheck Cond',
    'Output',
  ]

  const details: string[] = []
  for (const key of detailKeys) {
    const value = raw[key]
    if (value !== undefined && value !== null && String(value).trim()) {
      details.push(`${key}: ${String(value)}`)
    }
  }
  return details
}

function buildJsonPlanLabel(raw: Record<string, unknown>): {
  label: string
  nodeType: string
} {
  const nodeType = String(raw['Node Type'] ?? raw.node_type ?? 'Plan')
  const relation = raw['Relation Name'] ?? raw.relation_name
  const schema = raw['Schema Name'] ?? raw.schema_name
  const alias = raw.Alias ?? raw.alias
  const indexName = raw['Index Name'] ?? raw.index_name
  const joinType = raw['Join Type'] ?? raw.join_type

  const parts = [joinType ? String(joinType) : null, nodeType]
  if (relation) {
    const qualified = schema ? `${schema}.${relation}` : String(relation)
    parts.push(`on ${qualified}`)
    if (alias && alias !== relation) {
      parts.push(`(${alias})`)
    }
  }
  if (indexName) {
    parts.push(`using ${indexName}`)
  }

  return {
    label: parts.filter(Boolean).join(' '),
    nodeType,
  }
}

function parseJsonPlanNode(raw: Record<string, unknown>): PostgresQueryPlanNode {
  const { label, nodeType } = buildJsonPlanLabel(raw)
  const childPlans = Array.isArray(raw.Plans)
    ? raw.Plans
    : Array.isArray(raw.plans)
      ? raw.plans
      : []

  return {
    id: nextPlanNodeId(),
    label,
    nodeType,
    details: buildJsonPlanDetails(raw),
    metrics: buildJsonPlanMetrics(raw),
    children: childPlans
      .filter(isPlainObject)
      .map((child) => parseJsonPlanNode(child)),
    variant: classifyNodeType(nodeType),
  }
}

function normalizePlanInput(
  plan: DedicatedDatabaseQueryExplanation['plan'] | unknown,
): Record<string, unknown>[] {
  if (!plan) return []

  if (typeof plan === 'string') {
    const parsed = tryParseJsonValue(plan)
    return parsed != null ? normalizePlanInput(parsed) : []
  }

  if (Array.isArray(plan)) {
    return plan.filter(isPlainObject)
  }

  if (isPlainObject(plan)) {
    if (isPlainObject(plan.Plan)) {
      return [plan]
    }

    if (plan['Node Type'] || plan.node_type) {
      return [plan]
    }

    const queryPlan = plan['QUERY PLAN']
    if (queryPlan !== undefined) {
      return normalizePlanInput(queryPlan)
    }

    return [plan]
  }

  return []
}

function extractJsonPlanRoots(
  plan: DedicatedDatabaseQueryExplanation['plan'] | unknown,
): PostgresQueryPlanNode[] {
  const roots: PostgresQueryPlanNode[] = []

  for (const item of normalizePlanInput(plan)) {
    if (isPlainObject(item.Plan)) {
      roots.push(parseJsonPlanNode(item.Plan))
      continue
    }

    if (item['Node Type'] || item.node_type) {
      roots.push(parseJsonPlanNode(item))
    }
  }

  return roots
}

function formatRawPlanForDisplay(
  explanation: DedicatedDatabaseQueryExplanation,
): string {
  const payload = resolveExplainPayload(explanation)
  if (payload != null) {
    try {
      return JSON.stringify(payload, null, 2)
    } catch {
      /* fall through */
    }
  }

  const raw = explanation.raw?.trim()
  if (raw && looksLikeTextExplainPlan(raw)) {
    return raw
  }

  return raw ?? ''
}

export function normalizePostgresQueryPlan(
  explanation: DedicatedDatabaseQueryExplanation,
): PostgresQueryPlanNode[] {
  planNodeId = 0

  const structured = extractJsonPlanRoots(explanation.plan)
  if (structured.length > 0) {
    return structured
  }

  const payload = resolveExplainPayload(explanation)
  if (payload != null) {
    const jsonRoots = extractJsonPlanRoots(payload)
    if (jsonRoots.length > 0) {
      return jsonRoots
    }
  }

  const raw = explanation.raw?.trim()
  if (raw && looksLikeTextExplainPlan(raw)) {
    return parseExplainTextTree(raw)
  }

  return []
}

export function formatPostgresQueryPlanRaw(
  explanation: DedicatedDatabaseQueryExplanation,
): string {
  return formatRawPlanForDisplay(explanation)
}

export function countPostgresQueryPlanNodes(
  nodes: PostgresQueryPlanNode[],
): number {
  return nodes.reduce(
    (total, node) => total + 1 + countPostgresQueryPlanNodes(node.children),
    0,
  )
}
