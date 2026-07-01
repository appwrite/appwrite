import { useEffect, useMemo, useState } from 'react'
import { toast } from 'sonner'
import { Loader2, ShieldAlert } from 'lucide-react'
import { BlockMode, BlockResourceType } from '@appwrite.io/console'
import { useBlocks, useCreateBlock } from '@/lib/react-query/hooks/manager'
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

export function ComposeBlock({ projectId }: { projectId: string | null }) {
  const [type, setType] = useState<BlockResourceType>(BlockResourceType.Projects)
  const [mode, setMode] = useState<BlockMode>(BlockMode.Full)
  const [resourceId, setResourceId] = useState('')
  const [reason, setReason] = useState('')
  const [expiry, setExpiry] = useState<Expiry>({ kind: 'never' })
  const [confirmOpen, setConfirmOpen] = useState(false)

  const supportsReadonly = resourceTypeSupportsReadonly(type)

  const createMutation = useCreateBlock()
  // Reuses the cached list query populated by BlocksList - gives us the
  // project + org metadata for a friendlier confirm message.
  const { data: blocksData } = useBlocks(projectId)
  const meta = blocksData?.blocks?.[0]

  useEffect(() => {
    if (!projectId) {
      setResourceId('')
      setReason('')
    }
  }, [projectId])

  // Read-only is database-only; drop a stale readonly selection when switching
  // to a resource type that only supports full blocks.
  useEffect(() => {
    if (!supportsReadonly) setMode(BlockMode.Full)
  }, [supportsReadonly])

  const payload = useMemo(() => {
    if (!projectId) return null
    return {
      projectId,
      resourceType: type,
      resourceId: resourceId.trim() || undefined,
      mode: supportsReadonly ? mode : undefined,
      reason: reason.trim() || undefined,
      expiredAt: expiryToIso(expiry),
    }
  }, [projectId, type, resourceId, mode, supportsReadonly, reason, expiry])

  const canSubmit = !!projectId && !createMutation.isPending

  const handleConfirm = () => {
    if (!payload) return
    createMutation.mutate(payload, {
      onSuccess: () => {
        toast.success('Block created')
        setResourceId('')
        setReason('')
        setMode(BlockMode.Full)
        setExpiry({ kind: 'never' })
        setConfirmOpen(false)
      },
      onError: (e) => {
        toast.error('Could not create block', {
          description: e instanceof Error ? e.message : String(e),
        })
      },
    })
  }

  const typeMeta = RESOURCE_TYPE_META[type]

  return (
    <section className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Create block
        </h3>
        <p className="mt-1 text-[13px] text-muted-foreground">
          Block a resource for the selected project.
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
                  disabled={!projectId}
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
                    disabled={!projectId}
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

        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <Label htmlFor="resource-id">Resource ID</Label>
            {!resourceId.trim() && (
              <Badge
                variant="secondary"
                className="h-5 px-1.5 text-[10.5px] font-normal"
              >
                All resources
              </Badge>
            )}
          </div>
          <Input
            id="resource-id"
            value={resourceId}
            onChange={(e) => setResourceId(e.target.value)}
            disabled={!projectId}
            placeholder="Leave empty to block all"
            spellCheck={false}
            autoComplete="off"
            className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
          />
        </div>

        <div className="space-y-2">
          <Label htmlFor="reason">Reason (optional)</Label>
          <Textarea
            id="reason"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            rows={2}
            disabled={!projectId}
            placeholder="Why is this being blocked?"
            className="resize-none border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
          />
        </div>

        <div className="space-y-2">
          <Label>Expiry</Label>
          <div className="flex flex-wrap gap-1.5">
            <ExpiryChip
              active={expiry.kind === 'never'}
              label="Never"
              onClick={() => setExpiry({ kind: 'never' })}
              disabled={!projectId}
            />
            {PRESETS.map((p) => (
              <ExpiryChip
                key={p.label}
                active={expiry.kind === 'preset' && expiry.hours === p.hours}
                label={p.label}
                onClick={() =>
                  setExpiry({ kind: 'preset', hours: p.hours, label: p.label })
                }
                disabled={!projectId}
              />
            ))}
            <ExpiryChip
              active={expiry.kind === 'custom'}
              label="Custom"
              onClick={() =>
                setExpiry({
                  kind: 'custom',
                  iso: new Date(Date.now() + 86400000).toISOString(),
                })
              }
              disabled={!projectId}
            />
          </div>
          {expiry.kind === 'custom' && (
            <DateTimePicker
              value={expiry.iso}
              onChange={(v) => setExpiry({ kind: 'custom', iso: v })}
              clearable={false}
              size="sm"
              className="w-full"
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
          Create block
        </Button>
      </div>

      <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <DialogContent
          className="sm:max-w-md"
          overlayClassName="bg-black/60 backdrop-blur-sm"
        >
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <ShieldAlert className="h-4 w-4 text-destructive" />
              Create block
            </DialogTitle>
            <DialogDescription asChild>
              <p>
                Block{' '}
                {resourceId.trim() ? (
                  <>
                    <span className="font-medium text-foreground">
                      {typeMeta.label}
                    </span>{' '}
                    <code className="rounded bg-muted px-1 py-0.5 text-[11.5px] text-foreground">
                      {resourceId.trim()}
                    </code>
                  </>
                ) : (
                  <span className="font-medium text-foreground">
                    all {typeMeta.label}
                  </span>
                )}{' '}
                in{' '}
                <span
                  className="font-medium text-foreground"
                  title={meta?.projectName || projectId || ''}
                >
                  {formatProjectNameForDisplay(
                    meta?.projectName || projectId || '',
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
              </p>
            </DialogDescription>
          </DialogHeader>

          <pre className="mt-2 max-h-48 overflow-auto rounded-md border border-border bg-muted/40 p-3 text-[11.5px] leading-relaxed text-foreground/90 whitespace-pre-wrap break-all">
            {JSON.stringify(payload ?? {}, null, 2)}
          </pre>

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
              Create block
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </section>
  )
}

function ExpiryChip({
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
