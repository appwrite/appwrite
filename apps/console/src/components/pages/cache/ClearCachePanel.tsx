import { useMemo, useState } from 'react'
import { toast } from 'sonner'
import { Globe, Loader2, Trash2 } from 'lucide-react'
import { CacheDatabase, CacheTarget, Region } from '@appwrite.io/console'
import {
  useDeleteCache,
  type DeleteCacheParams,
} from '@/lib/react-query/hooks/manager'
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
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import {
  CACHE_DATABASE_META,
  CACHE_TARGET_META,
  ORDERED_CACHE_DATABASES,
  ORDERED_CACHE_TARGETS,
  REGION_OPTIONS,
} from './cache-target-meta'

export function ClearCachePanel() {
  const [region, setRegion] = useState<Region | null>(null)
  const [target, setTarget] = useState<CacheTarget>(CacheTarget.Cache)
  const [all, setAll] = useState(false)
  const [database, setDatabase] = useState<CacheDatabase>(CacheDatabase.Console)
  const [projectId, setProjectId] = useState('')
  const [collectionId, setCollectionId] = useState('')
  const [documentId, setDocumentId] = useState('')
  const [confirmOpen, setConfirmOpen] = useState(false)

  const mutation = useDeleteCache()

  const targetMeta = CACHE_TARGET_META[target]
  const dbMeta = CACHE_DATABASE_META[database]

  // Non-scopable targets are always cleared in full, so the payload must say so
  // regardless of the toggle's prior state on a scopable target. This keeps the
  // same intent ("clear Locks") producing the same payload no matter how the
  // user arrived at it.
  const effectiveAll = all || !targetMeta.scopable

  // Scoping (database/project/collection/document) only applies to the data
  // cache, and only when we are not flushing the whole target.
  const showScope = !effectiveAll
  const showProject = showScope && dbMeta.takesProject
  const showCollection = showProject && !!projectId.trim()
  const showDocument = showCollection && !!collectionId.trim()

  const payload = useMemo<DeleteCacheParams>(() => {
    const p: DeleteCacheParams = { cache: target }
    if (region) p.region = region
    if (effectiveAll) {
      p.all = true
      return p
    }
    p.database = database
    if (dbMeta.takesProject && projectId.trim()) {
      p.projectId = projectId.trim()
      if (collectionId.trim()) {
        p.collectionId = collectionId.trim()
        if (documentId.trim()) p.documentId = documentId.trim()
      }
    }
    return p
  }, [
    region,
    target,
    effectiveAll,
    database,
    dbMeta.takesProject,
    projectId,
    collectionId,
    documentId,
  ])

  // Scoping by a specific project requires an actual project ID; without it the
  // payload would be ambiguous.
  const missingProject = showProject && !projectId.trim()
  const canSubmit = !mutation.isPending && !missingProject

  const handleConfirm = () => {
    mutation.mutate(payload, {
      onSuccess: () => {
        toast.success('Cache cleared')
        setConfirmOpen(false)
      },
      onError: (e) => {
        toast.error('Could not clear cache', {
          description: e instanceof Error ? e.message : String(e),
        })
      },
    })
  }

  const regionLabel =
    REGION_OPTIONS.find((o) => o.value === region)?.label ?? 'All regions'

  return (
    <section className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Clear cache
        </h3>
        <p className="mt-1 text-[13px] text-muted-foreground">
          Flush an internal cache target. Leaving the region unset clears it in
          every region.
        </p>
      </div>
      <div className="border-t border-border" />

      <form
        className="px-6 py-4 space-y-5"
        onSubmit={(e) => {
          e.preventDefault()
          if (canSubmit) setConfirmOpen(true)
        }}
      >
        {/* Region */}
        <div className="space-y-2">
          <Label>Region</Label>
          <div className="flex flex-wrap gap-1.5">
            {REGION_OPTIONS.map((opt) => {
              const active = region === opt.value
              const isAll = opt.value === null
              return (
                <button
                  key={opt.label}
                  type="button"
                  onClick={() => setRegion(opt.value)}
                  className={cn(
                    'flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-[12px] uppercase tracking-wide transition-colors',
                    active
                      ? 'border-foreground/30 bg-accent text-foreground'
                      : 'border-border bg-background text-muted-foreground hover:bg-accent hover:text-foreground',
                  )}
                >
                  {isAll && <Globe className="h-3.5 w-3.5" />}
                  {opt.label}
                </button>
              )
            })}
          </div>
        </div>

        {/* Cache target */}
        <div className="space-y-2">
          <Label>Cache target</Label>
          <div className="grid grid-cols-3 gap-1.5">
            {ORDERED_CACHE_TARGETS.map((ct) => {
              const meta = CACHE_TARGET_META[ct]
              const Icon = meta.icon
              const active = target === ct
              return (
                <button
                  key={ct}
                  type="button"
                  onClick={() => setTarget(ct)}
                  title={meta.description}
                  className={cn(
                    'flex items-center gap-1.5 rounded-md border px-2 py-1.5 text-[12px] text-start transition-colors',
                    active
                      ? 'border-foreground/30 bg-accent text-foreground'
                      : 'border-border bg-background text-muted-foreground hover:bg-accent hover:text-foreground',
                  )}
                >
                  <Icon className="h-3.5 w-3.5 shrink-0" />
                  <span className="truncate">{meta.label}</span>
                </button>
              )
            })}
          </div>
          <p className="text-[12px] text-muted-foreground">
            {targetMeta.description}
          </p>
        </div>

        {/* Clear-everything toggle */}
        <div className="flex items-center justify-between rounded-md border border-border bg-background px-4 py-3">
          <div className="space-y-0.5">
            <Label htmlFor="clear-all" className="text-[13px]">
              Clear the entire target
            </Label>
            <p className="text-[12px] text-muted-foreground">
              {targetMeta.scopable
                ? 'Ignore the scope below and flush the whole target.'
                : 'This target is always cleared in full.'}
            </p>
          </div>
          <Switch
            id="clear-all"
            checked={all || !targetMeta.scopable}
            disabled={!targetMeta.scopable}
            onCheckedChange={setAll}
          />
        </div>

        {/* Scope (data cache only) */}
        {showScope && (
          <div className="space-y-4 rounded-md border border-border bg-background/60 px-4 py-4">
            <div className="space-y-2">
              <Label>Database scope</Label>
              <div className="grid grid-cols-3 gap-1.5">
                {ORDERED_CACHE_DATABASES.map((db) => {
                  const meta = CACHE_DATABASE_META[db]
                  const Icon = meta.icon
                  const active = database === db
                  return (
                    <button
                      key={db}
                      type="button"
                      onClick={() => setDatabase(db)}
                      title={meta.description}
                      className={cn(
                        'flex items-center gap-1.5 rounded-md border px-2 py-1.5 text-[12px] text-start transition-colors',
                        active
                          ? 'border-foreground/30 bg-accent text-foreground'
                          : 'border-border bg-card text-muted-foreground hover:bg-accent hover:text-foreground',
                      )}
                    >
                      <Icon className="h-3.5 w-3.5 shrink-0" />
                      <span className="truncate">{meta.label}</span>
                    </button>
                  )
                })}
              </div>
              <p className="text-[12px] text-muted-foreground">
                {dbMeta.description}
              </p>
            </div>

            {showProject && (
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <Label htmlFor="cache-project-id">Project ID</Label>
                  <Badge
                    variant="secondary"
                    className="h-5 px-1.5 text-[10.5px] font-normal"
                  >
                    Required
                  </Badge>
                </div>
                <Input
                  id="cache-project-id"
                  value={projectId}
                  onChange={(e) => setProjectId(e.target.value)}
                  placeholder="Enter project ID"
                  spellCheck={false}
                  autoComplete="off"
                  className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                />
              </div>
            )}

            {showCollection && (
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <Label htmlFor="cache-collection-id">Collection ID</Label>
                  {!collectionId.trim() && (
                    <Badge
                      variant="secondary"
                      className="h-5 px-1.5 text-[10.5px] font-normal"
                    >
                      Whole project
                    </Badge>
                  )}
                </div>
                <Input
                  id="cache-collection-id"
                  value={collectionId}
                  onChange={(e) => setCollectionId(e.target.value)}
                  placeholder="Leave empty to clear the whole project"
                  spellCheck={false}
                  autoComplete="off"
                  className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                />
              </div>
            )}

            {showDocument && (
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <Label htmlFor="cache-document-id">Document ID</Label>
                  {!documentId.trim() && (
                    <Badge
                      variant="secondary"
                      className="h-5 px-1.5 text-[10.5px] font-normal"
                    >
                      Whole collection
                    </Badge>
                  )}
                </div>
                <Input
                  id="cache-document-id"
                  value={documentId}
                  onChange={(e) => setDocumentId(e.target.value)}
                  placeholder="Leave empty to clear the whole collection"
                  spellCheck={false}
                  autoComplete="off"
                  className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                />
              </div>
            )}
          </div>
        )}
      </form>

      <div className="px-6 py-4 border-t border-border bg-muted/30 flex items-center justify-end gap-2">
        <Button
          type="button"
          size="sm"
          className="h-9 text-[13px]"
          disabled={!canSubmit}
          onClick={() => setConfirmOpen(true)}
        >
          Clear cache
        </Button>
      </div>

      <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <DialogContent
          className="sm:max-w-md"
          overlayClassName="bg-black/60 backdrop-blur-sm"
        >
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Trash2 className="h-4 w-4 text-destructive" />
              Clear cache
            </DialogTitle>
            <DialogDescription asChild>
              <p>
                Clear the{' '}
                <span className="font-medium text-foreground">
                  {effectiveAll ? 'entire ' : ''}
                  {targetMeta.label.toLowerCase()}
                </span>{' '}
                in{' '}
                <span className="font-medium text-foreground">
                  {region ? regionLabel : 'all regions'}
                </span>
                ?
              </p>
            </DialogDescription>
          </DialogHeader>

          <pre className="mt-2 max-h-48 overflow-auto rounded-md border border-border bg-muted/40 p-3 text-[11.5px] leading-relaxed text-foreground/90 whitespace-pre-wrap break-all">
            {JSON.stringify(payload, null, 2)}
          </pre>

          <div className="flex justify-end gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setConfirmOpen(false)}
              disabled={mutation.isPending}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleConfirm}
              disabled={mutation.isPending}
            >
              {mutation.isPending && (
                <Loader2 className="h-3.5 w-3.5 animate-spin" />
              )}
              Clear cache
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </section>
  )
}
