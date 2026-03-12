import { useState, useEffect } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  useProjectPlatform,
  useUpdatePlatform,
  useDeletePlatform,
} from '@/lib/react-query/hooks'
import { getPlatformDisplayName } from '@/lib/utils/platform'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { toast } from 'sonner'
import { Trash2, ExternalLink } from 'lucide-react'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import type { Models } from '@appwrite.io/console'

interface PlatformDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  platform: Models.Platform | null
  onSuccess?: () => void
}

export function PlatformDrawer({
  open,
  onOpenChange,
  projectId,
  platform,
  onSuccess,
}: PlatformDrawerProps) {
  const platformId = platform?.$id ?? null
  const { platform: fullPlatform, isLoading: platformLoading } =
    useProjectPlatform(projectId, platformId)
  const displayPlatform = fullPlatform ?? platform

  const updateMutation = useUpdatePlatform(projectId)
  const deleteMutation = useDeletePlatform(projectId)
  const isPending = updateMutation.isPending || deleteMutation.isPending

  const [name, setName] = useState('')
  const [key, setKey] = useState('')
  const [store, setStore] = useState('')
  const [hostname, setHostname] = useState('')
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})

  useEffect(() => {
    if (!open) {
      setName('')
      setKey('')
      setStore('')
      setHostname('')
      setErrors({})
      setDeleteConfirmOpen(false)
    } else if (displayPlatform) {
      setName(displayPlatform.name || '')
      setKey(displayPlatform.key || '')
      setStore(displayPlatform.store || '')
      setHostname(displayPlatform.hostname || '')
      setErrors({})
    }
  }, [open, displayPlatform])

  const handleOpenChange = (newOpen: boolean) => {
    if (!isPending) {
      onOpenChange(newOpen)
      if (!newOpen) setDeleteConfirmOpen(false)
    }
  }

  const validate = (): boolean => {
    const newErrors: Record<string, string> = {}
    if (!name.trim()) {
      newErrors.name = 'Name is required'
    }
    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!validate() || !platformId) return

    updateMutation.mutate(
      {
        platformId,
        name: name.trim(),
        key: key.trim() || undefined,
        store: store.trim() || undefined,
        hostname: hostname.trim() || undefined,
      },
      {
        onSuccess: () => {
          toast.success('App updated successfully')
          handleOpenChange(false)
          onSuccess?.()
        },
        onError: (error: Error) => {
          toast.error(getErrorMessage(error) || 'Failed to update app')
        },
      },
    )
  }

  const handleDelete = () => {
    if (!platformId) return
    deleteMutation.mutate(platformId, {
      onSuccess: () => {
        toast.success('App deleted successfully')
        setDeleteConfirmOpen(false)
        handleOpenChange(false)
        onSuccess?.()
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || 'Failed to delete app')
      },
    })
  }

  const platformType = (displayPlatform?.type ?? 'web') as string
  const typeLower = platformType.toLowerCase()
  const showKey = typeLower.includes('android') || typeLower.includes('apple')
  const showHostname =
    typeLower === 'web' || typeLower.startsWith('flutter-web')
  const showStore = typeLower.includes('android') || typeLower.includes('apple')

  if (!platform && !platformId) return null

  return (
    <>
      <BaseDrawer
        open={open}
        onOpenChange={handleOpenChange}
        title="Update app"
        maxWidth="sm:max-w-lg"
      >
        <>
          <div className="border-t border-border shrink-0" />

          <form
            onSubmit={handleSubmit}
            className="flex flex-1 flex-col min-h-0"
          >
            <div className="flex-1 overflow-y-auto">
              <div className="px-6 py-6 space-y-5">
                {platformLoading && !displayPlatform ? (
                  <div className="animate-pulse space-y-4">
                    <div className="h-10 bg-muted rounded" />
                    <div className="h-10 bg-muted rounded" />
                  </div>
                ) : (
                  <>
                    <div className="space-y-2">
                      <Label
                        htmlFor="platform-name"
                        className="text-[12px] font-medium"
                      >
                        Name <span className="text-destructive">*</span>
                      </Label>
                      <Input
                        id="platform-name"
                        placeholder="App name"
                        value={name}
                        onChange={(e) => {
                          setName(e.target.value)
                          if (errors.name)
                            setErrors((prev) => ({ ...prev, name: '' }))
                        }}
                        disabled={isPending}
                        className={errors.name ? 'border-destructive' : ''}
                      />
                      {errors.name && (
                        <p className="text-[12px] text-destructive">
                          {errors.name}
                        </p>
                      )}
                    </div>

                    <div className="space-y-2">
                      <Label className="text-[12px] font-medium text-muted-foreground">
                        Type
                      </Label>
                      <div className="flex items-center gap-2 rounded-md border border-border bg-muted/30 px-3 py-2">
                        <PlatformIcon platform={platformType} size="sm" />
                        <span className="text-[13px] text-foreground">
                          {getPlatformDisplayName(platformType)}
                        </span>
                      </div>
                    </div>

                    {showHostname && (
                      <div className="space-y-2">
                        <Label
                          htmlFor="platform-hostname"
                          className="text-[12px] font-medium"
                        >
                          Hostname
                        </Label>
                        <Input
                          id="platform-hostname"
                          placeholder="e.g. localhost or myapp.example.com"
                          value={hostname}
                          onChange={(e) => setHostname(e.target.value)}
                          disabled={isPending}
                        />
                        <p className="text-[12px] text-muted-foreground">
                          The domain your app makes requests from. Use{' '}
                          <code className="rounded bg-muted px-1 py-0.5 text-[11px]">
                            localhost
                          </code>{' '}
                          for development (no port or protocol). Add a separate
                          platform for each origin (e.g. localhost and
                          production).
                        </p>
                        <a
                          href="https://appwrite.io/blog/post/cors-error"
                          target="_blank"
                          rel="noopener noreferrer"
                          className="inline-flex items-center gap-1 text-[12px] text-primary hover:underline"
                        >
                          Troubleshoot CORS errors
                          <ExternalLink className="h-3 w-3 shrink-0" />
                        </a>
                      </div>
                    )}

                    {showKey && (
                      <div className="space-y-2">
                        <Label
                          htmlFor="platform-key"
                          className="text-[12px] font-medium"
                        >
                          {platformType.toLowerCase().includes('apple')
                            ? 'Bundle ID'
                            : 'Package name'}
                        </Label>
                        <Input
                          id="platform-key"
                          placeholder={
                            platformType.toLowerCase().includes('apple')
                              ? 'com.example.app'
                              : 'com.example.app'
                          }
                          value={key}
                          onChange={(e) => setKey(e.target.value)}
                          disabled={isPending}
                        />
                      </div>
                    )}

                    {showStore && (
                      <div className="space-y-2">
                        <Label
                          htmlFor="platform-store"
                          className="text-[12px] font-medium"
                        >
                          App store / Play store ID
                        </Label>
                        <Input
                          id="platform-store"
                          placeholder="Optional"
                          value={store}
                          onChange={(e) => setStore(e.target.value)}
                          disabled={isPending}
                        />
                      </div>
                    )}

                    <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden mt-6">
                      <div className="px-6 py-4">
                        <h3 className="text-[15px] font-semibold text-foreground">
                          Delete app
                        </h3>
                      </div>
                      <div className="border-t border-destructive/20" />
                      <div className="px-6 py-4">
                        <p className="text-[13px] text-muted-foreground">
                          Remove this app from the project. This action cannot
                          be undone.
                        </p>
                      </div>
                      <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
                        <Button
                          type="button"
                          variant="destructive"
                          size="sm"
                          className="h-9 text-[13px]"
                          onClick={() => setDeleteConfirmOpen(true)}
                          disabled={isPending}
                        >
                          <Trash2 className="mr-1.5 h-4 w-4" />
                          Delete app
                        </Button>
                      </div>
                    </div>
                  </>
                )}
              </div>
            </div>

            <div className="flex-shrink-0 flex items-center justify-start gap-2 border-t border-border bg-muted/30 px-6 py-4">
              <Button type="submit" disabled={isPending || platformLoading}>
                Update
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={() => handleOpenChange(false)}
                disabled={isPending}
              >
                Cancel
              </Button>
            </div>
          </form>
        </>
      </BaseDrawer>

      <Dialog open={deleteConfirmOpen} onOpenChange={setDeleteConfirmOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>Delete app</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete{' '}
              <strong>{displayPlatform?.name || 'this app'}</strong>? This
              action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setDeleteConfirmOpen(false)}
              disabled={deleteMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleDelete}
              disabled={deleteMutation.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
