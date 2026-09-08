/**
 * Template Configuration View (Function)
 *
 * Configure and create a function from an Appwrite function template.
 * Create function, domain, variables, then createTemplateDeployment (tag).
 */

import { useState, useEffect, useMemo } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
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
import { Loader2, Key, Tag, GitBranch } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  ID,
  TemplateReferenceType,
  type Runtime,
} from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useFunctionTemplate,
  useFunctionSpecifications,
  useProject,
} from '@/lib/react-query/hooks'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { resolveTemplatePlaceholder } from '@/lib/template-placeholders'
import {
  getFirstEnabledSpecification,
  isSpecificationAllowedInPlan,
  hasUnavailableSpecifications,
  SpecificationType,
} from '@/lib/specifications'
import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import { useFunctionWizard } from './WizardContext'
import { FunctionDomainCard } from './_components/FunctionDomainCard'
import { VariablesSettingsCard } from '@/components/global/shared/VariablesSettingsCard'
import {
  ConnectRepositorySection,
  type ConnectRepositoryValue,
} from '@/components/global/shared/ConnectRepositorySection'
import { VCSDetectionType } from '@appwrite.io/console'
import type { FunctionWizardVariable } from './RepositoryConfigView'
import { useT } from '@/lib/i18n/translate'
import { buildVcsAuthUrl, type VcsProviderId } from '@/lib/vcs/providers'
import { validateVariables } from '@/lib/variables'

interface TemplateConfigViewProps {
  templateId: string
  runtimeFromSearch?: string
}

export function TemplateConfigView({
  templateId,
  runtimeFromSearch,
}: TemplateConfigViewProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { formData, updateFormData, installations, generateDomain } =
    useFunctionWizard()
  const { project } = useProject(projectId)

  const { data: template, isLoading: templateLoading } = useFunctionTemplate(
    projectId,
    templateId,
  )
  const { data: specificationsData } = useFunctionSpecifications(
    projectId,
    SpecificationType.Builds,
  )
  const specifications = useMemo(
    () => specificationsData?.specifications ?? [],
    [specificationsData],
  )

  const [functionName, setFunctionName] = useState('')
  const [functionId, setFunctionId] = useState<string | undefined>()
  const [runtime, setRuntime] = useState('')
  const [domain, setDomain] = useState('')
  const [domainValid, setDomainValid] = useState(false)
  const [variables, setVariables] = useState<FunctionWizardVariable[]>([])
  const [isPublic, setIsPublic] = useState(true)
  const [specification, setSpecification] = useState('')
  const [isDeploying, setIsDeploying] = useState(false)
  const [gitConnection, setGitConnection] = useState<'now' | 'later'>('later')

  const connectRepoValue: ConnectRepositoryValue = useMemo(
    () => ({
      installationId: formData.installationId,
      providerRepositoryId: formData.providerRepositoryId,
      repositoryName: formData.repositoryName,
      repositoryOwner: formData.repositoryOwner,
    }),
    [
      formData.installationId,
      formData.providerRepositoryId,
      formData.repositoryName,
      formData.repositoryOwner,
    ],
  )
  // Empty until BranchSelector resolves the repository's default branch.
  const [connectBranch, setConnectBranch] = useState(
    formData.providerBranch || '',
  )
  const [connectRootDir, setConnectRootDir] = useState(
    formData.providerRootDirectory || './',
  )

  const getVcsAuthUrl = useMemo(() => {
    return (provider: VcsProviderId = 'github') => {
      if (typeof window === 'undefined' || !projectId) return '#'
      const origin = window.location.origin
      const redirectUrl = `${origin}/projects/${projectId}/functions/create`
      const projectEndpoint = getApiEndpoint(project?.region)
      return buildVcsAuthUrl({
        endpoint: projectEndpoint,
        provider,
        projectId,
        successUrl: redirectUrl,
        failureUrl: redirectUrl,
      })
    }
  }, [projectId, project?.region])
  const getGitHubAuthUrl = getVcsAuthUrl('github')

  const handleConnectRepoValueChange = (next: ConnectRepositoryValue) => {
    updateFormData({
      installationId: next.installationId,
      providerRepositoryId: next.providerRepositoryId,
      repositoryName: next.repositoryName,
      repositoryOwner: next.repositoryOwner,
    })
  }

  useEffect(() => {
    if (specifications.length > 0 && !specification) {
      const first = getFirstEnabledSpecification(specifications)
      if (first?.slug) setSpecification(first.slug)
    }
  }, [specifications, specification])

  useEffect(() => {
    if (template) {
      if (!functionName) setFunctionName(template.name)
      // Pre-fill template variables and auto-replace placeholders
      if (template.variables?.length && variables.length === 0) {
        const apiEndpoint = getApiEndpoint(project?.region)
        const context = {
          apiEndpoint,
          projectId: projectId ?? '',
          projectName: project?.name ?? '',
        }
        const templateVars = template.variables.map((v) => ({
          key: v.name,
          value: resolveTemplatePlaceholder(v.value || '', context),
          secret: v.secret || false,
        }))
        setVariables(templateVars)
      }
      const firstRuntime = template.runtimes?.[0]
      const defaultRuntimeName =
        firstRuntime?.name ?? (template.runtimes?.[0] as { key?: string })?.key
      if (defaultRuntimeName && !runtime) {
        const resolved =
          runtimeFromSearch &&
          (() => {
            const base = runtimeFromSearch.toLowerCase().split('-')[0]
            const matching = (template.runtimes ?? []).filter((t) => {
              const n =
                (t as { name?: string }).name ??
                (t as { key?: string }).key ??
                ''
              return n.toLowerCase().split('-')[0] === base
            })
            if (matching.length === 0) return runtimeFromSearch
            if (matching.length === 1) {
              const r = matching[0]
              return (
                (r as { name?: string }).name ?? (r as { key?: string }).key
              )
            }
            const getVersion = (r: unknown) => {
              const name =
                (r as { name?: string }).name ??
                (r as { key?: string }).key ??
                ''
              const afterBase = name.split('-').slice(1).join('-')
              return afterBase.split('.').map((s) => parseInt(s, 10) || 0)
            }
            const latest = matching.reduce((a, b) => {
              const va = getVersion(a)
              const vb = getVersion(b)
              for (let i = 0; i < Math.max(va.length, vb.length); i++) {
                const na = va[i] ?? 0
                const nb = vb[i] ?? 0
                if (na !== nb) return nb > na ? b : a
              }
              return b
            })
            return (
              (latest as { name?: string }).name ??
              (latest as { key?: string }).key
            )
          })()
        setRuntime(resolved ?? defaultRuntimeName)
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [template, runtimeFromSearch])

  useEffect(() => {
    if (functionName && !domain) {
      setDomain(generateDomain(functionName))
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [functionName, generateDomain])

  const handleDeploy = async () => {
    if (!projectId || !template) {
      toast.error(t('Template not loaded'))
      return
    }
    if (!functionName || !runtime) {
      toast.error(t('Please fill in function name and runtime'))
      return
    }
    if (!domain.trim()) {
      toast.error(t('Please enter a domain'))
      return
    }
    if (gitConnection === 'now') {
      if (!formData.installationId || !formData.providerRepositoryId) {
        toast.error(t('Please select a repository'))
        return
      }
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

    const selectedRuntimeObj = (template.runtimes ?? []).find(
      (r) =>
        (r as { name?: string }).name === runtime ||
        (r as { key?: string }).key === runtime,
    ) as
      | {
          entrypoint?: string
          commands?: string
          providerRootDirectory?: string
        }
      | undefined

    const connectNow =
      gitConnection === 'now' &&
      formData.installationId &&
      formData.providerRepositoryId

    try {
      await projectSdk.functions.create({
        functionId: finalFunctionId,
        name: functionName.trim(),
        runtime: runtime as Runtime,
        execute: isPublic
          ? ['any']
          : template.permissions?.length
            ? template.permissions
            : [],
        events: template.events?.length ? template.events : undefined,
        schedule: template.cron || undefined,
        timeout: template.timeout ?? undefined,
        enabled: true,
        entrypoint: selectedRuntimeObj?.entrypoint,
        commands: selectedRuntimeObj?.commands,
        scopes: template.scopes?.length ? template.scopes : undefined,
        ...(connectNow
          ? {
              installationId: formData.installationId,
              providerRepositoryId: formData.providerRepositoryId,
              providerBranch: connectBranch,
              providerRootDirectory: connectRootDir || './',
              providerSilentMode: false,
            }
          : {
              providerBranch: 'main',
              providerSilentMode: false,
              providerRootDirectory: './',
            }),
        buildSpecification: specification || undefined,
      })

      await projectSdk.proxy.createFunctionRule({
        domain: domainTrimmed,
        functionId: finalFunctionId,
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

      // Use template deployment so the deployment has source code (same as sites).
      // Payload must match backend expectation: repository name, owner, rootDirectory path in repo (e.g. php/starter), tag reference (e.g. 0.2.*).
      if (!template.providerRepositoryId || !template.providerOwner) {
        toast.error(t('Template is missing repository information'))
        setIsDeploying(false)
        return
      }
      const runtimeRoot = selectedRuntimeObj?.providerRootDirectory?.trim()
      // Derive path when template does not provide it (e.g. appwrite/templates uses php/starter, node/starter).
      const rootDirectory =
        runtimeRoot || (runtime ? `${runtime.split('-')[0]}/starter` : './')
      const reference = template.providerVersion?.trim() || 'main'

      const deployment = await projectSdk.functions.createTemplateDeployment({
        functionId: finalFunctionId,
        repository: template.providerRepositoryId,
        owner: template.providerOwner,
        rootDirectory,
        type: TemplateReferenceType.Tag,
        reference,
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
      toast.error(
        err instanceof Error ? err.message : t('Failed to create function'),
      )
      setIsDeploying(false)
    }
  }

  const templateRuntimes = template?.runtimes ?? []

  const sidebarContent = template ? (
    <div className="rounded-xl border border-border bg-gradient-to-b from-card/80 to-card/40 backdrop-blur-sm overflow-hidden">
      <div className="px-5 py-4 border-b border-border/50">
        <div className="flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-muted to-muted/50 ring-1 ring-border/50">
            <RuntimeIcon
              runtime={runtime}
              className="h-5 w-5 text-muted-foreground"
            />
          </div>
          <div className="flex-1 min-w-0">
            <h3 className="text-[13px] font-semibold text-foreground truncate">
              {template.name}
            </h3>
            <p className="text-[11px] text-muted-foreground truncate">
              {template.tagline ||
                (template.providerOwner && template.providerRepositoryId
                  ? `${template.providerOwner}/${template.providerRepositoryId}`
                  : t('Template'))}
            </p>
          </div>
        </div>
      </div>
      <div className="px-5 py-4 space-y-3">
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
        {template.providerVersion && (
          <div className="flex items-center justify-between">
            <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
              <Tag className="h-3.5 w-3.5" />
              {t('Version')}
            </span>
            <code className="text-[12px] font-mono text-foreground bg-muted/50 px-2 py-0.5 rounded">
              {template.providerVersion}
            </code>
          </div>
        )}
        {(variables.length > 0 || (template.variables?.length ?? 0) > 0) && (
          <div className="flex items-center justify-between">
            <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
              <Key className="h-3.5 w-3.5" />
              {t('Variables')}
            </span>
            <span className="text-[12px] text-foreground">
              {variables.length} {t('configured')}
              {template.variables?.length
                ? ` · ${template.variables.length} ${t('in template')}`
                : ''}
            </span>
          </div>
        )}
      </div>
      {template.providerRepositoryId && (
        <div className="px-5 py-4 border-t border-border/50 flex gap-2">
          <Button
            variant="outline"
            size="sm"
            className="flex-1 h-9 text-[13px]"
            asChild
          >
            <a
              href={`https://github.com/${template.providerOwner || 'appwrite'}/${template.providerRepositoryId}`}
              target="_blank"
              rel="noopener noreferrer"
            >
              <GitBranch className="me-1.5 h-4 w-4" />
              {t('View source')}
            </a>
          </Button>
        </div>
      )}
      <div className="px-5 py-3 bg-muted/20 border-t border-border/50">
        <div className="flex items-center gap-2">
          <div className="h-2 w-2 rounded-full bg-emerald-500 animate-pulse" />
          <span className="text-[11px] text-muted-foreground">
            {t('Ready to deploy')}
          </span>
        </div>
      </div>
    </div>
  ) : null

  if (templateLoading || !template) {
    return (
      <WizardLayout
        title={t('Create function')}
        fallbackPath={`/projects/${projectId}/functions/create`}
        fullscreen
        maxWidth="max-w-[1400px]"
      >
        <div className="flex items-center justify-center py-12">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      </WizardLayout>
    )
  }

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
          <Button
            onClick={handleDeploy}
            disabled={
              isDeploying ||
              !functionName ||
              !runtime ||
              !domain.trim() ||
              !domainValid ||
              (gitConnection === 'now' &&
                (!formData.providerRepositoryId || !formData.installationId))
            }
          >
            {t('Create and deploy')}
          </Button>
        </>
      }
    >
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
            <Select value={runtime} onValueChange={setRuntime}>
              <SelectTrigger className="h-9 text-[13px]">
                <SelectValue placeholder={t('Select runtime')} />
              </SelectTrigger>
              <SelectContent>
                {templateRuntimes.map((r) => {
                  const rName = r.name || (r as { key?: string }).key
                  if (!rName) return null
                  return (
                    <SelectItem key={rName} value={rName}>
                      <div className="flex items-center gap-2">
                        <RuntimeIcon runtime={rName} size="sm" />
                        {rName}
                      </div>
                    </SelectItem>
                  )
                })}
              </SelectContent>
            </Select>
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

      {/* Git connection section – same as site template wizard */}
      <RadioGroup
        value={gitConnection}
        onValueChange={(value) => setGitConnection(value as 'now' | 'later')}
        className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6"
      >
        <Label
          htmlFor="git-now"
          className={cn(
            'relative flex items-start cursor-pointer rounded-xl border p-5 transition-all',
            gitConnection === 'now'
              ? 'border-foreground bg-card/80'
              : 'border-border bg-card/50 hover:border-border/80 hover:bg-card/60',
          )}
        >
          <RadioGroupItem value="now" id="git-now" className="mt-1 shrink-0" />
          <div className="ms-3 flex-1">
            <span className="text-[14px] font-medium text-foreground">
              {t('Connect your repository')}
            </span>
            <p className="mt-1.5 text-[12px] text-muted-foreground leading-relaxed">
              {t(
                'Clone this template into a new Git repository or link it to an existing one.',
              )}
            </p>
          </div>
        </Label>
        <Label
          htmlFor="git-later"
          className={cn(
            'relative flex items-start cursor-pointer rounded-xl border p-5 transition-all',
            gitConnection === 'later'
              ? 'border-foreground bg-card/80'
              : 'border-border bg-card/50 hover:border-border/80 hover:bg-card/60',
          )}
        >
          <RadioGroupItem
            value="later"
            id="git-later"
            className="mt-1 shrink-0"
          />
          <div className="ms-3 flex-1">
            <span className="text-[14px] font-medium text-foreground">
              {t('Connect later')}
            </span>
            <p className="mt-1.5 text-[12px] text-muted-foreground leading-relaxed">
              {t(
                'Deploy now and connect your version control later via CLI or Git integration in your function settings.',
              )}
            </p>
          </div>
        </Label>
      </RadioGroup>

      {gitConnection === 'now' && (
        <ConnectRepositorySection
          projectId={projectId}
          installations={installations}
          getGitHubAuthUrl={getGitHubAuthUrl}
          getVcsAuthUrl={getVcsAuthUrl}
          defaultRepositoryName={functionName || template?.name || ''}
          detectionType={VCSDetectionType.Runtime}
          value={connectRepoValue}
          onValueChange={handleConnectRepoValueChange}
          showBranchAndRoot={
            !!(formData.providerRepositoryId && formData.installationId)
          }
          branch={connectBranch}
          onBranchChange={(b) => {
            setConnectBranch(b)
            updateFormData({ providerBranch: b })
          }}
          rootDirectory={connectRootDir}
          onRootDirectoryChange={(r) => {
            setConnectRootDir(r)
            updateFormData({ providerRootDirectory: r })
          }}
          branchLabelTooltip={t(
            'Production branch for the repo linked to the function. Successful deployments from this branch get activated automatically.',
          )}
          rootDirectoryLabelTooltip={t(
            'Path to function code in the linked repo. Use the repository root (./) or a subdirectory that contains your function code.',
          )}
          rootDirectoryDescription={t(
            'Choose the directory containing your function code',
          )}
          emptyStateTitle={t('Connect Git repository')}
          emptyStateDescription={t(
            'Create and deploy a Function with a connected git repository.',
          )}
          className="mb-6"
        />
      )}

      {/* Environment variables – template vars or shared card */}
      {template.variables && template.variables.length > 0 ? (
        (() => {
          const requiredKeys = new Set(
            template.variables.filter((v) => v.required).map((v) => v.name),
          )
          const optionalKeys = new Set(
            template.variables.filter((v) => !v.required).map((v) => v.name),
          )
          const requiredVars = variables.filter((v) => requiredKeys.has(v.key))
          const optionalVars = variables.filter((v) => optionalKeys.has(v.key))

          const renderVariable = (
            variable: { key: string; value: string; secret: boolean },
            indexInFull: number,
          ) => {
            const templateVar = template.variables?.find(
              (v) => v.name === variable.key,
            )
            return (
              <div key={variable.key} className="space-y-2">
                <div className="flex items-center justify-between">
                  <Label className="text-[13px] font-mono">
                    {variable.key}
                    {templateVar?.required && (
                      <span className="text-destructive ms-1">*</span>
                    )}
                  </Label>
                  {templateVar?.secret && (
                    <span className="text-[10px] text-muted-foreground">
                      {t('Secret')}
                    </span>
                  )}
                </div>
                {templateVar?.description && (
                  <p
                    className="text-[11px] text-muted-foreground"
                    dangerouslySetInnerHTML={{
                      __html: templateVar.description,
                    }}
                  />
                )}
                <Input
                  value={variable.value}
                  onChange={(e) => {
                    const newVars = [...variables]
                    newVars[indexInFull] = {
                      ...variable,
                      value: e.target.value,
                    }
                    setVariables(newVars)
                  }}
                  placeholder={
                    templateVar?.placeholder ||
                    `${t('Enter')} ${variable.key}`
                  }
                  type={templateVar?.secret ? 'password' : 'text'}
                  className="h-9 text-[13px] font-mono"
                />
              </div>
            )
          }

          return (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  {t('Template variables')}
                </h3>
                <p className="text-[12px] text-muted-foreground mt-1">
                  {t('Configure the environment variables for this template')}
                </p>
              </div>
              <div className="border-t border-border" />
              <div className="px-6 py-4">
                <div className="space-y-3">
                  {requiredVars.map((variable) => {
                    const indexInFull = variables.findIndex(
                      (v) => v.key === variable.key,
                    )
                    return renderVariable(variable, indexInFull)
                  })}
                </div>
                {optionalVars.length > 0 && (
                  <div className="mt-4 border-t border-border pt-4">
                    <h4 className="text-[13px] font-medium text-muted-foreground mb-3">
                      {t('Optional variables')} ({optionalVars.length})
                    </h4>
                    <div className="space-y-3">
                      {optionalVars.map((variable) => {
                        const indexInFull = variables.findIndex(
                          (v) => v.key === variable.key,
                        )
                        return renderVariable(variable, indexInFull)
                      })}
                    </div>
                  </div>
                )}
              </div>
            </div>
          )
        })()
      ) : (
        <VariablesSettingsCard
          variant="wizard"
          variables={variables}
          onChange={setVariables}
        />
      )}
    </WizardLayout>
  )
}
