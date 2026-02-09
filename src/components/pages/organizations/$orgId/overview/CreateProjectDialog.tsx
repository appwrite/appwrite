import { useState, useEffect, useMemo } from 'react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { IdInput } from '@/components/ui/id-input'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Info, Globe } from 'lucide-react'
import { useCreateProject, useRegions } from '@/lib/react-query/hooks'
import { useNavigate } from '@tanstack/react-router'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import { sdk } from '@/lib/appwrite/sdk'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectSeparator,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

interface CreateProjectDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  teamId: string | null | undefined
  organizationPlan?: unknown
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
  const {
    regions,
    isLoading: regionsLoading,
    error: regionsError,
  } = useRegions()

  // Check if a region is coming soon (disabled/inactive)
  const isRegionComingSoon = (region: unknown): boolean => {
    return (
      region.status === 'coming-soon' ||
      region.comingSoon === true ||
      region.disabled === true ||
      region.status === 'disabled' ||
      region.status === 'inactive' ||
      region.inactive === true
    )
  }

  // Get available (non-coming-soon) regions
  const availableRegions = useMemo(() => {
    return regions.filter((r: unknown) => !isRegionComingSoon(r))
  }, [regions])

  // Sort regions: available first, inactive/coming soon last
  const sortedRegions = useMemo(() => {
    const available = regions.filter((r: unknown) => !isRegionComingSoon(r))
    const inactive = regions.filter((r: unknown) => isRegionComingSoon(r))
    return [...available, ...inactive]
  }, [regions])

  // Set default region when regions load
  useEffect(() => {
    if (availableRegions.length > 0 && !selectedRegion) {
      // Prefer Frankfurt (fra) or first available region
      const defaultRegion =
        availableRegions.find((r: unknown) => r.$id === 'fra') ||
        availableRegions[0]
      if (defaultRegion) {
        setSelectedRegion(defaultRegion.$id)
      }
    }
  }, [availableRegions, selectedRegion])

  // Calculate if this would be an additional project
  const isAdditionalProject = useMemo(() => {
    if (!organizationPlan) return false

    const projectLimit = (organizationPlan?.addons as unknown)?.projects?.limit
    const planIncluded = (organizationPlan?.addons as unknown)?.projects
      ?.planIncluded
    const limitNum = Number(projectLimit ?? planIncluded)
    const limit = isNaN(limitNum) ? null : limitNum

    if (limit === null || limit === 0) return false // Unlimited or no limit

    return currentProjectsCount >= limit
  }, [organizationPlan, currentProjectsCount])

  // Get additional project price
  const additionalProjectPrice = useMemo(() => {
    if (!isAdditionalProject || !organizationPlan) return null
    return (
      (organizationPlan?.addons as unknown)?.projects?.price ||
      (organizationPlan as unknown)?.additionalProjectPrice ||
      null
    )
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
        navigate({
          to: '/projects/$projectId',
          params: { projectId: result.$id },
        })
      }
    } catch (error: unknown) {
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
                <div className="h-9 w-full animate-pulse rounded-md border border-border bg-muted/50" />
              ) : regionsError ? (
                <div className="rounded-lg border border-destructive/50 bg-destructive/5 p-4 text-center">
                  <p className="text-[13px] text-destructive mb-1">
                    Failed to load regions
                  </p>
                  <p className="text-[12px] text-muted-foreground">
                    {regionsError instanceof Error
                      ? regionsError.message
                      : 'Unknown error'}
                  </p>
                </div>
              ) : regions.length > 0 ? (
                <Select
                  value={selectedRegion || undefined}
                  onValueChange={setSelectedRegion}
                  disabled={createProjectMutation.isPending}
                >
                  <SelectTrigger className="h-9 w-full text-[13px]">
                    <SelectValue placeholder="Select a region">
                      {selectedRegion
                        ? (() => {
                            const region = regions.find(
                              (r: unknown) => r.$id === selectedRegion,
                            )
                            if (!region) return 'Select a region'
                            const flagCode = region.flag || ''
                            const regionName =
                              region.name || region.$id || 'Unknown'
                            const flagUrl = flagCode
                              ? `${sdk.forConsole.client.config.endpoint}/avatars/flags/${flagCode.toLowerCase()}?width=80&height=80&quality=100&project=console`
                              : null
                            return (
                              <div className="flex items-center gap-2 w-full">
                                {flagUrl ? (
                                  <img
                                    src={flagUrl}
                                    alt={`${regionName} flag`}
                                    className="h-4 w-4 shrink-0 rounded border border-border/50 object-cover"
                                    onError={(e) => {
                                      const target =
                                        e.target as HTMLImageElement
                                      target.style.display = 'none'
                                    }}
                                  />
                                ) : (
                                  <Globe className="h-4 w-4 shrink-0 text-muted-foreground" />
                                )}
                                <span className="flex-1">{regionName}</span>
                                <span className="text-[11px] font-mono text-muted-foreground">
                                  {region.$id}
                                </span>
                              </div>
                            )
                          })()
                        : 'Select a region'}
                    </SelectValue>
                  </SelectTrigger>
                  <SelectContent>
                    {sortedRegions.map((region: unknown, index: number) => {
                      const flagCode = region.flag || ''
                      const regionName = region.name || region.$id || 'Unknown'
                      const flagUrl = flagCode
                        ? `${sdk.forConsole.client.config.endpoint}/avatars/flags/${flagCode.toLowerCase()}?width=80&height=80&quality=100&project=console`
                        : null
                      const isComingSoon = isRegionComingSoon(region)
                      const isFirstInactive =
                        index > 0 &&
                        !isRegionComingSoon(sortedRegions[index - 1]) &&
                        isComingSoon

                      return (
                        <>
                          {isFirstInactive && (
                            <SelectSeparator
                              key={`separator-${region.$id}`}
                              className="my-1"
                            />
                          )}
                          <SelectItem
                            key={region.$id}
                            value={region.$id}
                            disabled={isComingSoon}
                            className={cn(
                              isComingSoon && 'opacity-50 cursor-not-allowed',
                            )}
                          >
                            <div className="flex items-center gap-2 w-full">
                              {flagUrl ? (
                                <img
                                  src={flagUrl}
                                  alt={`${regionName} flag`}
                                  className="h-4 w-4 shrink-0 rounded border border-border/50 object-cover"
                                  onError={(e) => {
                                    const target = e.target as HTMLImageElement
                                    target.style.display = 'none'
                                  }}
                                />
                              ) : (
                                <Globe className="h-4 w-4 shrink-0 text-muted-foreground" />
                              )}
                              <span className="flex-1">{regionName}</span>
                              <span className="text-[11px] font-mono text-muted-foreground">
                                {region.$id}
                              </span>
                              {isComingSoon && (
                                <span className="text-[11px] text-muted-foreground">
                                  Coming soon
                                </span>
                              )}
                            </div>
                          </SelectItem>
                        </>
                      )
                    })}
                  </SelectContent>
                </Select>
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
              disabled={
                createProjectMutation.isPending ||
                !name.trim() ||
                !selectedRegion
              }
              style={{ backgroundColor: '#f02e65' }}
              className="text-white hover:opacity-90"
            >
              Create
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
