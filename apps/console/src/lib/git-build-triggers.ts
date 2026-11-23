export type ResourceWithBuildTriggers = {
  installationId?: string | null
  providerRepositoryId?: string | null
  providerBranch?: string | null
  providerRootDirectory?: string | null
  providerBranches?: string[]
  providerPaths?: string[]
}

export type TriggerResourceKind = 'function' | 'site'

export type TriggerValidationField = 'branches' | 'paths'

export type TriggerValidationIssue = {
  field: TriggerValidationField
  message: string
}

export type TriggerExample = {
  label: string
  pattern: string
  hint?: string
}

export function hasGitRepository(
  resource: Pick<
    ResourceWithBuildTriggers,
    'installationId' | 'providerRepositoryId'
  >,
): boolean {
  return Boolean(resource.installationId && resource.providerRepositoryId)
}

export function normalizeTriggerPatterns(patterns: string[] = []): string[] {
  return [...new Set(patterns.map((pattern) => pattern.trim()).filter(Boolean))]
}

export function triggerPatternsEqual(a: string[], b: string[]): boolean {
  const left = normalizeTriggerPatterns(a).sort()
  const right = normalizeTriggerPatterns(b).sort()
  if (left.length !== right.length) return false
  return left.every((value, index) => value === right[index])
}

export function getResourceBuildTriggers(
  resource: ResourceWithBuildTriggers,
): { providerBranches: string[]; providerPaths: string[] } {
  return {
    providerBranches: normalizeTriggerPatterns(resource.providerBranches),
    providerPaths: normalizeTriggerPatterns(resource.providerPaths),
  }
}

export function mergeTriggerPatterns(
  existing: string[],
  toAdd: string[],
): string[] {
  return normalizeTriggerPatterns([...existing, ...toAdd])
}

function formatPatternList(patterns: string[]): string {
  if (patterns.length === 0) return ''
  const quoted = patterns.map((pattern) => `"${pattern}"`)
  if (quoted.length <= 3) return quoted.join(', ')
  return `${quoted.slice(0, 3).join(', ')}, and ${patterns.length - 3} more`
}

function isWildcardPattern(pattern: string): boolean {
  return pattern.includes('*') || pattern.includes('?') || pattern.includes('[')
}

/**
 * Trigger patterns as the server's Globstar matches them. Exclusions alone
 * leave everything else matching. Otherwise a literal inclusion always
 * matches, and among the other patterns the last one that matches decides,
 * so an exclusion before any inclusion changes nothing. An exclusion is the
 * text after its `!`, untrimmed, as the server reads it.
 */
function triggerRules(patterns: string[]): {
  excludedOnly: string[] | null
  literals: string[]
  steps: { exclude: boolean; patterns: string[] }[]
} {
  const normalized = normalizeTriggerPatterns(patterns)
  if (normalized.every((pattern) => pattern.startsWith('!'))) {
    return {
      excludedOnly: normalized.map((pattern) => pattern.slice(1)),
      literals: [],
      steps: [],
    }
  }

  // Without exclusions every inclusion simply matches
  if (!normalized.some((pattern) => pattern.startsWith('!'))) {
    return {
      excludedOnly: null,
      literals: [],
      steps: [{ exclude: false, patterns: normalized }],
    }
  }

  const literals: string[] = []
  const steps: { exclude: boolean; patterns: string[] }[] = []
  for (const pattern of normalized) {
    const exclude = pattern.startsWith('!')
    if (!exclude && !isWildcardPattern(pattern)) {
      literals.push(pattern)
      continue
    }
    if (exclude && steps.length === 0) continue

    const last = steps[steps.length - 1]
    const value = exclude ? pattern.slice(1) : pattern
    if (last && last.exclude === exclude) {
      last.patterns.push(value)
    } else {
      steps.push({ exclude, patterns: [value] })
    }
  }

  return { excludedOnly: null, literals, steps }
}

function describeRules(
  rules: ReturnType<typeof triggerRules>,
  matching: string,
): string {
  const parts = rules.steps.map((step, index) =>
    index === 0
      ? `${matching} ${formatPatternList(step.patterns)}`
      : `${step.exclude ? 'except' : 'but including'} ${formatPatternList(step.patterns)}`,
  )
  if (rules.literals.length > 0) {
    parts.push(
      parts.length === 0
        ? `${matching} ${formatPatternList(rules.literals)}`
        : `and always ${formatPatternList(rules.literals)}`,
    )
  }
  return parts.join(', ')
}

export function describeTriggerBehavior(
  branches: string[],
  paths: string[],
): string {
  const branchRules = triggerRules(branches)
  const pathRules = triggerRules(paths)

  const branchPart =
    branchRules.excludedOnly === null
      ? describeRules(branchRules, 'Only branches matching')
      : branchRules.excludedOnly.length > 0
        ? `All branches except ${formatPatternList(branchRules.excludedOnly)}`
        : 'All branches'

  const pathPart =
    pathRules.excludedOnly === null
      ? describeRules(pathRules, 'only paths matching')
      : pathRules.excludedOnly.length > 0
        ? `changes outside ${formatPatternList(pathRules.excludedOnly)}`
        : 'all file changes'

  return `${branchPart} trigger deployments on ${pathPart}.`
}

function isValidGlobPattern(pattern: string): boolean {
  const trimmed = pattern.trim()
  if (!trimmed) return false

  const negated = trimmed.startsWith('!')
  const core = negated ? trimmed.slice(1).trim() : trimmed
  if (!core) return false
  if (negated && trimmed.startsWith('!!')) return false

  return /^[\w*?/.[\]{}-]+$/.test(core)
}

function findIncludeExcludeConflicts(
  patterns: string[],
  field: TriggerValidationField,
): TriggerValidationIssue[] {
  const includes = new Set<string>()
  const excludes = new Set<string>()

  for (const pattern of patterns) {
    if (pattern.startsWith('!')) {
      excludes.add(pattern.slice(1))
    } else {
      includes.add(pattern)
    }
  }

  const issues: TriggerValidationIssue[] = []
  for (const include of includes) {
    if (excludes.has(include)) {
      issues.push({
        field,
        message: `Pattern "${include}" is both included and excluded.`,
      })
    }
  }
  return issues
}

export function validateTriggerPatterns(
  branches: string[],
  paths: string[],
): TriggerValidationIssue[] {
  const normalizedBranches = normalizeTriggerPatterns(branches)
  const normalizedPaths = normalizeTriggerPatterns(paths)
  const issues: TriggerValidationIssue[] = []

  for (const pattern of normalizedBranches) {
    if (!isValidGlobPattern(pattern)) {
      issues.push({
        field: 'branches',
        message: `Branch pattern "${pattern}" is invalid.`,
      })
    }
  }

  for (const pattern of normalizedPaths) {
    if (!isValidGlobPattern(pattern)) {
      issues.push({
        field: 'paths',
        message: `Path pattern "${pattern}" is invalid.`,
      })
    }
  }

  issues.push(...findIncludeExcludeConflicts(normalizedBranches, 'branches'))
  issues.push(...findIncludeExcludeConflicts(normalizedPaths, 'paths'))

  return issues
}

export function getBranchExamples(
  productionBranch?: string | null,
): TriggerExample[] {
  const branch = productionBranch?.trim() || 'main'
  return [
    { label: 'Production', pattern: branch },
    { label: 'Prefix', pattern: 'feat/*' },
    { label: 'Exclude', pattern: '!draft/*' },
  ]
}

export function getPathExamples(): TriggerExample[] {
  return [
    { label: 'Folder', pattern: 'src/**' },
    { label: 'Monorepo', pattern: 'apps/my-app/**' },
    { label: 'Exclude', pattern: '!docs/**' },
    { label: 'Exclude files', pattern: '!**/*.md' },
  ]
}

export function getPathFilterRootNote(
  rootDirectory?: string | null,
): string | null {
  const normalized = rootDirectory?.trim()
  if (!normalized || normalized === './' || normalized === '.') return null
  return `Paths match from repo root, not ${normalized}.`
}

export function getBranchPlaceholder(productionBranch?: string | null): string {
  const branch = productionBranch?.trim() || 'main'
  return `Enter or comma to add · e.g. ${branch}, feat/*, !draft/*`
}

export function getPathPlaceholder(_kind: TriggerResourceKind): string {
  return 'Enter or comma to add · e.g. src/**, !docs/**'
}
