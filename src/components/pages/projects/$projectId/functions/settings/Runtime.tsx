import { useState, useEffect, useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
import {
  useProject,
  useProjectFunction,
  useFunctionSpecifications,
  buildFunctionUpdateParams,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import {
  hasUnavailableSpecifications,
  SpecificationType,
} from '@/lib/specifications'
import { SpecificationsUpgradeNote } from '@/components/global/shared/SpecificationsUpgradeNote'
import { SpecificationTableCard } from '../../shared/SpecificationTableCard'
import { FunctionImageCard } from './FunctionImageCard'
import { FunctionTimeoutCard } from './FunctionTimeoutCard'
import { FunctionLoggingCard } from './FunctionLoggingCard'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const t = useT()
  const { projectId, functionId } = useParams({ strict: false })
  const queryClient = useQueryClient()
  const { project } = useProject(projectId)

  const { data: func, isLoading: funcLoading } = useProjectFunction(
    projectId,
    functionId,
  )
  const { data: specificationsData } = useFunctionSpecifications(
    projectId,
    SpecificationType.Runtimes,
  )

  const [runtimeSpecification, setRuntimeSpecification] = useState('')

  useEffect(() => {
    if (func) {
      setRuntimeSpecification(func.runtimeSpecification || '')
    }
  }, [func])

  const specifications = useMemo(
    () => specificationsData?.specifications || [],
    [specificationsData],
  )

  const updateFunctionMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Function>) => {
      if (!projectId || !functionId || !func)
        throw new Error('Project ID, Function ID, and Function are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update(
        buildFunctionUpdateParams(func, updates),
      )
    },
    onSuccess: (updated) => {
      toast.success(t('Function updated successfully'))
      queryClient.setQueryData(
        ['function', 'project', projectId, functionId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['functions', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(
        error instanceof Error ? error.message : t('Failed to update function'),
      )
    },
  })

  const handleSaveSpecification = () => {
    updateFunctionMutation.mutate({
      runtimeSpecification: runtimeSpecification || undefined,
    })
  }

  const specDirty = runtimeSpecification !== (func?.runtimeSpecification || '')

  if (funcLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">
          {t('Loading settings...')}
        </p>
      </div>
    )
  }

  if (!func) return null

  const cards: SettingsCardItem[] = [
    {
      id: 'image',
      search: { title: 'Image', keywords: ['runtime', 'docker', 'container'] },
      node: (
        <FunctionImageCard
          projectId={projectId}
          functionId={functionId}
          func={func}
        />
      ),
    },
    {
      id: 'timeout',
      search: { title: 'Timeout', keywords: ['execute', 'seconds', 'limit'] },
      node: (
        <FunctionTimeoutCard
          projectId={projectId}
          functionId={functionId}
          func={func}
        />
      ),
    },
    {
      id: 'logging',
      search: { title: 'Logging', keywords: ['logs', 'stdout', 'stderr'] },
      node: (
        <FunctionLoggingCard
          projectId={projectId}
          functionId={functionId}
          func={func}
        />
      ),
    },
    ...(specifications.length > 0
      ? [
          {
            id: 'specification',
            search: {
              title: 'Specification',
              description:
                'CPU and memory available to each function execution at runtime.',
              keywords: ['vcpu', 'memory', 'cpu', 'resources'],
            },
            node: (
              <SpecificationTableCard
                title={t('Specification')}
                description={t(
                  'CPU and memory available to each function execution at runtime.',
                )}
                scope="runtime-function"
                specs={specifications}
                selectedSlug={runtimeSpecification}
                onSelectedSlugChange={setRuntimeSpecification}
                hasChanges={specDirty}
                isSaving={updateFunctionMutation.isPending}
                onSave={handleSaveSpecification}
                footerNote={
                  hasUnavailableSpecifications(specifications) ? (
                    <SpecificationsUpgradeNote
                      orgId={project?.teamId}
                      showContactSales
                    />
                  ) : undefined
                }
              />
            ),
          } satisfies SettingsCardItem,
        ]
      : []),
  ]

  return <SettingsCardsList cards={cards} />
}
