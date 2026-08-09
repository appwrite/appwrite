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
import { useProject } from '@/lib/react-query/hooks'
import {
  hasUnavailableSpecifications,
  SpecificationType,
} from '@/lib/specifications'
import { SpecificationsUpgradeNote } from '@/components/global/shared/SpecificationsUpgradeNote'
import { SpecificationTableCard } from '../../shared/SpecificationTableCard'
import { useT } from '@/lib/i18n/translate'

interface SiteRuntimeSpecificationCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
  isCloud?: boolean
}

export function SiteRuntimeSpecificationCard({
  projectId,
  siteId,
  site,
  isCloud = false,
}: SiteRuntimeSpecificationCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const { project } = useProject(projectId)
  const { data: specificationsData } = useSiteSpecifications(
    projectId,
    SpecificationType.Runtimes,
  )

  const specifications = useMemo(
    () => specificationsData?.specifications || [],
    [specificationsData],
  )

  const [runtimeSpecification, setRuntimeSpecification] = useState('')

  useEffect(() => {
    if (site) {
      setRuntimeSpecification(site.runtimeSpecification || '')
    }
  }, [site])

  const updateSiteMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Site>) => {
      if (!projectId || !siteId || !site)
        throw new Error('Project ID, Site ID, and Site are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update(buildSiteUpdateParams(site, updates))
    },
    onSuccess: (updated) => {
      toast.success(t('Specification updated successfully'))
      queryClient.setQueryData(
        ['site', 'project', projectId, siteId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update specification')))
    },
  })

  const handleSave = () => {
    updateSiteMutation.mutate({
      runtimeSpecification: runtimeSpecification || undefined,
    })
  }

  const hasChanges = runtimeSpecification !== (site?.runtimeSpecification || '')

  if (!isCloud || specifications.length === 0) {
    return null
  }

  const footerNote = hasUnavailableSpecifications(specifications) ? (
    <SpecificationsUpgradeNote orgId={project?.teamId} />
  ) : undefined

  return (
    <SpecificationTableCard
      title={t('Specification')}
      description={t(
        'CPU and memory allocated when your site handles requests, including server-side rendering (SSR).',
      )}
      scope="runtime-site"
      specs={specifications}
      selectedSlug={runtimeSpecification}
      onSelectedSlugChange={setRuntimeSpecification}
      hasChanges={hasChanges}
      isSaving={updateSiteMutation.isPending}
      onSave={handleSave}
      footerNote={footerNote}
    />
  )
}
