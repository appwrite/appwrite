/**
 * Template Configuration View Component
 *
 * Configuration screen for deploying a site from a template.
 * Handles site details, Git connection options, and template variables.
 */

import { useState, useEffect, useMemo } from 'react'
import { useParams, useNavigate, Link } from '@tanstack/react-router'
import { useTheme } from 'next-themes'
import { cn } from '@/lib/utils'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
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
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import {
  ExternalLink,
  Loader2,
  GitBranch,
  Layers,
  Tag,
  FolderOpen,
  Key,
  LayoutTemplate,
} from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import {
  useSiteTemplate,
  useCreateSite,
  useCreateSiteDomain,
  useCreateTemplateDeployment,
  useProject,
} from '@/lib/react-query/hooks'
import { sdk, getApiEndpoint } from '@/lib/appwrite/sdk'
import { resolveTemplatePlaceholder } from '@/lib/template-placeholders'
import { useWizard } from './WizardContext'
import { DomainInput } from './DomainInput'
import {
  ConnectRepositorySection,
  type ConnectRepositoryValue,
} from '@/components/global/shared/ConnectRepositorySection'
import { VCSDetectionType, ID } from '@appwrite.io/console'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'
import { buildVcsAuthUrl, type VcsProviderId } from '@/lib/vcs/providers'
import { getSiteTemplateScreenshotUrl } from '@/lib/sites/site-template-wizard'
import { validateVariables } from '@/lib/variables'

// Fade-in image component
function FadeImage({
  src,
  alt,
  className,
}: {
  src: string
  alt: string
  className?: string
}) {
  const [loaded, setLoaded] = useState(false)

  return (
    <img
      src={src}
      alt={alt}
      className={cn(
        className,
        'transition-opacity duration-300',
        loaded ? 'opacity-100' : 'opacity-0',
      )}
      onLoad={() => setLoaded(true)}
    />
  )
}

/**
 * Helper to safely extract a framework string from template.frameworks
 * The API might return strings or objects with a 'key' or 'name' property
 */
function getFrameworkString(framework: unknown): string {
  if (!framework) return ''
  if (typeof framework === 'string') return framework
  if (typeof framework === 'object' && framework !== null) {
    const obj = framework as Record<string, unknown>
    if (typeof obj.key === 'string') return obj.key
    if (typeof obj.name === 'string') return obj.name
    if (typeof obj.id === 'string') return obj.id
  }
  return ''
}

interface TemplateConfigViewProps {
  templateParam: string
}

export function TemplateConfigView({ templateParam }: TemplateConfigViewProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { theme, resolvedTheme } = useTheme()
  const {
    formData,
    updateFormData,
    frameworks,
    getFrameworkDefaults,
    generateDomain,
    installations,
  } = useWizard()

  const templateId = decodeURIComponent(templateParam)

  // Fetch template details and project (for placeholder resolution)
  const { data: template, isLoading: templateLoading } = useSiteTemplate(
    projectId,
    templateId,
  )
  const { project } = useProject(projectId)

  // Local form state
  const [siteName, setSiteName] = useState(formData.siteName || '')
  const [siteId, setSiteId] = useState<string | undefined>(formData.siteId)
  const [framework, setFramework] = useState(formData.framework || '')
  const [gitConnection, setGitConnection] = useState<'now' | 'later'>('later')
  const [variables, setVariables] = useState(formData.variables || [])
  const [domain, setDomain] = useState(formData.domain || '')
  const [domainValid, setDomainValid] = useState(formData.domainValid || false)
  const [isDeploying, setIsDeploying] = useState(false)

  // Connect-now repo selection (synced to formData for deploy)
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
      const redirectUrl = `${origin}/projects/${projectId}/sites/create`
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

  // Determine theme for screenshots
  const isDark = useMemo(() => {
    if (typeof window === 'undefined') return true
    return (
      resolvedTheme === 'dark' ||
      (resolvedTheme === 'system' &&
        window.matchMedia('(prefers-color-scheme: dark)').matches) ||
      theme === 'dark'
    )
  }, [theme, resolvedTheme])

  // Initialize form from template
  useEffect(() => {
    if (template) {
      if (!siteName) setSiteName(template.name)
      if (!framework && template.frameworks?.length) {
        setFramework(getFrameworkString(template.frameworks[0]))
      }
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
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [template])

  // Generate domain when site name changes
  useEffect(() => {
    if (siteName && !domain) {
      setDomain(generateDomain(siteName))
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [siteName, generateDomain])

  const screenshotUrl = useMemo(() => {
    if (!template) return null
    return getSiteTemplateScreenshotUrl(template, isDark) ?? null
  }, [template, isDark])

  // Mutations
  const createSiteMutation = useCreateSite(projectId)
  const createDomainMutation = useCreateSiteDomain(projectId)
  const createTemplateDeploymentMutation =
    useCreateTemplateDeployment(projectId)

  const handleConnectRepoValueChange = (next: ConnectRepositoryValue) => {
    updateFormData({
      installationId: next.installationId,
      providerRepositoryId: next.providerRepositoryId,
      repositoryName: next.repositoryName,
      repositoryOwner: next.repositoryOwner,
    })
  }

  const handleDeploy = async () => {
    if (!projectId || !template || !siteName || !framework) {
      toast.error(t('Please fill in all required fields'))
      return
    }

    if (!domainValid) {
      toast.error(t('Please enter a valid domain'))
      return
    }

    if (gitConnection === 'now') {
      if (!formData.installationId || !formData.providerRepositoryId) {
        toast.error(t('Please select a repository'))
        return
      }
    }

    // Reject an unusable key before the resource is created, so a rejected
    // variable cannot leave a half-configured site behind. Only the rows that
    // get written are checked -- the valueless ones are dropped below.
    const validationError = validateVariables(variables.filter((v) => v.value))
    if (validationError) {
      toast.error(validationError)
      return
    }

    setIsDeploying(true)

    try {
      // Use template framework when available, otherwise SDK framework defaults (buildRuntime, adapter, fallbackFile)
      const sdkDefaults = getFrameworkDefaults(framework)
      const defaults = templateFramework
        ? {
            installCommand: templateFramework.installCommand,
            buildCommand: templateFramework.buildCommand,
            outputDirectory: templateFramework.outputDirectory,
            buildRuntime: templateFramework.buildRuntime,
            adapter: templateFramework.adapter,
            fallbackFile: templateFramework.fallbackFile,
          }
        : sdkDefaults

      const connectNow =
        gitConnection === 'now' &&
        formData.installationId &&
        formData.providerRepositoryId

      // 1. Create the site (with or without VCS connection)
      const site = await createSiteMutation.mutateAsync({
        siteId: siteId || undefined,
        name: siteName,
        framework,
        installCommand: defaults.installCommand,
        buildCommand: defaults.buildCommand,
        startCommand: undefined,
        outputDirectory: defaults.outputDirectory,
        buildRuntime: defaults.buildRuntime ?? 'node-22',
        adapter: defaults.adapter ?? '',
        fallbackFile: defaults.fallbackFile ?? '',
        ...(connectNow && {
          installationId: formData.installationId,
          providerRepositoryId: formData.providerRepositoryId,
          providerBranch: connectBranch,
          providerRootDirectory: connectRootDir || undefined,
          providerSilentMode: false,
        }),
      })

      // 2. Create domain rule
      if (domain) {
        await createDomainMutation.mutateAsync({
          domain,
          siteId: site.$id,
        })
      }

      // 3. Create environment variables
      if (variables.length > 0) {
        const projectSdk = sdk.forProject(projectId)
        await Promise.all(
          variables
            .filter((v) => v.value) // Only create variables with values
            .map((v) =>
              projectSdk.sites.createVariable({
                siteId: site.$id,
                variableId: ID.unique(),
                key: v.key,
                value: v.value,
                secret: v.secret,
              }),
            ),
        )
      }

      // 4. Create deployment: always use template deployment so the build has source code.
      // (When connect now, the site is linked to the user's repo for future VCS deployments,
      // but the initial deploy uses the template repo so new/empty user repos don't fail.)
      if (!template.providerRepositoryId || !template.providerOwner) {
        toast.error(t('Template is missing repository information'))
        setIsDeploying(false)
        return
      }
      const deployment = await createTemplateDeploymentMutation.mutateAsync({
        siteId: site.$id,
        repository: template.providerRepositoryId,
        owner: template.providerOwner,
        rootDirectory: templateFramework?.providerRootDirectory ?? './',
        type: 'tag',
        reference: template.providerVersion || 'main',
        activate: true,
      })

      updateFormData({
        createdSiteId: site.$id,
        createdDeploymentId: deployment?.$id,
      })

      await queryClient.refetchQueries({
        queryKey: ['sites', 'project', projectId],
      })

      navigate({
        to: '/projects/$projectId/sites/create/deploying',
        params: { projectId },
        search: {
          siteId: site.$id,
          deploymentId: deployment?.$id ?? '',
        },
      })
    } catch (error: unknown) {
      toast.error(error.message || t('Failed to create site'))
      setIsDeploying(false)
    }
  }

  const frameworkInfo = useMemo(() => {
    return frameworks.find((f) => f.key === framework)
  }, [frameworks, framework])

  // Selected framework from template (has buildRuntime, adapter, fallbackFile required by API)
  const templateFramework = useMemo((): Models.TemplateFramework | null => {
    if (!template?.frameworks?.length || !framework) return null
    const match = template.frameworks.find(
      (f) => getFrameworkString(f) === framework,
    )
    return (match ?? template.frameworks[0]) as Models.TemplateFramework
  }, [template, framework])

  // GitHub URL to the template's code (repo root or root directory when set)
  const templateSourceUrl = useMemo(() => {
    if (!template?.providerOwner || !template?.providerRepositoryId) return null
    const base = `https://github.com/${template.providerOwner}/${template.providerRepositoryId}`
    const ref = 'main'
    const rootDir =
      templateFramework?.providerRootDirectory?.trim() ??
      template.providerRootDirectory?.trim() ??
      ''
    const path = rootDir.replace(/^\.\/?/, '').replace(/\/+$/, '')
    if (!path) return `${base}/tree/${ref}`
    return `${base}/tree/${ref}/${path}`
  }, [
    template?.providerOwner,
    template?.providerRepositoryId,
    template?.providerRootDirectory,
    templateFramework?.providerRootDirectory,
  ])

  const sidebarContent = template ? (
    <div className="rounded-xl border border-border bg-gradient-to-b from-card/80 to-card/40 backdrop-blur-sm overflow-hidden">
      {/* Header with template info */}
      <div className="px-5 py-4">
        <div className="flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-muted to-muted/50 ring-1 ring-border/50">
            <FrameworkIcon framework={framework} size="md" />
          </div>
          <div className="flex-1 min-w-0">
            <h3 className="text-[13px] font-semibold text-foreground truncate">
              {template.name}
            </h3>
            <p className="text-[11px] text-muted-foreground truncate">
              {template.tagline ||
                `${template.providerOwner}/${template.providerRepositoryId}`}
            </p>
          </div>
        </div>
      </div>

      {/* Template preview - tilted screenshot style (aspect-video reserves space to avoid layout shift) */}
      <div className="relative h-[120px] overflow-hidden border-b border-border/50">
        {screenshotUrl ? (
          <div className="absolute start-6 -end-4 top-4 aspect-video transform -rotate-3">
            <div className="relative h-full w-full overflow-hidden rounded-lg ring-1 ring-border bg-muted/30">
              <FadeImage
                src={screenshotUrl}
                alt={`${template.name} preview`}
                className="absolute inset-0 h-full w-full object-cover object-top"
              />
            </div>
          </div>
        ) : (
          <div className="absolute start-6 -end-4 top-4 aspect-video transform -rotate-3 flex items-center justify-center rounded-lg bg-muted/50 ring-1 ring-border">
            <LayoutTemplate className="h-8 w-8 text-muted-foreground/30" />
          </div>
        )}
      </div>

      {/* Configuration details */}
      <div className="px-5 py-4 space-y-3">
        {frameworkInfo && (
          <div className="flex items-center justify-between">
            <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
              <Layers className="h-3.5 w-3.5" />
              {t('Framework')}
            </span>
            <span className="flex items-center gap-1.5 text-[12px] text-foreground">
              <FrameworkIcon framework={framework} size="sm" />
              <span>{frameworkInfo.name}</span>
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
        {template.providerRootDirectory &&
          template.providerRootDirectory !== './' && (
            <div className="flex items-center justify-between">
              <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
                <FolderOpen className="h-3.5 w-3.5" />
                {t('Root directory')}
              </span>
              <code className="text-[12px] font-mono text-foreground bg-muted/50 px-2 py-0.5 rounded max-w-[120px] truncate">
                {template.providerRootDirectory}
              </code>
            </div>
          )}
        {template.variables && template.variables.length > 0 && (
          <div className="flex items-center justify-between">
            <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
              <Key className="h-3.5 w-3.5" />
              {t('Variables')}
            </span>
            <span className="text-[12px] text-foreground">
              {template.variables.length} {t('required')}
            </span>
          </div>
        )}
      </div>

      {/* CTA buttons */}
      {(template.providerRepositoryId || template.demoUrl) && (
        <div className="px-5 py-4 border-t border-border/50 flex gap-2">
          {templateSourceUrl && (
            <Button
              variant="outline"
              size="sm"
              className="flex-1 h-9 text-[13px]"
              asChild
            >
              <a
                href={templateSourceUrl}
                target="_blank"
                rel="noopener noreferrer"
              >
                <GitBranch className="me-1.5 h-4 w-4" />
                {t('View source')}
              </a>
            </Button>
          )}
          {template.demoUrl && (
            <Button
              variant="outline"
              size="sm"
              className="flex-1 h-9 text-[13px]"
              asChild
            >
              <a
                href={template.demoUrl}
                target="_blank"
                rel="noopener noreferrer"
              >
                <ExternalLink className="me-1.5 h-4 w-4" />
                {t('Live demo')}
              </a>
            </Button>
          )}
        </div>
      )}

      {/* Status indicator */}
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

  if (templateLoading) {
    return (
      <WizardLayout
        title={t('Create site')}
        fallbackPath={`/projects/${projectId}/sites`}
        fullscreen
        maxWidth="max-w-[1400px]"
      >
        <div className="flex items-center justify-center py-16">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      </WizardLayout>
    )
  }

  if (!template) {
    return (
      <WizardLayout
        title={t('Create site')}
        fallbackPath={`/projects/${projectId}/sites`}
        fullscreen
        maxWidth="max-w-[1400px]"
      >
        <div className="text-center py-16">
          <p className="text-muted-foreground">{t('Template not found')}</p>
          <Button variant="outline" className="mt-4" asChild>
            <Link
              to="/projects/$projectId/sites/create/templates"
              params={{ projectId: projectId! }}
            >
              {t('Browse templates')}
            </Link>
          </Button>
        </div>
      </WizardLayout>
    )
  }

  return (
    <WizardLayout
      title={t('Create site')}
      showBackButton
      backButtonLabel={t('Back')}
      fallbackPath={`/projects/${projectId}/sites`}
      fullscreen
      maxWidth="max-w-[1400px]"
      footerAlign="right"
      sidebar={sidebarContent}
      footer={
        <>
          <Button
            variant="outline"
            onClick={() => window.history.back()}
            disabled={isDeploying}
          >
            {t('Cancel')}
          </Button>
          <Button
            onClick={handleDeploy}
            disabled={
              isDeploying ||
              !siteName ||
              !framework ||
              !domainValid ||
              createSiteMutation.isPending ||
              (gitConnection === 'now' &&
                (!formData.providerRepositoryId || !formData.installationId))
            }
          >
            {t('Deploy')}
          </Button>
        </>
      }
    >
      {/* Details section */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Details')}
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          {/* Site name */}
          <div className="space-y-2">
            <Label htmlFor="site-name" className="text-[13px]">
              {t('Site name')}
            </Label>
            <Input
              id="site-name"
              value={siteName}
              onChange={(e) => setSiteName(e.target.value)}
              placeholder={t('My awesome site')}
              className="h-9 text-[13px]"
            />
          </div>

          {/* Site ID */}
          <div className="space-y-2">
            <Label className="text-[13px]">{t('Site ID')}</Label>
            <IdInput
              value={siteId}
              onChange={setSiteId}
              placeholder={t('Auto-generated')}
            />
          </div>

          {/* Framework (if template supports multiple) */}
          {template.frameworks && template.frameworks.length > 1 && (
            <div className="space-y-2">
              <Label htmlFor="framework" className="text-[13px]">
                {t('Framework')}
              </Label>
              <Select value={framework} onValueChange={setFramework}>
                <SelectTrigger className="h-9 text-[13px]">
                  <SelectValue placeholder={t('Select framework')} />
                </SelectTrigger>
                <SelectContent>
                  {template.frameworks.map((f, index) => {
                    const fKey = getFrameworkString(f)
                    const fInfo = frameworks.find((fr) => fr.key === fKey)
                    return (
                      <SelectItem key={fKey || index} value={fKey}>
                        <div className="flex items-center gap-2">
                          <FrameworkIcon framework={fKey} size="sm" />
                          {fInfo?.name || fKey}
                        </div>
                      </SelectItem>
                    )
                  })}
                </SelectContent>
              </Select>
            </div>
          )}
        </div>
      </div>

      {/* Domain section */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Domain')}
          </h3>
          <p className="text-[12px] text-muted-foreground mt-1">
            {t('Your site will be accessible at this URL')}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <DomainInput
            value={domain}
            onChange={setDomain}
            onValidChange={setDomainValid}
          />
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/20">
          <p className="text-[11px] text-muted-foreground leading-relaxed">
            {t(
              'Want to use your own domain? After deployment, you can connect a custom domain via CNAME record or let Appwrite manage your DNS.', // pragma: allowlist secret
            )}{' '}
            <DocsRouteLink
              className="link-neutral font-medium"
              href="/docs/products/sites/domains"
            >
              {t('Learn more →')}
            </DocsRouteLink>
          </p>
        </div>
      </div>

      {/* Git connection section */}
      <RadioGroup
        value={gitConnection}
        onValueChange={(value) => setGitConnection(value as 'now' | 'later')}
        className="grid grid-cols-1 sm:grid-cols-2 gap-4"
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
                'Deploy now and connect your version control later via CLI or Git integration in your settings.',
              )}
            </p>
          </div>
        </Label>
      </RadioGroup>

      {/* Git repository section (only when Connect your repository is selected) */}
      {gitConnection === 'now' && (
        <ConnectRepositorySection
          projectId={projectId}
          installations={installations}
          getGitHubAuthUrl={getGitHubAuthUrl}
          getVcsAuthUrl={getVcsAuthUrl}
          defaultRepositoryName={siteName || template?.name || ''}
          detectionType={VCSDetectionType.Framework}
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
          emptyStateTitle={t('Connect Git repository')}
          emptyStateDescription={t(
            'Create and deploy a Site with a connected git repository.',
          )}
        />
      )}

      {/* Template variables section */}
      {template.variables &&
        template.variables.length > 0 &&
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
                    templateVar?.placeholder || `Enter ${variable.key}`
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
        })()}
    </WizardLayout>
  )
}
