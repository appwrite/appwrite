import { useState, useEffect, useMemo } from 'react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { IdInput } from '@/components/ui/id-input'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Info, Globe, Check } from 'lucide-react'
import { useCreateProject, useRegions } from '@/lib/react-query/hooks'
import { useNavigate } from '@tanstack/react-router'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import { sdk } from '@/lib/appwrite/sdk'

interface CreateProjectDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  teamId: string | null | undefined
  organizationPlan?: any
  currentProjectsCount?: number
}

export function CreateProjectDialog({
  open,
  onOpenChange,
  teamId,
  organizationPlan,
  currentProjectsCount = 0,
}: CreateProjectDialogProps) {
  const navigate = useNavigate()
  const [projectId, setProjectId] = useState<string | undefined>(undefined)
  const [name, setName] = useState('')
  const [selectedRegion, setSelectedRegion] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const createProjectMutation = useCreateProject(teamId)
  const { regions, isLoading: regionsLoading, error: regionsError } = useRegions()

  // Log regions data for debugging
  useEffect(() => {
    if (regions.length === 0 && !regionsLoading) {
      console.log('Regions data:', { regions, regionsError, regionsLoading })
    }
  }, [regions, regionsLoading, regionsError])

  // Set default region when regions load
  useEffect(() => {
    if (regions.length > 0 && !selectedRegion) {
      // Prefer Frankfurt (fra) or first available region
      const defaultRegion = regions.find((r: any) => r.$id === 'fra') || regions[0]
      if (defaultRegion) {
        setSelectedRegion(defaultRegion.$id)
      }
    }
  }, [regions, selectedRegion])

  // Calculate if this would be an additional project
  const isAdditionalProject = useMemo(() => {
    if (!organizationPlan) return false
    
    const projectLimit = (organizationPlan?.addons as any)?.projects?.limit
    const planIncluded = (organizationPlan?.addons as any)?.projects?.planIncluded
    const limitNum = Number(projectLimit ?? planIncluded)
    const limit = isNaN(limitNum) ? null : limitNum
    
    if (limit === null || limit === 0) return false // Unlimited or no limit
    
    return currentProjectsCount >= limit
  }, [organizationPlan, currentProjectsCount])

  // Get additional project price
  const additionalProjectPrice = useMemo(() => {
    if (!isAdditionalProject || !organizationPlan) return null
    return (organizationPlan?.addons as any)?.projects?.price || 
           (organizationPlan as any)?.additionalProjectPrice || 
           null
  }, [isAdditionalProject, organizationPlan])

  const handleOpenChange = (newOpen: boolean) => {
    if (!createProjectMutation.isPending) {
      onOpenChange(newOpen)
      if (!newOpen) {
        resetForm()
      }
    }
  }

  const resetForm = () => {
    setProjectId(undefined)
    setName('')
    setSelectedRegion(null)
    setErrors({})
  }

  useEffect(() => {
    if (!open) {
      resetForm()
    }
  }, [open])

  const validate = (): boolean => {
    const newErrors: Record<string, string> = {}

    if (!name.trim()) {
      newErrors.name = 'Name is required'
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    if (!validate()) {
      return
    }

    if (!teamId) {
      toast.error('Team ID is required')
      return
    }

    if (!selectedRegion) {
      toast.error('Please select a region')
      return
    }

    try {
      const result = await createProjectMutation.mutateAsync({
        projectId,
        name: name.trim(),
        region: selectedRegion,
      })

      toast.success('Project created successfully')
      handleOpenChange(false)
      
      // Navigate to the new project
      if (result?.$id) {
        navigate({ to: '/projects/$projectId', params: { projectId: result.$id } })
      }
    } catch (error: any) {
      toast.error(error?.message || 'Failed to create project')
    }
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>Create project</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Create a new project in your organization.
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-0 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="name">
                Name <span className="text-destructive">*</span>
              </Label>
              <Input
                id="name"
                type="text"
                placeholder="Enter project name"
                value={name}
                onChange={(e) => {
                  setName(e.target.value)
                  if (errors.name) {
                    setErrors((prev) => ({ ...prev, name: '' }))
                  }
                }}
                disabled={createProjectMutation.isPending}
                className={errors.name ? 'border-destructive' : ''}
                autoFocus
              />
              {errors.name && (
                <p className="text-[12px] text-destructive">{errors.name}</p>
              )}
            </div>

            <div className="space-y-2">
              <Label htmlFor="project-id">Project ID</Label>
              <IdInput
                id="project-id"
                value={projectId}
                onChange={setProjectId}
                maxLength={36}
                disabled={createProjectMutation.isPending}
                placeholder="Leave blank to auto-generate"
              />
            </div>

            <div className="space-y-2">
              <Label className="flex items-center gap-2">
                <Globe className="h-3.5 w-3.5 text-muted-foreground" />
                Region
                <span className="text-destructive">*</span>
              </Label>
              {regionsLoading ? (
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                  {[1, 2, 3, 4, 5, 6].map((i) => (
                    <div
                      key={i}
                      className="h-20 animate-pulse rounded-lg border border-border bg-muted/50"
                    />
                  ))}
                </div>
              ) : regionsError ? (
                <div className="rounded-lg border border-destructive/50 bg-destructive/5 p-4 text-center">
                  <p className="text-[13px] text-destructive mb-1">
                    Failed to load regions
                  </p>
                  <p className="text-[12px] text-muted-foreground">
                    {regionsError instanceof Error ? regionsError.message : 'Unknown error'}
                  </p>
                </div>
              ) : regions.length > 0 ? (
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                  {regions.map((region: any) => {
                    const isSelected = selectedRegion === region.$id
                    const regionCode = region.code || region.$id?.toUpperCase() || ''
                    const regionName = region.name || region.$id || 'Unknown'
                    
                    return (
                      <button
                        key={region.$id}
                        type="button"
                        onClick={() => setSelectedRegion(region.$id)}
                        disabled={createProjectMutation.isPending}
                        className={cn(
                          'group relative flex items-center gap-3 rounded-lg border p-3 text-left transition-all',
                          'hover:border-foreground/20 hover:bg-accent/50',
                          isSelected
                            ? 'border-[#f02e65] bg-[#f02e65]/5 shadow-sm'
                            : 'border-border bg-card/50',
                        )}
                      >
                        {/* Flag Image */}
                        <div className="relative flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-md border border-border/50 bg-background shadow-sm">
                          {regionCode ? (
                            <img
                              src={`${sdk.forConsole.client.config.endpoint}/avatars/flags/${regionCode.toLowerCase()}?width=40&height=40&quality=100&project=console`}
                              alt={`${regionName} flag`}
                              className="h-full w-full object-cover"
                              onError={(e) => {
                                // Fallback: hide image and show globe icon
                                const target = e.target as HTMLImageElement
                                target.style.display = 'none'
                                const fallback = target.parentElement?.querySelector('.flag-fallback')
                                if (fallback) {
                                  (fallback as HTMLElement).style.display = 'flex'
                                }
                              }}
                            />
                          ) : null}
                          <div className="flag-fallback hidden h-full w-full items-center justify-center text-lg">
                            🌐
                          </div>
                        </div>

                        {/* Region Info */}
                        <div className="min-w-0 flex-1">
                          <div className="flex items-center gap-1.5">
                            <span className="text-[13px] font-medium text-foreground">
                              {regionName}
                            </span>
                            {isSelected && (
                              <Check className="h-3.5 w-3.5 shrink-0 text-[#f02e65]" />
                            )}
                          </div>
                          {regionCode && (
                            <span className="text-[11px] text-muted-foreground font-mono">
                              {regionCode.toUpperCase()}
                            </span>
                          )}
                        </div>

                        {/* Selection Indicator */}
                        {isSelected && (
                          <div className="absolute inset-0 rounded-lg border-2 border-[#f02e65] pointer-events-none" />
                        )}
                      </button>
                    )
                  })}
                </div>
              ) : (
                <div className="rounded-lg border border-border bg-muted/50 p-4 text-center">
                  <p className="text-[13px] text-muted-foreground">
                    No regions available
                  </p>
                </div>
              )}
            </div>

            {isAdditionalProject && additionalProjectPrice && (
              <Alert>
                <Info className="h-4 w-4" />
                <AlertDescription className="text-[12px]">
                  This project will incur an additional charge of{' '}
                  <span className="font-medium">
                    ${additionalProjectPrice.toFixed(2)} per month
                  </span>
                  .
                </AlertDescription>
              </Alert>
            )}
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => handleOpenChange(false)}
              disabled={createProjectMutation.isPending}
            >
              Cancel
            </Button>
            <Button 
              type="submit" 
              disabled={createProjectMutation.isPending || !name.trim() || !selectedRegion}
              style={{ backgroundColor: '#f02e65' }}
              className="text-white hover:opacity-90"
            >
              {createProjectMutation.isPending ? 'Creating...' : 'Create'}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
