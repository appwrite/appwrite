/**
 * Repository Configuration View (Function)
 *
 * Configure and create a function from a selected Git repository.
 * Runtime detection, branch/root, domain, variables, then create + VCS deployment.
 */

import { useState, useEffect, useMemo } from 'react'
import { useParams, useNavigate, Link } from '@tanstack/react-router'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { IdInput } from '@/components/ui/id-input'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { BranchSelector } from '@/components/global/shared/BranchSelector'
import { RootDirectoryPicker } from '@/components/global/shared/RootDirectoryPicker'
import { FunctionDomainCard } from './_components/FunctionDomainCard'
import { VariablesSettingsCard } from '@/components/global/shared/VariablesSettingsCard'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Loader2,
  GitBranch,
  Key,
  FolderOpen,
  Lock,
  Globe,
  ExternalLink,
} from 'lucide-react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ID, VCSDetectionType, VCSReferenceType } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useRepository,
  useProject,
  useProjectRuntimes,
  useFunctionSpecifications,
} from '@/lib/react-query/hooks'
import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import { VcsInstallationErrorAlert } from '@/components/global/shared/VcsInstallationError'
import { getVcsInstallationErrorKind } from '@/lib/utils/error-formatting'
import { useVcsInstallationReconnect } from '@/lib/vcs/use-installation-reconnect'
import { cn } from '@/lib/utils'
import {
  getFirstEnabledSpecification,
  isSpecificationAllowedInPlan,
  hasUnavailableSpecifications,
  SpecificationType,
} from '@/lib/specifications'
import { useFunctionWizard } from './WizardContext'
import { useT } from '@/lib/i18n/translate'
import { getVcsProvider } from '@/lib/vcs/providers'
import { validateVariables } from '@/lib/variables'

export interface FunctionWizardVariable {
  key: string
  value: string
  secret: boolean
}

interface RepositoryConfigViewProps {
  repositoryParam: string
  installationIdFromSearch?: string
  providerRepositoryIdFromSearch?: string
}

export function RepositoryConfigView({
  repositoryParam,
  installationIdFromSearch,
  providerRepositoryIdFromSearch,
}: RepositoryConfigViewProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const { project } = useProject(projectId)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { formData, updateFormData, generateDomain } = useFunctionWizard()

  const [repoOwner, repoName] = useMemo(() => {
    const decoded = decodeURIComponent(repositoryParam)
    const parts = decoded.split('/')
    return [parts[0] || '', parts[1] || '']
  }, [repositoryParam])

  const installationId = formData.installationId || installationIdFromSearch
  const providerRepositoryId =
    formData.providerRepositoryId || providerRepositoryIdFromSearch

  useEffect(() => {
    if (installationIdFromSearch && !formData.installationId) {
      updateFormData({ installationId: installationIdFromSearch })
    }
    if (providerRepositoryIdFromSearch && !formData.providerRepositoryId) {
      updateFormData({ providerRepositoryId: providerRepositoryIdFromSearch })
    }
  }, [
    installationIdFromSearch,
    providerRepositoryIdFromSearch,
    formData.installationId,
    formData.providerRepositoryId,
    updateFormData,
  ])

  const [functionName, setFunctionName] = useState(
    formData.functionName || repoName,
  )
  const [functionId, setFunctionId] = useState<string | undefined>()
  const [runtime, setRuntime] = useState(formData.runtime || '')
  const [entrypoint, setEntrypoint] = useState('')
  const [commands, setCommands] = useState('')
  // Empty so BranchSelector resolves it from the repository.
  const [branch, setBranch] = useState('')
  const [rootDirectory, setRootDirectory] = useState('./')
  const [silentMode, setSilentMode] = useState(false)
  const [variables, setVariables] = useState<FunctionWizardVariable[]>([])
  const [domain, setDomain] = useState('')
  const [domainValid, setDomainValid] = useState(false)
  const [isPublic, setIsPublic] = useState(true)
  const [specification, setSpecification] = useState('')
  const [isDeploying, setIsDeploying] = useState(false)

  const {
    data: repository,
    error: repositoryError,
    isFetching: repositoryFetching,
    refetch: refetchRepository,
  } = useRepository(
    projectId,
    installationId || null,
    providerRepositoryId || null,
  )
  const { Icon: RepositoryProviderIcon, label: repositoryProviderLabel } =
    getVcsProvider(repository?.provider)
  const { data: runtimesData } = useProjectRuntimes(projectId)
  const { data: specificationsData } = useFunctionSpecifications(
    projectId,
    SpecificationType.Builds,
  )
  const runtimes = runtimesData?.runtimes ?? []
  const specifications = useMemo(
    () => specificationsData?.specifications ?? [],
    [specificationsData],
  )

  useEffect(() => {
    if (specifications.length > 0 && !specification) {
      const first = getFirstEnabledSpecification(specifications)
      if (first?.slug) setSpecification(first.slug)
    }
  }, [specifications, specification])

  const detectRuntimeMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !installationId || !providerRepositoryId) {
        throw new Error('Missing required parameters')
      }
      const projectSdk = sdk.forProject(projectId)
      const result = await projectSdk.vcs.createRepositoryDetection({
        installationId,
        providerRepositoryId,
        type: VCSDetectionType.Runtime,
        providerRootDirectory: rootDirectory,
      })
      return result as Models.DetectionFramework & {
        runtime?: string
        entrypoint?: string
        commands?: string
      }
    },
    onSuccess: (data) => {
      const r = data as {
        runtime?: string
        entrypoint?: string
        commands?: string
      }
      if (r.runtime) setRuntime(r.runtime)
      if (r.entrypoint != null) setEntrypoint(r.entrypoint)
      if (r.commands != null) setCommands(r.commands)
    },
  })

  useEffect(() => {
    if (installationId && providerRepositoryId && !runtime) {
      detectRuntimeMutation.mutate()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [installationId, providerRepositoryId])

  // Both calls refresh the installation token, so either one can be the first
  // to report that the installation itself is what broke. Left unread, runtime
  // detection just silently gives up and the step looks ready to submit.
  const installationErrorKind =
    getVcsInstallationErrorKind(repositoryError) ??
    getVcsInstallationErrorKind(detectRuntimeMutation.error)

  // Only a dead token can block submission. A transient failure clears on
  // retry, and runtime detection failing on its own still leaves the manual
  // runtime picker, so neither should strand a user who could otherwise create.
  const createBlocked =
    getVcsInstallationErrorKind(repositoryError) === 'reconnect'

  // The search params carry the repository, so the current URL is where the
  // user should land after re-authorizing.
  const {
    provider: installationProvider,
    organization: installationOrganization,
    reconnectUrl,
  } = useVcsInstallationReconnect(projectId, installationId)

  const handleInstallationRetry = () => {
    refetchRepository()
    if (installationId && providerRepositoryId) {
      detectRuntimeMutation.mutate()
    }
  }

  useEffect(() => {
    if (functionName && !domain) {
      setDomain(generateDomain(functionName))
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [functionName, generateDomain])

  const handleDeploy = async () => {
    if (!projectId || !functionName || !runtime) {
      toast.error(t('Please fill in function name and runtime'))
      return
    }
    if (!domain.trim()) {
      toast.error(t('Please enter a domain'))
      return
    }
    if (!installationId || !providerRepositoryId) {
      toast.error(t('Missing repository connection'))
      return
    }

    // Reject an unusable key before the resource is created, so a rejected
    // variable cannot leave a half-configured function behind. Only the rows
    // that get written are checked -- the keyless ones are skipped below.
    const validationError = validateVariables(
      variables.filter((v) => v.key.trim()),
    )
    if (validationError) {
      toast.error(validationError)
      return
    }

    setIsDeploying(true)
    const projectSdk = sdk.forProject(projectId)
    const finalFunctionId = (functionId?.trim() || ID.unique()) as string
    const domainTrimmed = domain.toLowerCase().trim()

    try {
      await projectSdk.functions.create({
        functionId: finalFunctionId,
        name: functionName.trim(),
        runtime: runtime as unknown,
        execute: isPublic ? ['any'] : [],
        enabled: true,
        entrypoint: entrypoint.trim() || undefined,
        commands: commands.trim() || undefined,
        installationId,
        providerRepositoryId,
        providerBranch: branch,
        providerSilentMode: silentMode,
        providerRootDirectory: rootDirectory || undefined,
        buildSpecification: specification || undefined,
      })

      await projectSdk.proxy.createFunctionRule({
        domain: domainTrimmed,
        functionId: finalFunctionId,
        branch,
      })

      for (const v of variables) {
        if (!v.key.trim()) continue
        await projectSdk.functions.createVariable({
          functionId: finalFunctionId,
          variableId: ID.unique(),
          key: v.key.trim(),
          value: v.value,
          secret: v.secret,
        })
      }

      const deployment = await projectSdk.functions.createVcsDeployment({
        functionId: finalFunctionId,
        type: VCSReferenceType.Branch,
        reference: branch,
        activate: true,
      })

      updateFormData({
        createdFunctionId: finalFunctionId,
        createdDeploymentId: deployment.$id,
      })

      await queryClient.refetchQueries({
        queryKey: ['functions', 'project', projectId],
      })

      navigate({
        to: '/projects/$projectId/functions/create/deploying',
        params: { projectId },
        search: { functionId: finalFunctionId, deploymentId: deployment.$id },
      })
    } catch (err: unknown) {
      toast.error(err?.message || t('Failed to create function'))
      setIsDeploying(false)
    }
  }

  const sidebarContent = (
    <div className="rounded-xl border border-border bg-gradient-to-b from-card/80 to-card/40 backdrop-blur-sm overflow-hidden">
      <div className="px-5 py-4 border-b border-border/50">
        <div className="flex items-center gap-3">
          <div className="relative">
            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-muted to-muted/50 ring-1 ring-border/50">
              <RuntimeIcon
                runtime={runtime}
                className="h-5 w-5 text-muted-foreground"
              />
            </div>
            <div className="absolute -bottom-1 -end-1 flex h-5 w-5 items-center justify-center rounded-full bg-background ring-2 ring-background">
              <RepositoryProviderIcon className="h-3 w-3 text-muted-foreground" />
            </div>
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2">
              <h3 className="text-[13px] font-semibold text-foreground truncate">
                {repository?.name || repoName}
              </h3>
              {repository?.private !== undefined &&
                (repository.private ? (
                  <Lock className="h-3 w-3 text-muted-foreground shrink-0" />
                ) : (
                  <Globe className="h-3 w-3 text-muted-foreground shrink-0" />
                ))}
            </div>
            <p className="text-[11px] text-muted-foreground truncate">
              {repository
                ? `${repository.organization}/${repository.name}`
                : `${repoOwner}/${repoName}`}
              {repository?.pushedAt && (
                <>
                  <span className="mx-1.5">·</span>
                  <span>
                    {t('Updated')} <DateTooltip date={repository.pushedAt} />
                  </span>
                </>
              )}
            </p>
          </div>
          {repository?.url && (
            <a
              href={repository.url}
              target="_blank"
              rel="noopener noreferrer"
              className="flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/50 transition-colors"
            >
              <ExternalLink className="h-4 w-4" />
            </a>
          )}
        </div>
      </div>
      <div className="px-5 py-4 space-y-3">
        <div className="flex items-center justify-between">
          <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
            <GitBranch className="h-3.5 w-3.5" />
            {t('Branch')}
          </span>
          <code className="text-[12px] font-mono text-foreground bg-muted/50 px-2 py-0.5 rounded">
            {branch || repository?.defaultBranch || 'main'}
          </code>
        </div>
        <div className="flex items-center justify-between">
          <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
            <FolderOpen className="h-3.5 w-3.5" />
            {t('Root directory')}
          </span>
          <code className="text-[12px] font-mono text-foreground bg-muted/50 px-2 py-0.5 rounded max-w-[120px] truncate">
            {rootDirectory || './'}
          </code>
        </div>
        {runtime && (
          <div className="flex items-center justify-between">
            <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
              {t('Runtime')}
            </span>
            <span className="flex items-center gap-1.5 text-[12px] text-foreground">
              <RuntimeIcon runtime={runtime} size="sm" />
              <span className="truncate max-w-[100px]">{runtime}</span>
            </span>
          </div>
        )}
        {variables.length > 0 && (
          <div className="flex items-center justify-between">
            <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
              <Key className="h-3.5 w-3.5" />
              {t('Variables')}
            </span>
            <span className="text-[12px] text-foreground">
              {variables.length} {t('configured')}
            </span>
          </div>
        )}
      </div>
      <div className="px-5 py-3 bg-muted/20 border-t border-border/50">
        <div className="flex items-center gap-2">
          <div
            className={cn(
              'h-2 w-2 rounded-full',
              installationErrorKind
                ? 'bg-amber-500'
                : 'bg-emerald-500 animate-pulse',
            )}
          />
          <span className="text-[11px] text-muted-foreground">
            {installationErrorKind
              ? t('Git connection needs attention')
              : t('Ready to deploy')}
          </span>
        </div>
      </div>
    </div>
  )

  return (
    <WizardLayout
      title={t('Create function')}
      showBackButton
      backButtonLabel={t('Back')}
      fallbackPath={`/projects/${projectId}/functions`}
      onClose={() =>
        navigate({
          to: '/projects/$projectId/functions',
          params: { projectId: projectId! },
        })
      }
      onBack={() =>
        navigate({
          to: '/projects/$projectId/functions/create',
          params: { projectId: projectId! },
        })
      }
      fullscreen
      maxWidth="max-w-[1400px]"
      footerAlign="right"
      sidebar={sidebarContent}
      footer={
        <>
          <Button
            variant="outline"
            onClick={() =>
              navigate({
                to: '/projects/$projectId/functions',
                params: { projectId: projectId! },
              })
            }
            disabled={isDeploying}
          >
            {t('Cancel')}
          </Button>
          {createBlocked ? (
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="inline-block">
                  <Button disabled>{t('Create and deploy')}</Button>
                </span>
              </TooltipTrigger>
              <TooltipContent>
                {t(
                  'Reconnect the Git installation before creating this function.',
                )}
              </TooltipContent>
            </Tooltip>
          ) : (
            <Button
              onClick={handleDeploy}
              disabled={
                isDeploying ||
                !functionName ||
                !runtime ||
                !domain.trim() ||
                !domainValid
              }
            >
              {t('Create and deploy')}
            </Button>
          )}
        </>
      }
    >
      {installationErrorKind && (
        <VcsInstallationErrorAlert
          kind={installationErrorKind}
          provider={installationProvider}
          organization={installationOrganization}
          reconnectUrl={reconnectUrl}
          onRetry={handleInstallationRetry}
          isRetrying={repositoryFetching || detectRuntimeMutation.isPending}
          className="mb-6"
        >
          {t(
            'Appwrite could not read this repository, so the runtime was not detected and this function cannot be created from Git yet.',
          )}
        </VcsInstallationErrorAlert>
      )}

      <div className="rounded-xl border border-border bg-card/50 p-4 mb-6">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
              <RepositoryProviderIcon className="h-5 w-5 text-muted-foreground" />
            </div>
            <div>
              <p className="text-[13px] font-medium text-foreground">
                {repoOwner}/{repoName}
              </p>
              <p className="text-[11px] text-muted-foreground">
                {t(`${repositoryProviderLabel} repository`)}
              </p>
            </div>
          </div>
          <Button
            variant="outline"
            size="sm"
            asChild
            className="h-8 text-[12px]"
          >
            <Link
              to="/projects/$projectId/functions/create"
              params={{ projectId: projectId! }}
            >
              {t('Change')}
            </Link>
          </Button>
        </div>
      </div>

      {/* Details card */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden mb-6">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Details')}
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          <div className="space-y-2">
            <Label htmlFor="function-name" className="text-[13px]">
              {t('Function name')}
            </Label>
            <Input
              id="function-name"
              value={functionName}
              onChange={(e) => setFunctionName(e.target.value)}
              placeholder={t('My function')}
              className="h-9 text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label className="text-[13px]">{t('Function ID')}</Label>
            <IdInput
              value={functionId}
              onChange={setFunctionId}
              placeholder={t('Auto-generated')}
            />
          </div>
          <div className="space-y-2">
            <Label className="text-[13px]">{t('Runtime')}</Label>
            {detectRuntimeMutation.isPending ? (
              <div className="flex items-center gap-2">
                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                <span className="text-[13px] text-muted-foreground">
                  {t('Detecting runtime...')}
                </span>
              </div>
            ) : (
              <Select value={runtime} onValueChange={setRuntime}>
                <SelectTrigger className="h-9 text-[13px]">
                  <SelectValue placeholder={t('Select runtime')} />
                </SelectTrigger>
                <SelectContent>
                  {runtimes.map((r) => (
                    <SelectItem key={r.$id} value={r.$id || r.key}>
                      <div className="flex items-center gap-2">
                        <RuntimeIcon runtime={r.$id || r.key} size="sm" />
                        <span>
                          {r.name} {r.version}
                        </span>
                      </div>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          </div>
          <div className="flex items-center justify-between">
            <div>
              <Label className="text-[13px]">{t('Public')}</Label>
              <p className="text-[11px] text-muted-foreground">
                {t('Allow anyone to execute this function (execute role: any)')}
              </p>
            </div>
            <Switch checked={isPublic} onCheckedChange={setIsPublic} />
          </div>
          {specifications.length > 0 && (
            <div className="space-y-2">
              <Label htmlFor="specification" className="text-[13px]">
                {t('Compute')}
              </Label>
              <Select
                value={specification || undefined}
                onValueChange={setSpecification}
              >
                <SelectTrigger id="specification" className="h-9 text-[13px]">
                  <SelectValue placeholder={t('Select specification')} />
                </SelectTrigger>
                <SelectContent>
                  {specifications
                    .filter((s) => s.slug?.trim())
                    .map((spec) => (
                      <SelectItem
                        key={spec.slug}
                        value={spec.slug}
                        disabled={!isSpecificationAllowedInPlan(spec)}
                      >
                        {spec.cpus} CPU, {spec.memory}MB RAM
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
              <p className="text-[11px] text-muted-foreground">
                {t('Runtime specification for your function')}
              </p>
              {hasUnavailableSpecifications(specifications) && (
                <p className="text-[11px] text-muted-foreground">
                  <UpgradePlanLink orgId={project?.teamId} />{' '}
                  {t('to unlock additional specifications.')}
                </p>
              )}
            </div>
          )}
        </div>
      </div>

      <FunctionDomainCard
        domain={domain}
        setDomain={setDomain}
        domainValid={domainValid}
        setDomainValid={setDomainValid}
      />

      {/* Production branch – when Git is used */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden mb-6">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Production branch')}
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          <BranchSelector
            projectId={projectId}
            installationId={installationId}
            providerRepositoryId={providerRepositoryId}
            value={branch}
            onChange={setBranch}
            label={t('Branch')}
          />
          <RootDirectoryPicker
            projectId={projectId}
            installationId={installationId}
            providerRepositoryId={providerRepositoryId}
            branch={branch || 'main'}
            value={rootDirectory}
            onChange={setRootDirectory}
            label={t('Root directory')}
            description={t('Directory containing your function code')}
          />
          <div className="flex items-center justify-between">
            <div>
              <Label className="text-[13px]">{t('Silent mode')}</Label>
              <p className="text-[11px] text-muted-foreground">
                {t('Disable automated comments on repository commits')}
              </p>
            </div>
            <Switch checked={silentMode} onCheckedChange={setSilentMode} />
          </div>
        </div>
      </div>

      {/* Build settings – accordion like sites */}
      <Accordion
        type="single"
        collapsible
        className="rounded-xl border border-border bg-card/50 overflow-hidden mb-6"
      >
        <AccordionItem value="build-settings" className="border-none">
          <AccordionTrigger className="px-6 py-4 hover:no-underline hover:bg-transparent cursor-pointer">
            <span className="text-[15px] font-semibold text-foreground">
              {t('Build')}
            </span>
          </AccordionTrigger>
          <AccordionContent className="px-6 pb-4 pt-0 border-t border-border">
            <div className="space-y-4 pt-4">
              <div className="space-y-2">
                <Label htmlFor="entrypoint" className="text-[13px]">
                  {t('Entrypoint')}
                </Label>
                <Input
                  id="entrypoint"
                  value={entrypoint}
                  onChange={(e) => setEntrypoint(e.target.value)}
                  placeholder="src/main.js"
                  className="h-9 text-[13px] font-mono"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="commands" className="text-[13px]">
                  {t('Build commands')}
                </Label>
                <Input
                  id="commands"
                  value={commands}
                  onChange={(e) => setCommands(e.target.value)}
                  placeholder="npm install && npm run build"
                  className="h-9 text-[13px] font-mono"
                />
              </div>
            </div>
          </AccordionContent>
        </AccordionItem>
      </Accordion>

      {/* Environment variables – shared card */}
      <VariablesSettingsCard
        variant="wizard"
        variables={variables}
        onChange={setVariables}
      />
    </WizardLayout>
  )
}
