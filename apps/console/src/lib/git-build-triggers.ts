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

export function describeTriggerBehavior(
  branches: string[],
  paths: string[],
): string {
  const normalizedBranches = normalizeTriggerPatterns(branches)
  const normalizedPaths = normalizeTriggerPatterns(paths)

  const branchPart =
    normalizedBranches.length === 0
      ? 'All branches'
      : `Only branches matching ${formatPatternList(normalizedBranches)}`

  const pathPart =
    normalizedPaths.length === 0
      ? 'all file changes'
      : `only paths matching ${formatPatternList(normalizedPaths)}`

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
