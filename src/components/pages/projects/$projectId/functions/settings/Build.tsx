import { useState, useEffect, useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
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
import { FunctionDeploymentRetentionCard } from './FunctionDeploymentRetentionCard'
import { FunctionBuildTriggersCard } from '../FunctionBuildTriggersCard'
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
    SpecificationType.Builds,
  )

  const [commands, setCommands] = useState('')
  const [buildSpecification, setBuildSpecification] = useState('')

  useEffect(() => {
    if (func) {
      setCommands(func.commands || '')
      setBuildSpecification(func.buildSpecification || '')
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

  const commandsDirty = commands !== (func?.commands || '')
  const specDirty = buildSpecification !== (func?.buildSpecification || '')

  const handleSaveCommands = () => {
    updateFunctionMutation.mutate({ commands })
  }

  const handleSaveSpecification = () => {
    updateFunctionMutation.mutate({
      buildSpecification: buildSpecification || undefined,
    })
  }

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

  const specFooterNote = hasUnavailableSpecifications(specifications) ? (
    <SpecificationsUpgradeNote orgId={project?.teamId} showContactSales />
  ) : undefined

  const cards: SettingsCardItem[] = [
    {
      id: 'commands',
      search: {
        title: 'Commands',
        description:
          'Commands run while your function deployment is being built and packaged.',
        keywords: ['install', 'build', 'package', 'npm'],
      },
      node: (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Commands')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t(
                'Commands run while your function deployment is being built and packaged.',
              )}
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <Label htmlFor="build-commands" className="text-[13px]">
              {t('Commands')}
            </Label>
            <Input
              id="build-commands"
              value={commands}
              onChange={(e) => setCommands(e.target.value)}
              placeholder="npm install"
              className="mt-2 h-9 font-mono text-[13px]"
            />
            <p className="mt-1 text-[12px] text-muted-foreground">
              {t('Commands to run during function build.')}
            </p>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={!commandsDirty || updateFunctionMutation.isPending}
              onClick={handleSaveCommands}
            >
              {t('Update')}
            </Button>
          </div>
        </div>
      ),
    },
    {
      id: 'triggers',
      search: {
        title: 'Triggers',
        description:
          'Control which branch pushes and file changes trigger automatic deployments.',
        keywords: [
          'git',
          'branch',
          'path',
          'glob',
          'filter',
          'deploy',
          'pattern',
        ],
      },
      node: <FunctionBuildTriggersCard func={func} />,
    },
    {
      id: 'deployment-retention',
      search: {
        title: 'Retention',
        description:
          'Keep active deployments and choose when inactive deployments are deleted.',
        keywords: [
          'deployment',
          'retention',
          'delete',
          'inactive',
          'forever',
          'cleanup',
        ],
      },
      node: (
        <FunctionDeploymentRetentionCard
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
                'CPU and memory allocated on the build worker while your function image is produced.',
              keywords: ['vcpu', 'memory', 'worker', 'cpu'],
            },
            node: (
              <SpecificationTableCard
                title={t('Specification')}
                description={t(
                  'CPU and memory allocated on the build worker while your function image is produced.',
                )}
                scope="build"
                specs={specifications}
                selectedSlug={buildSpecification}
                onSelectedSlugChange={setBuildSpecification}
                hasChanges={specDirty}
                isSaving={updateFunctionMutation.isPending}
                onSave={handleSaveSpecification}
                footerNote={specFooterNote}
              />
            ),
          } satisfies SettingsCardItem,
        ]
      : []),
  ]

  return <SettingsCardsList cards={cards} />
}
