import { useEffect, useMemo, useState } from 'react'
import { Link } from '@tanstack/react-router'
import { AlertCircle, Info } from 'lucide-react'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { InputTags } from '@/components/ui/input-tags'
import { Label } from '@/components/ui/label'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  describeTriggerBehavior,
  getBranchExamples,
  getBranchPlaceholder,
  getPathExamples,
  getPathFilterRootNote,
  getPathPlaceholder,
  getResourceBuildTriggers,
  hasGitRepository,
  normalizeTriggerPatterns,
  triggerPatternsEqual,
  validateTriggerPatterns,
  type ResourceWithBuildTriggers,
  type TriggerExample,
  type TriggerResourceKind,
  type TriggerValidationIssue,
} from '@/lib/git-build-triggers'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'

interface BuildTriggersCardProps {
  kind: TriggerResourceKind
  resource: ResourceWithBuildTriggers & { $id: string }
  projectId: string
  resourceId: string
  docsLink: string
  onSave: (updates: {
    providerBranches: string[]
    providerPaths: string[]
  }) => void
  isSaving?: boolean
}

function FieldLabel({
  htmlFor,
  children,
  tooltip,
}: {
  htmlFor: string
  children: string
  tooltip: string
}) {
  const t = useT()
  return (
    <Label htmlFor={htmlFor} className="text-[13px] mb-2 block">
      {children}
      <Tooltip>
        <TooltipTrigger asChild>
          <button
            type="button"
            className="inline-flex ms-1.5 align-middle text-muted-foreground hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded"
            aria-label={t('More info')}
          >
            <Info className="h-3.5 w-3.5" />
          </button>
        </TooltipTrigger>
        <TooltipContent side="top" className="max-w-[240px] z-[200] text-[12px]">
          {tooltip}
        </TooltipContent>
      </Tooltip>
    </Label>
  )
}

function PatternExamples({
  examples,
  disabled,
  onPick,
}: {
  examples: TriggerExample[]
  disabled?: boolean
  onPick: (pattern: string) => void
}) {
  const t = useT()
  if (examples.length === 0) return null

  return (
    <div className="mt-2 flex flex-wrap gap-1.5">
      {examples.map((example) => (
        <Tooltip key={example.label}>
          <TooltipTrigger asChild>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-7 text-[12px] font-normal"
              disabled={disabled}
              onClick={() => onPick(example.pattern)}
            >
              {t(example.label)}
            </Button>
          </TooltipTrigger>
          <TooltipContent
            side="top"
            className="max-w-[240px] z-[200] font-mono text-[12px]"
          >
            {example.pattern}
          </TooltipContent>
        </Tooltip>
      ))}
    </div>
  )
}

function fieldIssues(
  issues: TriggerValidationIssue[],
  field: TriggerValidationIssue['field'],
): string | null {
  const messages = issues
    .filter((issue) => issue.field === field)
    .map((issue) => issue.message)
  if (messages.length === 0) return null
  return messages.join(' ')
}

export function BuildTriggersCard({
  kind,
  resource,
  projectId,
  resourceId,
  docsLink,
  onSave,
  isSaving = false,
}: BuildTriggersCardProps) {
  const t = useT()
  const isConfigured = hasGitRepository(resource)
  const productionBranch = resource.providerBranch?.trim() || 'main'
  const pathRootNote = getPathFilterRootNote(resource.providerRootDirectory)

  const gitSettingsTo =
    kind === 'function'
      ? ('/projects/$projectId/functions/$functionId/settings/git' as const)
      : ('/projects/$projectId/sites/$siteId/settings/git' as const)

  const gitSettingsParams =
    kind === 'function'
      ? { projectId, functionId: resourceId }
      : { projectId, siteId: resourceId }

  const saved = useMemo(
    () => getResourceBuildTriggers(resource),
    [resource.providerBranches, resource.providerPaths, resource.$id],
  )

  const [providerBranches, setProviderBranches] = useState<string[]>(
    saved.providerBranches,
  )
  const [providerPaths, setProviderPaths] = useState<string[]>(
    saved.providerPaths,
  )
  const [branchPrefill, setBranchPrefill] = useState<{
    id: number
    value: string
  } | null>(null)
  const [pathPrefill, setPathPrefill] = useState<{
    id: number
    value: string
  } | null>(null)

  useEffect(() => {
    setProviderBranches(saved.providerBranches)
    setProviderPaths(saved.providerPaths)
  }, [saved.providerBranches, saved.providerPaths, resource.$id])

  const hasChanges =
    !triggerPatternsEqual(providerBranches, saved.providerBranches) ||
    !triggerPatternsEqual(providerPaths, saved.providerPaths)

  const validationIssues = useMemo(
    () => validateTriggerPatterns(providerBranches, providerPaths),
    [providerBranches, providerPaths],
  )

  const branchExamples = useMemo(
    () => getBranchExamples(productionBranch),
    [productionBranch],
  )

  const pathExamples = useMemo(() => getPathExamples(), [])

  const behaviorSummary = describeTriggerBehavior(providerBranches, providerPaths)
  const branchFieldError = fieldIssues(validationIssues, 'branches')
  const pathFieldError = fieldIssues(validationIssues, 'paths')

  const handleSave = () => {
    const issues = validateTriggerPatterns(providerBranches, providerPaths)
    if (issues.length > 0) return

    onSave({
      providerBranches: normalizeTriggerPatterns(providerBranches),
      providerPaths: normalizeTriggerPatterns(providerPaths),
    })
  }

  const handleClearAll = () => {
    setProviderBranches([])
    setProviderPaths([])
  }

  const fieldsDisabled = !isConfigured || isSaving
  const hasAnyPatterns =
    providerBranches.length > 0 || providerPaths.length > 0

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Triggers')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('Limit which pushes trigger deployments. Use globs; prefix with')}{' '}
          <span className="font-mono">!</span> {t('to exclude.')}{' '}
          <DocsRouteLink href={docsLink} className="link-neutral">
            {t('Learn more')}
          </DocsRouteLink>
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="space-y-4">
          {!isConfigured ? (
            <div className="rounded-lg border border-border bg-muted/30 px-3 py-2.5">
              <p className="text-[12px] text-muted-foreground">
                {t('Connect a repository in')}{' '}
                <Link
                  to={gitSettingsTo}
                  params={gitSettingsParams}
                  className="link-neutral"
                >
                  {t('Git settings')}
                </Link>
                .
              </p>
            </div>
          ) : (
            <>
              <div className="rounded-lg border border-border bg-muted/30 overflow-hidden">
                <div className="border-b border-border bg-muted/40 px-3.5 py-2">
                  <span className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                    {hasChanges ? t('Preview') : t('Current behavior')}
                  </span>
                </div>
                <div className="space-y-3 px-3.5 py-3">
                  <p className="text-[13px] font-medium text-foreground leading-relaxed">
                    {behaviorSummary}
                  </p>
                  <p className="border-t border-border pt-3 text-[12px] text-muted-foreground">
                    {t('Production')}{' '}
                    <code className="font-mono text-foreground">
                      {productionBranch}
                    </code>
                    {' · '}
                    <Link
                      to={gitSettingsTo}
                      params={gitSettingsParams}
                      className="link-neutral"
                    >
                      Git
                    </Link>
                  </p>
                </div>
              </div>

              {validationIssues.length > 0 ? (
                <Alert
                  variant="destructive"
                  className="border-destructive/30 py-2.5"
                >
                  <AlertCircle className="h-4 w-4" />
                  <AlertDescription className="text-[12px]">
                    <ul className="list-disc ps-4 space-y-1">
                      {validationIssues.map((issue) => (
                        <li key={`${issue.field}-${issue.message}`}>
                          {issue.message}
                        </li>
                      ))}
                    </ul>
                  </AlertDescription>
                </Alert>
              ) : null}
            </>
          )}

          <div>
            <FieldLabel
              htmlFor="provider-branches"
              tooltip={t('Empty = all branches. ! excludes.')}
            >
              {t('Branch filters')}
            </FieldLabel>
            <PatternExamples
              examples={branchExamples}
              disabled={fieldsDisabled}
              onPick={(pattern) =>
                setBranchPrefill({ id: Date.now(), value: pattern })
              }
            />
            <InputTags
              id="provider-branches"
              value={providerBranches}
              onChange={(value) => setProviderBranches(value)}
              disabled={fieldsDisabled}
              splitOnComma
              placeholder={getBranchPlaceholder(productionBranch)}
              prefillRequest={branchPrefill}
              onPrefillConsumed={() => setBranchPrefill(null)}
              className="mt-2"
            />
            {branchFieldError ? (
              <p className="text-[12px] text-destructive mt-1">
                {branchFieldError}
              </p>
            ) : null}
          </div>

          <div>
            <FieldLabel
              htmlFor="provider-paths"
              tooltip={t('Empty = all file changes. ! excludes.')}
            >
              {t('Path filters')}
            </FieldLabel>
            <PatternExamples
              examples={pathExamples}
              disabled={fieldsDisabled}
              onPick={(pattern) =>
                setPathPrefill({ id: Date.now(), value: pattern })
              }
            />
            <InputTags
              id="provider-paths"
              value={providerPaths}
              onChange={(value) => setProviderPaths(value)}
              disabled={fieldsDisabled}
              splitOnComma
              placeholder={getPathPlaceholder(kind)}
              prefillRequest={pathPrefill}
              onPrefillConsumed={() => setPathPrefill(null)}
              className="mt-2"
            />
            {pathFieldError ? (
              <p className="text-[12px] text-destructive mt-1">
                {pathFieldError}
              </p>
            ) : null}
            {pathRootNote ? (
              <p className="text-[12px] text-muted-foreground mt-1">
                {pathRootNote}
              </p>
            ) : null}
          </div>

        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-wrap gap-2">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={
            !isConfigured ||
            !hasChanges ||
            isSaving ||
            validationIssues.length > 0
          }
          onClick={handleSave}
        >
          {t('Update')}
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-9 text-[13px]"
          disabled={fieldsDisabled || !hasAnyPatterns}
          onClick={handleClearAll}
        >
          {t('Clear all')}
        </Button>
      </div>
    </div>
  )
}
