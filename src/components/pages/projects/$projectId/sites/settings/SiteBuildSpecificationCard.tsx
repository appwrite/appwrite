import { useState, useEffect, useMemo } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  buildSiteUpdateParams,
  useSiteSpecifications,
} from '@/lib/react-query/hooks'
import { hasUnavailableSpecifications } from '@/lib/specifications'
import { SpecificationTableCard } from '../../shared/SpecificationTableCard'

interface SiteBuildSpecificationCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
  isCloud?: boolean
}

export function SiteBuildSpecificationCard({
  projectId,
  siteId,
  site,
  isCloud = false,
}: SiteBuildSpecificationCardProps) {
  const queryClient = useQueryClient()
  const { data: specificationsData } = useSiteSpecifications(projectId)

  const specifications = useMemo(
    () => specificationsData?.specifications || [],
    [specificationsData],
  )

  const [buildSpecification, setBuildSpecification] = useState('')

  useEffect(() => {
    if (site) {
      setBuildSpecification(site.buildSpecification || '')
    }
  }, [site])

  const updateSiteMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Site>) => {
      if (!projectId || !siteId || !site)
        throw new Error('Project ID, Site ID, and Site are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update(buildSiteUpdateParams(site, updates))
    },
    onSuccess: () => {
      toast.success('Specification updated successfully')
      queryClient.invalidateQueries({
        queryKey: ['site', 'project', projectId, siteId],
      })
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(
        getErrorMessage(error, 'Failed to update specification'),
      )
    },
  })

  const handleSave = () => {
    updateSiteMutation.mutate({
      buildSpecification: buildSpecification || undefined,
    })
  }

  const hasChanges =
    buildSpecification !== (site?.buildSpecification || '')

  if (!isCloud || specifications.length === 0) {
    return null
  }

  const footerNote = hasUnavailableSpecifications(specifications) ? (
    <div className="rounded-lg border border-border bg-muted/30 px-3 py-2.5">
      <p className="text-[12px] text-muted-foreground">
        Need more resources?{' '}
        <a
          href="#"
          className="font-medium text-foreground underline hover:no-underline"
          onClick={(e) => {
            e.preventDefault()
          }}
        >
          Upgrade your plan
        </a>{' '}
        to unlock additional specifications.
      </p>
    </div>
  ) : undefined

  return (
    <SpecificationTableCard
      title="Specification"
      description="CPU and memory allocated on the build worker for dependency install and compile steps."
      scope="build"
      specs={specifications}
      selectedSlug={buildSpecification}
      onSelectedSlugChange={setBuildSpecification}
      hasChanges={hasChanges}
      isSaving={updateSiteMutation.isPending}
      onSave={handleSave}
      footerNote={footerNote}
    />
  )
}
