import { useState, useEffect } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import {
  useProjectSite,
  useSiteVariables,
  useDeleteSite,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { Trash2 } from 'lucide-react'

export function SiteSettingsView() {
  const { projectId, siteId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const { data: site, isLoading: siteLoading } = useProjectSite(
    projectId,
    siteId,
  )
  const { data: variablesData } = useSiteVariables(projectId, siteId)
  const deleteSiteMutation = useDeleteSite(projectId)

  const [name, setName] = useState('')
  const [enabled, setEnabled] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  // Initialize state from site data
  useEffect(() => {
    if (site) {
      setName(site.name || '')
      setEnabled(site.enabled !== false) // Default to true if not specified
    }
  }, [site])

  // Update site mutation
  const updateSiteMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Site>) => {
      if (!projectId || !siteId || !site)
        throw new Error('Project ID, Site ID, and Site are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update({
        siteId,
        name: site.name,
        ...updates,
      })
    },
    onSuccess: () => {
      toast.success('Site updated successfully')
      queryClient.invalidateQueries({
        queryKey: ['site', 'project', projectId, siteId],
      })
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: any) => {
      toast.error(error.message || 'Failed to update site')
    },
  })

  // Update enabled mutation
  const updateEnabledMutation = useMutation({
    mutationFn: async (enabled: boolean) => {
      if (!projectId || !siteId || !site)
        throw new Error('Project ID, Site ID, and Site are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update({
        siteId,
        name: site.name,
        enabled,
      })
    },
    onSuccess: () => {
      toast.success(`Site has been ${enabled ? 'enabled' : 'disabled'}`)
      queryClient.invalidateQueries({
        queryKey: ['site', 'project', projectId, siteId],
      })
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: any) => {
      toast.error(getErrorMessage(error))
      // Revert to original value on error
      if (site) {
        setEnabled(site.enabled !== false)
      }
    },
  })

  const handleSaveName = () => {
    if (!name.trim()) {
      toast.error('Site name is required')
      return
    }
    updateSiteMutation.mutate({ name })
  }

  const handleEnabledToggle = (checked: boolean) => {
    setEnabled(checked)
  }

  const handleDeleteSite = () => {
    if (!siteId) return
    deleteSiteMutation.mutate(siteId, {
      onSuccess: () => {
        toast.success('Site deleted successfully')
        navigate({
          to: '/projects/$projectId/sites',
          params: { projectId: projectId! },
        })
      },
      onError: (error: any) => {
        toast.error(error.message || 'Failed to delete site')
      },
    })
    setDeleteDialogOpen(false)
  }

  if (siteLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">Loading settings...</p>
      </div>
    )
  }

  return (
    <div className="flex-1">
      <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        <div className="space-y-6">
          {/* Name Card */}
          {site && (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  Name
                </h3>
                <p className="text-[13px] text-muted-foreground mt-2">
                  Site name used for identification
                </p>
              </div>
              <div className="border-t border-border" />
              <div className="px-6 py-4">
                <Input
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder="Enter site name"
                  className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                />
              </div>
              <div className="px-6 py-4 border-t border-border bg-muted/30">
                <Button
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={
                    name === site?.name ||
                    !name.trim() ||
                    updateSiteMutation.isPending
                  }
                  onClick={handleSaveName}
                >
                  Update
                </Button>
              </div>
            </div>
          )}

          {/* Site Information */}
          {site && (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  {site.name}
                </h3>
              </div>
              <div className="border-t border-border" />
              <div className="px-6 py-4">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <Switch
                      id="toggle"
                      checked={enabled ?? false}
                      onCheckedChange={handleEnabledToggle}
                      disabled={updateEnabledMutation.isPending}
                    />
                    <Label
                      htmlFor="toggle"
                      className="text-[13px] text-foreground"
                    >
                      {enabled ? 'Enabled' : 'Disabled'}
                    </Label>
                  </div>
                </div>
                <div className="mt-4 space-y-1">
                  <p className="text-[13px] text-muted-foreground">
                    Site ID:{' '}
                    <span className="ml-1.5">
                      <CopyableId id={site.$id} size="sm" />
                    </span>
                  </p>
                  <p className="text-[13px] text-muted-foreground">
                    Created:{' '}
                    <DateTooltip
                      date={new Date(site.$createdAt)}
                      showFormattedDate
                      className="text-foreground"
                    />
                  </p>
                  <p className="text-[13px] text-muted-foreground">
                    Last updated:{' '}
                    <DateTooltip
                      date={new Date(site.$updatedAt || site.$createdAt)}
                      showFormattedDate
                      className="text-foreground"
                    />
                  </p>
                </div>
              </div>
              <div className="px-6 py-4 border-t border-border bg-muted/30">
                <Button
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={
                    enabled === (site.enabled !== false) ||
                    updateEnabledMutation.isPending
                  }
                  onClick={() => {
                    if (enabled !== (site.enabled !== false)) {
                      updateEnabledMutation.mutate(enabled)
                    }
                  }}
                >
                  Update
                </Button>
              </div>
            </div>
          )}

          {/* Environment Variables Card */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Environment Variables
              </h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                Configure environment variables for your site deployments
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              {variablesData?.variables && variablesData.variables.length > 0 ? (
                <div className="space-y-2">
                  {variablesData.variables.map((variable) => (
                    <div
                      key={variable.$id}
                      className="flex items-center justify-between py-2 px-3 rounded-md bg-muted/30"
                    >
                      <div>
                        <div className="text-[13px] font-medium text-foreground">
                          {variable.key}
                        </div>
                        {variable.value && (
                          <div className="text-[12px] text-muted-foreground mt-0.5">
                            {variable.value.length > 50
                              ? `${variable.value.substring(0, 50)}...`
                              : variable.value}
                          </div>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-[13px] text-muted-foreground">
                  No environment variables configured
                </p>
              )}
            </div>
          </div>

          {/* Delete Card */}
          {site && (
            <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  Delete Site
                </h3>
              </div>
              <div className="border-t border-destructive/20" />
              <div className="px-6 py-4">
                <p className="text-[13px] text-muted-foreground">
                  Permanently delete this site and all its data. This action
                  cannot be undone.
                </p>

                {/* Site Info Summary */}
                <div className="flex items-center gap-3 mt-4">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                    <Trash2 className="h-5 w-5 text-muted-foreground" />
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-[14px] font-medium text-foreground truncate">
                      {site.name || 'Unnamed Site'}
                    </p>
                    <p className="text-[12px] text-muted-foreground">
                      {site.$id}
                    </p>
                  </div>
                </div>
              </div>
              <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
                <Dialog
                  open={deleteDialogOpen}
                  onOpenChange={setDeleteDialogOpen}
                >
                  <DialogTrigger asChild>
                    <Button
                      variant="destructive"
                      size="sm"
                      className="h-9 text-[13px]"
                      disabled={deleteSiteMutation.isPending}
                    >
                      <Trash2 className="mr-1.5 h-4 w-4" />
                      Delete site
                    </Button>
                  </DialogTrigger>
                  <DialogContent className="sm:max-w-md p-0">
                    <DialogHeader className="px-6 pt-6 text-left">
                      <DialogTitle>Delete Site</DialogTitle>
                      <DialogDescription className="text-[13px] mt-2">
                        Are you sure you want to delete{' '}
                        <span className="font-medium text-foreground">
                          {site.name || 'this site'}
                        </span>{' '}
                        and all its data? This action cannot be undone.
                      </DialogDescription>
                    </DialogHeader>
                    <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                      <Button
                        variant="outline"
                        onClick={() => setDeleteDialogOpen(false)}
                        disabled={deleteSiteMutation.isPending}
                      >
                        Cancel
                      </Button>
                      <Button
                        variant="destructive"
                        onClick={handleDeleteSite}
                        disabled={deleteSiteMutation.isPending}
                      >
                        Delete
                      </Button>
                    </div>
                  </DialogContent>
                </Dialog>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
