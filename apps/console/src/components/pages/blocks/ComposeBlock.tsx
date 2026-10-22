import { useEffect, useMemo, useState } from 'react'
import { toast } from 'sonner'
import { Loader2, ShieldAlert } from 'lucide-react'
import { BlockMode, BlockResourceType } from '@appwrite.io/console'
import { useBlocks, useCreateBlocks } from '@/lib/react-query/hooks/manager'
import { parseBlockResourceIds } from './parse-resource-ids'
import { DateTimePicker } from '@/components/global/shared/DateTimePicker'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import { formatProjectNameForDisplay } from '@/lib/react-query/hooks/projects'
import {
  BLOCK_MODE_META,
  ORDERED_RESOURCE_TYPES,
  RESOURCE_TYPE_META,
  resourceTypeSupportsReadonly,
} from './resource-type-meta'

type Expiry =
  | { kind: 'never' }
  | { kind: 'preset'; hours: number; label: string }
  | { kind: 'custom'; iso: string | null }

const PRESETS: { hours: number; label: string }[] = [
  { hours: 1, label: '1h' },
  { hours: 24, label: '24h' },
  { hours: 24 * 7, label: '7d' },
  { hours: 24 * 30, label: '30d' },
]

function expiryToIso(e: Expiry): string | undefined {
  if (e.kind === 'never') return undefined
  if (e.kind === 'preset')
    return new Date(Date.now() + e.hours * 3600 * 1000).toISOString()
  if (e.kind === 'custom' && e.iso) return e.iso
  return undefined
}

export function ComposeBlock() {
  const [projectId, setProjectId] = useState('')
  const [projectIdsText, setProjectIdsText] = useState('')
  const [type, setType] = useState<BlockResourceType>(BlockResourceType.Projects)
  const [mode, setMode] = useState<BlockMode>(BlockMode.Full)
  const [bulk, setBulk] = useState(false)
  const [resourceId, setResourceId] = useState('')
  const [resourceIdsText, setResourceIdsText] = useState('')
  const [reason, setReason] = useState('')
  const [expiry, setExpiry] = useState<Expiry>({ kind: 'never' })
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [progress, setProgress] = useState<{ done: number; total: number } | null>(
    null,
  )

  const trimmedProjectId = projectId.trim()
  const blockingProjects = type === BlockResourceType.Projects
  const supportsReadonly = resourceTypeSupportsReadonly(type)

  const createMutation = useCreateBlocks()
  // Name and organization come from this project's block list when confirming
  // a single project. Bulk project blocks skip the lookup.
  const { data: blocksData } = useBlocks(
    confirmOpen && !(blockingProjects && bulk) ? trimmedProjectId : null,
  )
  const meta = blocksData?.blocks?.[0]

  // Read-only is database-only; drop a stale readonly selection when switching
  // to a resource type that only supports full blocks.
  useEffect(() => {
    if (!supportsReadonly) setMode(BlockMode.Full)
  }, [supportsReadonly])

  const parsedBulk = useMemo(
    () => parseBlockResourceIds(resourceIdsText),
    [resourceIdsText],
  )
  const parsedProjects = useMemo(
    () => parseBlockResourceIds(projectIdsText),
    [projectIdsText],
  )
  const targetIds = useMemo(() => {
    if (bulk) return parsedBulk.ids
    const singleId = resourceId.trim()
    return singleId ? [singleId] : []
  }, [bulk, parsedBulk.ids, resourceId])
  const projectTargets = blockingProjects
    ? bulk
      ? parsedProjects.ids
      : trimmedProjectId
        ? [trimmedProjectId]
        : []
    : trimmedProjectId
      ? [trimmedProjectId]
      : []
  const isWildcard = !blockingProjects && !bulk && targetIds.length === 0
  const blockCount = blockingProjects
    ? projectTargets.length
    : bulk
      ? targetIds.length
      : 1

  const payload = useMemo(() => {
    const shared = {
      resourceType: type,
      mode: supportsReadonly ? mode : undefined,
      reason: reason.trim() || undefined,
      expiredAt: expiryToIso(expiry),
    }
    if (blockingProjects && bulk) {
      if (parsedProjects.ids.length === 0) return null
      return { ...shared, projectIds: parsedProjects.ids }
    }
    if (!trimmedProjectId) return null
    if (!blockingProjects && targetIds.length > 1) {
      return { ...shared, projectId: trimmedProjectId, resourceIds: targetIds }
    }
    return {
      ...shared,
      projectId: trimmedProjectId,
      resourceId: blockingProjects ? undefined : targetIds[0],
    }
  }, [
    blockingProjects,
    bulk,
    parsedProjects.ids,
    trimmedProjectId,
    type,
    targetIds,
    mode,
    supportsReadonly,
    reason,
    expiry,
  ])

  const canSubmit =
    !createMutation.isPending &&
    (blockingProjects
      ? projectTargets.length > 0
      : !!trimmedProjectId && (!bulk || targetIds.length > 0))
  const createLabel = blockCount > 1 ? 'Create blocks' : 'Create block'

  const enableBulk = () => {
    setBulk(true)
    if (type === BlockResourceType.Projects) {
      setProjectIdsText((current) => {
        if (current.trim()) return current
        return projectId.trim()
      })
      return
    }
    setResourceIdsText((current) => {
      if (current.trim()) return current
      return resourceId.trim()
    })
  }

  const enableSingle = () => {
    if (type === BlockResourceType.Projects) {
      if (parsedProjects.ids[0]) setProjectId(parsedProjects.ids[0])
    } else if (parsedBulk.ids[0]) {
      setResourceId(parsedBulk.ids[0])
    }
    setBulk(false)
  }

  const handleConfirm = () => {
    if (blockingProjects && bulk) {
      if (parsedProjects.ids.length === 0) return
    } else if (
      !trimmedProjectId ||
      (!blockingProjects && bulk && targetIds.length === 0)
    ) {
      return
    }
    setProgress(blockCount > 1 ? { done: 0, total: blockCount } : null)
    createMutation.mutate(
      blockingProjects && bulk
        ? {
            projectIds: parsedProjects.ids,
            resourceType: type,
            resourceIds: [],
            reason: reason.trim() || undefined,
            expiredAt: expiryToIso(expiry),
            onProgress: (done, total) => setProgress({ done, total }),
          }
        : {
            projectId: trimmedProjectId,
            resourceType: type,
            resourceIds: blockingProjects ? [] : targetIds,
            mode: supportsReadonly ? mode : undefined,
            reason: reason.trim() || undefined,
            expiredAt: expiryToIso(expiry),
            onProgress: (done, total) => setProgress({ done, total }),
          },
      {
        onSuccess: (result) => {
          setProgress(null)
          const { created, failed } = result
          if (failed.length === 0) {
            toast.success(
              created.length === 1
                ? 'Block created'
                : `${created.length} blocks created`,
            )
            setResourceId('')
            setResourceIdsText('')
            setProjectIdsText('')
            setReason('')
            setMode(BlockMode.Full)
            setExpiry({ kind: 'never' })
            setConfirmOpen(false)
            return
          }

          const description =
            failed.length === 1
              ? failed[0]?.message
              : `${failed[0]?.message} (${failed.length - 1} more failed)`

          if (created.length === 0) {
            toast.error(
              failed.length === 1
                ? 'Could not create block'
                : 'Could not create blocks',
              { description },
            )
            return
          }

          toast.warning(
            `Created ${created.length} of ${created.length + failed.length} blocks`,
            { description },
          )
          const failedIds = blockingProjects
            ? failed.map((failure) => failure.projectId).filter(Boolean)
            : failed
                .map((failure) => failure.resourceId)
                .filter((id): id is string => !!id)
          if (failedIds.length > 0) {
            setBulk(true)
            if (blockingProjects) setProjectIdsText(failedIds.join('\n'))
            else setResourceIdsText(failedIds.join('\n'))
          }
          setConfirmOpen(false)
        },
        onError: (e) => {
          setProgress(null)
          toast.error('Could not create block', {
            description: e instanceof Error ? e.message : String(e),
          })
        },
      },
    )
  }

  const typeMeta = RESOURCE_TYPE_META[type]

  return (
    <section className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Create block
        </h3>
        <p className="mt-1 text-[13px] text-muted-foreground">
          Enter one project, many projects, or resources inside a project.
        </p>
      </div>
      <div className="border-t border-border" />

      <form
        className="px-6 py-4 space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          if (canSubmit) setConfirmOpen(true)
        }}
      >
        <div className="space-y-2">
          <Label>Resource type</Label>
          <div className="grid grid-cols-3 gap-1.5">
            {ORDERED_RESOURCE_TYPES.map((rt) => {
              const meta = RESOURCE_TYPE_META[rt]
              const Icon = meta.icon
              const active = type === rt
              return (
                <button
                  key={rt}
                  type="button"
                  onClick={() => setType(rt)}
                  className={cn(
                    'flex items-center gap-1.5 rounded-md border px-2 py-1.5 text-[12px] text-start transition-colors disabled:opacity-50',
                    active
                      ? 'border-foreground/30 bg-accent text-foreground'
                      : 'border-border bg-background text-muted-foreground hover:bg-accent hover:text-foreground',
                  )}
                  title={meta.description}
                >
                  <Icon className="h-3.5 w-3.5 shrink-0" />
                  <span className="truncate">{meta.label}</span>
                </button>
              )
            })}
          </div>
        </div>

        <div className="space-y-2">
          <div className="flex items-center justify-between gap-2">
            <Label htmlFor={blockingProjects && bulk ? 'block-project-ids' : 'block-project-id'}>
              {blockingProjects && bulk ? 'Project IDs' : 'Project ID'}
            </Label>
            {blockingProjects && bulk && parsedProjects.ids.length > 0 && (
              <Badge
                variant="secondary"
                className="h-5 px-1.5 text-[10.5px] font-normal"
              >
                {parsedProjects.ids.length === 1
                  ? '1 project'
                  : `${parsedProjects.ids.length} projects`}
              </Badge>
            )}
          </div>
          {blockingProjects && (
            <div className="flex flex-wrap gap-1.5">
              <ChoiceChip
                active={!bulk}
                label="Single"
                onClick={enableSingle}
              />
              <ChoiceChip active={bulk} label="Bulk" onClick={enableBulk} />
            </div>
          )}
          {blockingProjects && bulk ? (
            <>
              <Textarea
                id="block-project-ids"
                value={projectIdsText}
                onChange={(e) => setProjectIdsText(e.target.value)}
                rows={5}
                placeholder="One project ID per line"
                spellCheck={false}
                autoComplete="off"
                className="min-h-28 resize-y border-border bg-background font-mono text-[12px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
              />
              <p className="text-[11px] text-muted-foreground">
                Each ID is blocked as a whole project. Separate IDs with new lines, commas, or spaces.
                {parsedProjects.duplicateCount > 0 &&
                  ` ${parsedProjects.duplicateCount === 1 ? '1 duplicate' : `${parsedProjects.duplicateCount} duplicates`} will be skipped.`}
              </p>
            </>
          ) : (
            <>
              <Input
                id="block-project-id"
                value={projectId}
                onChange={(e) => setProjectId(e.target.value)}
                placeholder={
                  blockingProjects
                    ? 'Project to block'
                    : 'Project that owns the resource'
                }
                spellCheck={false}
                autoComplete="off"
                className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
              />
              <p className="text-[11px] text-muted-foreground">
                {blockingProjects
                  ? 'Blocks this whole project.'
                  : 'Resource IDs below are blocked in this project.'}
              </p>
            </>
          )}
        </div>

        {supportsReadonly && (
          <div className="space-y-2">
            <Label>Block mode</Label>
            <div className="grid grid-cols-2 gap-1.5">
              {[BlockMode.Full, BlockMode.Readonly].map((m) => {
                const modeMeta = BLOCK_MODE_META[m]
                const ModeIcon = modeMeta.icon
                const active = mode === m
                return (
                  <button
                    key={m}
                    type="button"
                    onClick={() => setMode(m)}
                    className={cn(
                      'flex flex-col gap-0.5 rounded-md border px-2.5 py-2 text-start transition-colors disabled:opacity-50',
                      active
                        ? 'border-foreground/30 bg-accent text-foreground'
                        : 'border-border bg-background text-muted-foreground hover:bg-accent hover:text-foreground',
                    )}
                  >
                    <span className="flex items-center gap-1.5 text-[12px] font-medium">
                      <ModeIcon className="h-3.5 w-3.5 shrink-0" />
                      {modeMeta.label}
                    </span>
                    <span className="text-[11px] text-muted-foreground">
                      {modeMeta.description}
                    </span>
                  </button>
                )
              })}
            </div>
          </div>
        )}

        {!blockingProjects && (
          <div className="space-y-2">
            <div className="flex items-center justify-between gap-2">
              <Label htmlFor={bulk ? 'resource-ids' : 'resource-id'}>
                {bulk ? 'Resource IDs' : 'Resource ID'}
              </Label>
              {!bulk && !resourceId.trim() && (
                <Badge
                  variant="secondary"
                  className="h-5 px-1.5 text-[10.5px] font-normal"
                >
                  All resources
                </Badge>
              )}
              {bulk && parsedBulk.ids.length > 0 && (
                <Badge
                  variant="secondary"
                  className="h-5 px-1.5 text-[10.5px] font-normal"
                >
                  {parsedBulk.ids.length === 1
                    ? '1 resource'
                    : `${parsedBulk.ids.length} resources`}
                </Badge>
              )}
            </div>
            <div className="flex flex-wrap gap-1.5">
              <ChoiceChip
                active={!bulk}
                label="Single"
                onClick={enableSingle}
              />
              <ChoiceChip active={bulk} label="Bulk" onClick={enableBulk} />
            </div>
            {bulk ? (
              <>
                <Textarea
                  id="resource-ids"
                  value={resourceIdsText}
                  onChange={(e) => setResourceIdsText(e.target.value)}
                  rows={5}
                  placeholder="One resource ID per line"
                  spellCheck={false}
                  autoComplete="off"
                  className="min-h-28 resize-y border-border bg-background font-mono text-[12px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                />
                <p className="text-[11px] text-muted-foreground">
                  Separate IDs with new lines, commas, or spaces.
                  {parsedBulk.duplicateCount > 0 &&
                    ` ${parsedBulk.duplicateCount === 1 ? '1 duplicate' : `${parsedBulk.duplicateCount} duplicates`} will be skipped.`}
                </p>
              </>
            ) : (
              <Input
                id="resource-id"
                value={resourceId}
                onChange={(e) => setResourceId(e.target.value)}
                placeholder="Leave empty to block all"
                spellCheck={false}
                autoComplete="off"
                className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
              />
            )}
          </div>
        )}

        <div className="space-y-2">
          <Label htmlFor="reason">Reason (optional)</Label>
          <Textarea
            id="reason"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            rows={2}
            placeholder="Why is this being blocked?"
            className="resize-none border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
          />
        </div>

        <div className="space-y-2">
          <Label>Expiry</Label>
          <div className="flex flex-wrap gap-1.5">
            <ChoiceChip
              active={expiry.kind === 'never'}
              label="Never"
              onClick={() => setExpiry({ kind: 'never' })}
            />
            {PRESETS.map((p) => (
              <ChoiceChip
                key={p.label}
                active={expiry.kind === 'preset' && expiry.hours === p.hours}
                label={p.label}
                onClick={() =>
                  setExpiry({ kind: 'preset', hours: p.hours, label: p.label })
                }
              />
            ))}
            <ChoiceChip
              active={expiry.kind === 'custom'}
              label="Custom"
              onClick={() =>
                setExpiry({
                  kind: 'custom',
                  iso: new Date(Date.now() + 86400000).toISOString(),
                })
              }
            />
          </div>
          {expiry.kind === 'custom' && (
            <DateTimePicker
              value={expiry.iso}
              onChange={(v) => setExpiry({ kind: 'custom', iso: v })}
              clearable={false}
              size="sm"
              className="w-full"
              timeZoneMode="preferred"
            />
          )}
        </div>
      </form>

      <div className="px-6 py-4 border-t border-border bg-muted/30 flex items-center justify-end gap-2">
        <Button
          type="button"
          size="sm"
          variant="destructive"
          className="h-9 text-[13px]"
          disabled={!canSubmit}
          onClick={() => setConfirmOpen(true)}
        >
          {createLabel}
        </Button>
      </div>

      <Dialog
        open={confirmOpen}
        onOpenChange={(open) => {
          if (createMutation.isPending) return
          setConfirmOpen(open)
          if (!open) setProgress(null)
        }}
      >
        <DialogContent
          className="sm:max-w-md"
          overlayClassName="bg-black/60 backdrop-blur-sm"
        >
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <ShieldAlert className="h-4 w-4 text-destructive" />
              {createLabel}
            </DialogTitle>
            <DialogDescription asChild>
              <p>
                {blockingProjects && bulk ? (
                  <>
                    Block{' '}
                    <span className="font-medium text-foreground">
                      {parsedProjects.ids.length === 1
                        ? '1 project'
                        : `${parsedProjects.ids.length} projects`}
                    </span>
                    ? Each project is blocked on its own.
                  </>
                ) : blockingProjects ? (
                  <>
                    Block project{' '}
                    <span
                      className="font-medium text-foreground"
                      title={meta?.projectName || trimmedProjectId}
                    >
                      {formatProjectNameForDisplay(
                        meta?.projectName || trimmedProjectId,
                      )}
                    </span>
                    {meta?.organizationName && (
                      <>
                        {' '}
                        <span className="text-muted-foreground">
                          ({meta.organizationName})
                        </span>
                      </>
                    )}
                    ?
                  </>
                ) : (
                  <>
                    Block{' '}
                    {isWildcard ? (
                      <span className="font-medium text-foreground">
                        all {typeMeta.label}
                      </span>
                    ) : targetIds.length === 1 ? (
                      <>
                        <span className="font-medium text-foreground">
                          {typeMeta.label}
                        </span>{' '}
                        <code className="rounded bg-muted px-1 py-0.5 text-[11.5px] text-foreground">
                          {targetIds[0]}
                        </code>
                      </>
                    ) : (
                      <span className="font-medium text-foreground">
                        {targetIds.length} {typeMeta.label}
                      </span>
                    )}{' '}
                    in{' '}
                    <span
                      className="font-medium text-foreground"
                      title={meta?.projectName || trimmedProjectId}
                    >
                      {formatProjectNameForDisplay(
                        meta?.projectName || trimmedProjectId,
                      )}
                    </span>
                    {meta?.organizationName && (
                      <>
                        {' '}
                        <span className="text-muted-foreground">
                          ({meta.organizationName})
                        </span>
                      </>
                    )}
                    ?
                    {targetIds.length > 1 &&
                      ' Each resource ID gets its own block.'}
                  </>
                )}
              </p>
            </DialogDescription>
          </DialogHeader>

          <pre className="mt-2 max-h-48 overflow-auto rounded-md border border-border bg-muted/40 p-3 text-[11.5px] leading-relaxed text-foreground/90 whitespace-pre-wrap break-all">
            {JSON.stringify(payload ?? {}, null, 2)}
          </pre>

          {progress && progress.total > 1 && (
            <p className="text-[12px] text-muted-foreground">
              Blocking {progress.done} of {progress.total}
            </p>
          )}

          <div className="flex justify-end gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setConfirmOpen(false)}
              disabled={createMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleConfirm}
              disabled={createMutation.isPending}
            >
              {createMutation.isPending && (
                <Loader2 className="h-3.5 w-3.5 animate-spin" />
              )}
              {createLabel}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </section>
  )
}

function ChoiceChip({
  active,
  label,
  onClick,
  disabled,
}: {
  active: boolean
  label: string
  onClick: () => void
  disabled?: boolean
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={cn(
        'rounded-md border px-2.5 py-1 text-[12px] transition-colors disabled:opacity-50',
        active
          ? 'border-foreground/30 bg-accent text-foreground'
          : 'border-border bg-background text-muted-foreground hover:bg-accent hover:text-foreground',
      )}
    >
      {label}
    </button>
  )
}
