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
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ID, TemplateReferenceType, type Runtime } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useFunctionTemplate,
  useFunctionSpecifications,
} from '@/lib/react-query/hooks'
import {
  getFirstEnabledSpecification,
  isSpecificationAllowedInPlan,
  hasUnavailableSpecifications,
} from '@/lib/specifications'
import { useFunctionWizard } from './WizardContext'
import { DomainInput } from '@/components/global/shared/DomainInput'
import { EnvironmentVariablesCard } from '@/components/global/shared/EnvironmentVariablesCard'
import type { FunctionWizardVariable } from './RepositoryConfigView'

interface TemplateConfigViewProps {
  templateId: string
  runtimeFromSearch?: string
}

export function TemplateConfigView({
  templateId,
  runtimeFromSearch,
}: TemplateConfigViewProps) {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { generateDomain, baseDomain } = useFunctionWizard()

  const { data: template, isLoading: templateLoading } = useFunctionTemplate(
    projectId,
    templateId,
  )
  const { data: specificationsData } = useFunctionSpecifications(projectId)
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

  useEffect(() => {
    if (specifications.length > 0 && !specification) {
      const first = getFirstEnabledSpecification(specifications)
      if (first?.slug) setSpecification(first.slug)
    }
  }, [specifications, specification])

  useEffect(() => {
    if (template) {
      if (!functionName) setFunctionName(template.name)
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
      toast.error('Template not loaded')
      return
    }
    if (!functionName || !runtime) {
      toast.error('Please fill in function name and runtime')
      return
    }
    if (!domain.trim()) {
      toast.error('Please enter a domain')
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
        providerBranch: 'main',
        providerSilentMode: false,
        providerRootDirectory: './',
        specification: specification || undefined,
      })

      await projectSdk.proxy.createFunctionRule({
        domain: domainTrimmed,
        functionId: finalFunctionId,
      })

      for (const v of variables) {
        if (!v.key.trim()) continue
        await projectSdk.functions.createVariable({
          functionId: finalFunctionId,
          key: v.key.trim(),
          value: v.value,
          secret: v.secret,
        })
      }

      // Use template deployment so the deployment has source code (same as sites).
      // Payload must match backend expectation: repository name, owner, rootDirectory path in repo (e.g. php/starter), tag reference (e.g. 0.2.*).
      if (!template.providerRepositoryId || !template.providerOwner) {
        toast.error('Template is missing repository information')
        setIsDeploying(false)
        return
      }
      const runtimeRoot = selectedRuntimeObj?.providerRootDirectory?.trim()
      // Derive path when template does not provide it (e.g. appwrite/templates uses php/starter, node/starter).
      const rootDirectory =
        runtimeRoot || (runtime ? `${runtime.split('-')[0]}/starter` : './')
      const reference = template.providerVersion?.trim() || 'main'

      await projectSdk.functions.createTemplateDeployment({
        functionId: finalFunctionId,
        repository: template.providerRepositoryId,
        owner: template.providerOwner,
        rootDirectory,
        type: TemplateReferenceType.Tag,
        reference,
        activate: true,
      })

      await queryClient.refetchQueries({
        queryKey: ['functions', 'project', projectId],
      })

      toast.success('Function created')
      navigate({
        to: '/projects/$projectId/functions/$functionId',
        params: { projectId, functionId: finalFunctionId },
      })
    } catch (err: unknown) {
      toast.error(
        err instanceof Error ? err.message : 'Failed to create function',
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
                  : 'Template')}
            </p>
          </div>
        </div>
      </div>
      <div className="px-5 py-4 space-y-3">
        {runtime && (
          <div className="flex items-center justify-between">
            <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
              Runtime
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
              Version
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
              Variables
            </span>
            <span className="text-[12px] text-foreground">
              {variables.length} configured
              {template.variables?.length
                ? ` · ${template.variables.length} in template`
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
              <GitBranch className="mr-1.5 h-4 w-4" />
              View source
            </a>
          </Button>
        </div>
      )}
      <div className="px-5 py-3 bg-muted/20 border-t border-border/50">
        <div className="flex items-center gap-2">
          <div className="h-2 w-2 rounded-full bg-emerald-500 animate-pulse" />
          <span className="text-[11px] text-muted-foreground">
            Ready to deploy
          </span>
        </div>
      </div>
    </div>
  ) : null

  if (templateLoading || !template) {
    return (
      <WizardLayout
        title="Create function"
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
      title="Create function"
      showBackButton
      backButtonLabel="Back"
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
            Cancel
          </Button>
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
            {isDeploying ? (
              <>
                <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                Creating...
              </>
            ) : (
              'Create and deploy'
            )}
          </Button>
        </>
      }
    >
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden mb-6">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">Details</h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          <div className="space-y-2">
            <Label htmlFor="function-name" className="text-[13px]">
              Function name
            </Label>
            <Input
              id="function-name"
              value={functionName}
              onChange={(e) => setFunctionName(e.target.value)}
              placeholder="My function"
              className="h-9 text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label className="text-[13px]">Function ID</Label>
            <IdInput
              value={functionId}
              onChange={setFunctionId}
              placeholder="Auto-generated"
            />
          </div>
          <div className="space-y-2">
            <Label className="text-[13px]">Runtime</Label>
            <Select value={runtime} onValueChange={setRuntime}>
              <SelectTrigger className="h-9 text-[13px]">
                <SelectValue placeholder="Select runtime" />
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
              <Label className="text-[13px]">Public</Label>
              <p className="text-[11px] text-muted-foreground">
                Allow anyone to execute this function (execute role: any)
              </p>
            </div>
            <Switch checked={isPublic} onCheckedChange={setIsPublic} />
          </div>
          {specifications.length > 0 && (
            <div className="space-y-2">
              <Label htmlFor="specification" className="text-[13px]">
                Compute
              </Label>
              <Select
                value={specification || undefined}
                onValueChange={setSpecification}
              >
                <SelectTrigger id="specification" className="h-9 text-[13px]">
                  <SelectValue placeholder="Select specification" />
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
                Runtime specification for your function
              </p>
              {hasUnavailableSpecifications(specifications) && (
                <p className="text-[11px] text-muted-foreground">
                  Upgrade your plan to unlock additional specifications.
                </p>
              )}
            </div>
          )}
        </div>
      </div>

      {/* Domain card – same structure and validation as sites */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden mb-6">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">Domain</h3>
          <p className="text-[12px] text-muted-foreground mt-1">
            Your function will be reachable at this URL
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <DomainInput
            value={domain}
            onChange={setDomain}
            onValidChange={setDomainValid}
            baseDomain={baseDomain}
          />
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/20">
          <p className="text-[11px] text-muted-foreground leading-relaxed">
            After deployment you can connect a custom domain via your function
            settings.{' '}
            <a
              href="https://appwrite.io/docs/functions"
              target="_blank"
              rel="noopener noreferrer"
              className="text-foreground hover:underline font-medium"
            >
              Learn more →
            </a>
          </p>
        </div>
      </div>

      {/* Environment variables – shared card */}
      <EnvironmentVariablesCard variables={variables} onChange={setVariables} />
    </WizardLayout>
  )
}
