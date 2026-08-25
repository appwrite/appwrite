/**
 * Quick Deploy View Component
 *
 * Deploy directly from a GitHub repository URL.
 * Pre-fills configuration from URL parameters.
 */

import { useState, useEffect, useMemo } from 'react'
import { useParams, useNavigate, Link } from '@tanstack/react-router'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
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
import { ExternalLink } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ID } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useCreateSite,
  useCreateSiteDomain,
  useCreateTemplateDeployment,
} from '@/lib/react-query/hooks'
import { useWizard } from './WizardContext'
import { DomainInput } from './DomainInput'
import { BuildSettings } from './BuildSettings'
import { VariablesSettingsCard } from '@/components/global/shared/VariablesSettingsCard'
import type { WizardVariable } from './WizardContext'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'
import { validateVariables } from '@/lib/variables'

// GitHub Icon Component
function GitHubIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="currentColor"
      className={className}
      xmlns="http://www.w3.org/2000/svg"
    >
      <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
    </svg>
  )
}

interface QuickDeployViewProps {
  repo?: string
  owner?: string
  framework?: string
  branch?: string
  root?: string
  installCommand?: string
  buildCommand?: string
  startCommand?: string
  outputDirectory?: string
  envKeys?: string
}

export function QuickDeployView({
  repo,
  owner,
  framework: initialFramework,
  branch: initialBranch,
  root: initialRoot,
  installCommand: initialInstall,
  buildCommand: initialBuild,
  startCommand: initialStart,
  outputDirectory: initialOutput,
  envKeys,
}: QuickDeployViewProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const {
    updateFormData,
    frameworks,
    getFramework,
    getFrameworkDefaults,
    generateDomain,
    setCurrentPath,
  } = useWizard()

  // Set current path
  useEffect(() => {
    setCurrentPath('deploy')
  }, [setCurrentPath])

  // Parse env keys
  const envKeysList = useMemo(() => {
    if (!envKeys) return []
    return envKeys
      .split(',')
      .map((k) => k.trim())
      .filter(Boolean)
  }, [envKeys])

  // Local form state
  const [siteName, setSiteName] = useState(repo || '')
  const [siteId, setSiteId] = useState<string | undefined>()
  const [framework, setFramework] = useState(initialFramework || '')
  const [rootDirectory, setRootDirectory] = useState(initialRoot || './')
  const [installCommand, setInstallCommand] = useState(initialInstall || '')
  const [buildCommand, setBuildCommand] = useState(initialBuild || '')
  const [outputDirectory, setOutputDirectory] = useState(initialOutput || '')
  const [startCommand, setStartCommand] = useState(initialStart || '')
  const [variables, setVariables] = useState<WizardVariable[]>(
    envKeysList.map((key) => ({ key, value: '', secret: false })),
  )
  const [domain, setDomain] = useState('')
  const [domainValid, setDomainValid] = useState(false)
  const [isDeploying, setIsDeploying] = useState(false)

  // Update build commands when framework changes
  useEffect(() => {
    if (
      framework &&
      !initialInstall &&
      !initialBuild &&
      !initialStart &&
      !initialOutput
    ) {
      const defaults = getFrameworkDefaults(framework)
      setInstallCommand(defaults.installCommand)
      setBuildCommand(defaults.buildCommand)
      setOutputDirectory(defaults.outputDirectory)
    }
  }, [
    framework,
    getFramework,
    getFrameworkDefaults,
    initialInstall,
    initialBuild,
    initialStart,
    initialOutput,
  ])

  // Generate domain when site name changes
  useEffect(() => {
    if (siteName && !domain) {
      setDomain(generateDomain(siteName))
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [siteName, generateDomain])

  // Mutations
  const createSiteMutation = useCreateSite(projectId)
  const createDomainMutation = useCreateSiteDomain(projectId)
  const createDeploymentMutation = useCreateTemplateDeployment(projectId)

  const handleDeploy = async () => {
    if (!projectId || !siteName || !framework || !repo || !owner) {
      toast.error(t('Please fill in all required fields'))
      return
    }

    if (!domainValid) {
      toast.error(t('Please enter a valid domain'))
      return
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
      // Get framework defaults
      const defaults = getFrameworkDefaults(framework)

      // 1. Create the site
      const site = await createSiteMutation.mutateAsync({
        siteId: siteId || undefined,
        name: siteName,
        framework,
        buildRuntime: defaults.buildRuntime,
        installCommand: installCommand || defaults.installCommand,
        buildCommand: buildCommand || defaults.buildCommand,
        startCommand: startCommand || undefined,
        outputDirectory: outputDirectory || defaults.outputDirectory,
        adapter: defaults.adapter || undefined,
      })

      // 2. Create domain rule
      if (domain) {
        await createDomainMutation.mutateAsync({
          domain,
          siteId: site.$id,
        })
      }

      // 3. Create environment variables
      const varsWithValues = variables.filter((v) => v.value)
      if (varsWithValues.length > 0) {
        const projectSdk = sdk.forProject(projectId)
        await Promise.all(
          varsWithValues.map((v) =>
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

      // 4. Create template deployment
      const deployment = await createDeploymentMutation.mutateAsync({
        siteId: site.$id,
        repository: repo,
        owner: owner,
        rootDirectory: rootDirectory,
        type: 'tag',
        reference: initialBranch || 'main',
        activate: true,
      })

      // Update form data
      updateFormData({
        createdSiteId: site.$id,
        createdDeploymentId: deployment.$id,
      })

      // Refetch sites list so cache is updated (list has refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['sites', 'project', projectId],
      })

      // Navigate to deploying screen
      navigate({
        to: '/projects/$projectId/sites/create/deploying',
        params: { projectId },
        search: { siteId: site.$id, deploymentId: deployment.$id },
      })
    } catch (error: unknown) {
      toast.error(error.message || t('Failed to create site'))
      setIsDeploying(false)
    }
  }

  const frameworkInfo = useMemo(() => {
    return frameworks.find((f) => f.key === framework)
  }, [frameworks, framework])

  const repoUrl = repo && owner ? `https://github.com/${owner}/${repo}` : null

  const sidebarContent = (
    <div className="space-y-4">
      {/* Repository info */}
      {repo && owner && (
        <div className="rounded-xl border border-border bg-card/50 p-4">
          <div className="flex items-center gap-3 mb-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              <GitHubIcon className="h-5 w-5" />
            </div>
            <div>
              <p className="text-[13px] font-medium text-foreground truncate">
                {owner}/{repo}
              </p>
              <p className="text-[11px] text-muted-foreground">
                {t('GitHub Repository')}
              </p>
            </div>
          </div>
          {repoUrl && (
            <Button
              variant="outline"
              size="sm"
              className="w-full h-8 text-[12px]"
              asChild
            >
              <a href={repoUrl} target="_blank" rel="noopener noreferrer">
                <ExternalLink className="me-1.5 h-3.5 w-3.5" />
                {t('View on GitHub')}
              </a>
            </Button>
          )}
        </div>
      )}

      {/* Framework info */}
      {frameworkInfo && (
        <div className="rounded-xl border border-border bg-card/50 p-4">
          <div className="flex items-center gap-3">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              <FrameworkIcon framework={framework} size="sm" />
            </div>
            <div>
              <p className="text-[12px] text-muted-foreground">
                {t('Framework')}
              </p>
              <p className="text-[13px] font-medium text-foreground">
                {frameworkInfo.name}
              </p>
            </div>
          </div>
        </div>
      )}
    </div>
  )

  // Show error if missing required params
  if (!repo || !owner) {
    return (
      <WizardLayout
        title={t('Create site')}
        fallbackPath={`/projects/${projectId}/sites`}
        fullscreen
        maxWidth="max-w-[1400px]"
      >
        <div className="rounded-xl border border-border bg-card/50 p-8 text-center">
          <p className="text-[13px] text-muted-foreground mb-4">
            {t('Repository information is missing from the URL.')}
          </p>
          <Button asChild>
            <Link
              to="/projects/$projectId/sites/create/repositories"
              params={{ projectId: projectId! }}
            >
              {t('Import from Git')}
            </Link>
          </Button>
        </div>
      </WizardLayout>
    )
  }

  return (
    <WizardLayout
      title={t('Create site')}
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
              createSiteMutation.isPending
            }
          >
            {t('Deploy')}
          </Button>
        </>
      }
    >
      {/* Repository card */}
      <div className="rounded-xl border border-border bg-card/50 p-4">
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted text-muted-foreground">
            <GitHubIcon className="h-5 w-5" />
          </div>
          <div className="flex-1">
            <p className="text-[13px] font-medium text-foreground">
              {owner}/{repo}
            </p>
            {repoUrl && (
              <a
                href={repoUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="text-[11px] text-muted-foreground hover:text-foreground"
              >
                {t('View repository')}
              </a>
            )}
          </div>
        </div>
      </div>

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

          {/* Framework selector */}
          <div className="space-y-2">
            <Label htmlFor="framework" className="text-[13px]">
              {t('Framework')}
            </Label>
            <Select
              value={framework}
              onValueChange={(value) => {
                setFramework(value)
                const defaults = getFrameworkDefaults(value)
                setInstallCommand(defaults.installCommand)
                setBuildCommand(defaults.buildCommand)
                setOutputDirectory(defaults.outputDirectory)
              }}
            >
              <SelectTrigger className="h-9 text-[13px]">
                <SelectValue placeholder={t('Select framework')} />
              </SelectTrigger>
              <SelectContent>
                {frameworks.map((f) => (
                  <SelectItem key={f.key} value={f.key}>
                    <div className="flex items-center gap-2">
                      <FrameworkIcon framework={f.key} size="sm" />
                      {f.name}
                    </div>
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>
      </div>

      {/* Git configuration */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Git configuration')}
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          {/* Root directory */}
          <div className="space-y-2">
            <Label htmlFor="root-directory" className="text-[13px]">
              {t('Root directory')}
            </Label>
            <Input
              id="root-directory"
              value={rootDirectory}
              onChange={(e) => setRootDirectory(e.target.value)}
              placeholder="./"
              className="h-9 font-mono text-[13px]"
            />
          </div>
        </div>
      </div>

      {/* Build settings */}
      <BuildSettings
        installCommand={installCommand}
        buildCommand={buildCommand}
        outputDirectory={outputDirectory}
        startCommand={startCommand}
        onInstallCommandChange={setInstallCommand}
        onBuildCommandChange={setBuildCommand}
        onOutputDirectoryChange={setOutputDirectory}
        onStartCommandChange={setStartCommand}
        frameworkKey={framework}
        defaultOpen={true}
      />

      {/* Environment variables */}
      <VariablesSettingsCard
        variant="wizard"
        variables={variables}
        onChange={setVariables}
        disabled={isDeploying}
      />

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
    </WizardLayout>
  )
}
