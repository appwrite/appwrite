/**
 * Build Settings Card Component
 *
 * Configures framework, adapter, and build commands for the site.
 */

import { useState, useEffect, useMemo } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { RotateCcw } from 'lucide-react'
import {
  getAdapterCopy,
  getAdapterDescriptionSegments,
} from '@/lib/frameworks'
import {
  hasUnavailableSpecifications,
  isSpecificationAllowedInPlan,
} from '@/lib/specifications'
import {
  buildSiteUpdateParams,
  useSiteFrameworks,
  useSiteSpecifications,
} from '@/lib/react-query/hooks'

const codeClassName =
  'rounded bg-muted/80 px-1.5 py-0.5 font-mono text-[12px] text-foreground/90'

function InputWithReset({
  id,
  label,
  value,
  placeholder,
  onChange,
  onReset,
  isModified,
}: {
  id: string
  label: string
  value: string
  placeholder: string
  onChange: (value: string) => void
  onReset: () => void
  isModified: boolean
}) {
  return (
    <div>
      <Label htmlFor={id} className="text-[13px]">
        {label}
      </Label>
      <div className="mt-2 flex overflow-hidden rounded-md border border-input transition-colors has-[:focus-visible]:border-ring">
        <Input
          id={id}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder={placeholder}
          className="h-9 flex-1 min-w-0 rounded-none border-0 border-r border-input bg-transparent font-mono text-[13px] focus-visible:ring-0 focus-visible:ring-offset-0"
        />
        <TooltipProvider delayDuration={300}>
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                onClick={onReset}
                disabled={!isModified}
                className={cn(
                  'h-9 w-9 shrink-0 rounded-none border-0 text-muted-foreground hover:text-foreground',
                  !isModified && 'cursor-default opacity-50',
                )}
                aria-label="Reset to default"
              >
                <RotateCcw className="h-3.5 w-3.5" />
              </Button>
            </TooltipTrigger>
            <TooltipContent side="top" className="text-xs">
              {isModified ? 'Reset to default' : 'No changes to reset'}
            </TooltipContent>
          </Tooltip>
        </TooltipProvider>
      </div>
    </div>
  )
}

function AdapterOptionDescription({
  desc,
  code,
}: {
  desc: string
  code: string[]
}) {
  const segments = getAdapterDescriptionSegments(desc, code)
  return (
    <p className="mt-2 text-[13px] text-muted-foreground leading-relaxed">
      {segments.map((seg, i) =>
        seg.type === 'text' ? (
          <span key={i}>{seg.value}</span>
        ) : (
          <code key={i} className={codeClassName}>
            {seg.value}
          </code>
        ),
      )}
    </p>
  )
}

function AdapterOptionCard({
  id,
  value,
  label,
  desc,
  code,
  url,
  isSelected,
}: {
  id: string
  value: 'ssr' | 'static'
  label: string
  desc: string
  code: string[]
  url?: string
  isSelected: boolean
}) {
  return (
    <Label
      htmlFor={id}
      className={cn(
        'relative flex cursor-pointer items-start rounded-xl border transition-colors',
        'px-5 py-4 sm:px-5 sm:py-5',
        isSelected
          ? 'border-primary bg-primary/5 shadow-sm'
          : 'border-border bg-card/50 hover:border-border hover:bg-muted/20',
      )}
    >
      <RadioGroupItem
        value={value}
        id={id}
        className="mt-1 shrink-0"
      />
      <div className="ml-4 flex-1 min-w-0 pr-2">
        <span className="block text-[15px] font-semibold tracking-tight text-foreground">
          {label}
        </span>
        <AdapterOptionDescription desc={desc} code={code} />
        {url && (
          <a
            href={url}
            target="_blank"
            rel="noopener noreferrer"
            className="mt-3 inline-block text-[13px] text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
            onClick={(e) => e.stopPropagation()}
          >
            Learn more
          </a>
        )}
      </div>
    </Label>
  )
}

function AdapterOptions({
  frameworkKey,
  adapter,
  onAdapterChange,
}: {
  frameworkKey: string
  adapter: string
  onAdapterChange: (value: 'ssr' | 'static') => void
}) {
  const ssrCopy = getAdapterCopy(frameworkKey, 'ssr')
  const staticCopy = getAdapterCopy(frameworkKey, 'static')
  return (
    <div>
      <Label className="text-[13px] font-medium text-foreground">Adapter</Label>
      <p className="mt-1 text-[13px] text-muted-foreground">
        Choose how your site is rendered at runtime.
      </p>
      <RadioGroup
        value={adapter}
        onValueChange={(v) => onAdapterChange(v as 'ssr' | 'static')}
        className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 sm:gap-5"
      >
        <AdapterOptionCard
          id="adapter-ssr"
          value="ssr"
          label={ssrCopy.label}
          desc={ssrCopy.desc}
          code={ssrCopy.code}
          url={ssrCopy.url}
          isSelected={adapter === 'ssr'}
        />
        <AdapterOptionCard
          id="adapter-static"
          value="static"
          label={staticCopy.label}
          desc={staticCopy.desc}
          code={staticCopy.code}
          url={staticCopy.url}
          isSelected={adapter === 'static'}
        />
      </RadioGroup>
    </div>
  )
}

interface BuildSettingsCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
  isCloud?: boolean
}

export function BuildSettingsCard({
  projectId,
  siteId,
  site,
  isCloud = false,
}: BuildSettingsCardProps) {
  const queryClient = useQueryClient()
  const { data: frameworksData } = useSiteFrameworks(projectId)
  const { data: specificationsData } = useSiteSpecifications(projectId)

  const frameworks = useMemo(
    () => frameworksData?.frameworks || [],
    [frameworksData],
  )
  const specifications = useMemo(
    () => specificationsData?.specifications || [],
    [specificationsData],
  )

  const [framework, setFramework] = useState('')
  const [adapter, setAdapter] = useState('')
  const [installCommand, setInstallCommand] = useState('')
  const [buildCommand, setBuildCommand] = useState('')
  const [outputDirectory, setOutputDirectory] = useState('')
  const [fallbackFile, setFallbackFile] = useState('')
  const [specification, setSpecification] = useState('')

  // Get current framework info
  const currentFramework = useMemo(
    () => frameworks.find((f) => f.key === framework),
    [frameworks, framework],
  )

  // Get framework defaults
  const frameworkDefaults = useMemo(() => {
    if (!currentFramework) {
      return {
        installCommand: 'npm install',
        buildCommand: 'npm run build',
        outputDirectory: '.output',
      }
    }
    return {
      installCommand: currentFramework.installCommand || 'npm install',
      buildCommand: currentFramework.buildCommand || 'npm run build',
      outputDirectory: currentFramework.outputDirectory || '.output',
    }
  }, [currentFramework])

  // Initialize state from site data
  useEffect(() => {
    if (site) {
      setFramework(site.framework || '')
      setAdapter(site.adapter || '')
      setInstallCommand(site.installCommand || '')
      setBuildCommand(site.buildCommand || '')
      setOutputDirectory(site.outputDirectory || '')
      setFallbackFile(site.fallbackFile || '')
      setSpecification(site.specification || '')
    }
  }, [site])

  // Update defaults when framework changes
  useEffect(() => {
    if (framework && !installCommand) {
      setInstallCommand(frameworkDefaults.installCommand)
    }
    if (framework && !buildCommand) {
      setBuildCommand(frameworkDefaults.buildCommand)
    }
    if (framework && !outputDirectory) {
      setOutputDirectory(frameworkDefaults.outputDirectory)
    }
  }, [framework, frameworkDefaults])

  // Update site mutation (use full site payload so other cards' values are preserved)
  const updateSiteMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Site>) => {
      if (!projectId || !siteId || !site)
        throw new Error('Project ID, Site ID, and Site are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update(buildSiteUpdateParams(site, updates))
    },
    onSuccess: () => {
      toast.success('Build settings updated successfully')
      queryClient.invalidateQueries({
        queryKey: ['site', 'project', projectId, siteId],
      })
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: any) => {
      toast.error(getErrorMessage(error, 'Failed to update build settings'))
    },
  })

  const handleSave = () => {
    if (!framework) {
      toast.error('Framework is required')
      return
    }
    updateSiteMutation.mutate({
      framework,
      adapter: adapter || undefined,
      installCommand: installCommand || undefined,
      buildCommand: buildCommand || undefined,
      outputDirectory: outputDirectory || undefined,
      fallbackFile: fallbackFile || undefined,
      specification: specification || undefined,
    })
  }

  const hasChanges =
    framework !== site?.framework ||
    adapter !== site?.adapter ||
    installCommand !== site?.installCommand ||
    buildCommand !== site?.buildCommand ||
    outputDirectory !== site?.outputDirectory ||
    fallbackFile !== site?.fallbackFile ||
    specification !== site?.specification

  const isStaticAdapter = adapter === 'static'
  const isInstallModified = installCommand !== frameworkDefaults.installCommand
  const isBuildModified = buildCommand !== frameworkDefaults.buildCommand
  const isOutputModified = outputDirectory !== frameworkDefaults.outputDirectory

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Build Settings
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          Configure framework, adapter, and build commands for the site
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="space-y-4">
          {/* Framework Selection */}
          <div>
            <Label htmlFor="framework" className="text-[13px]">
              Framework
            </Label>
            <Select value={framework} onValueChange={setFramework}>
              <SelectTrigger
                id="framework"
                className="mt-2 h-9 border-border bg-background text-[13px]"
              >
                <SelectValue placeholder="Select framework" />
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

          {/* Adapter Selection */}
          {framework && (
            <AdapterOptions
              frameworkKey={framework}
              adapter={adapter}
              onAdapterChange={setAdapter}
            />
          )}

          {/* Install Command */}
          <InputWithReset
            id="install-command"
            label="Install Command"
            value={installCommand}
            placeholder={frameworkDefaults.installCommand}
            onChange={setInstallCommand}
            onReset={() => setInstallCommand(frameworkDefaults.installCommand)}
            isModified={isInstallModified}
          />

          {/* Build Command */}
          <InputWithReset
            id="build-command"
            label="Build Command"
            value={buildCommand}
            placeholder={frameworkDefaults.buildCommand}
            onChange={setBuildCommand}
            onReset={() => setBuildCommand(frameworkDefaults.buildCommand)}
            isModified={isBuildModified}
          />

          {/* Output Directory */}
          <InputWithReset
            id="output-directory"
            label="Output Directory"
            value={outputDirectory}
            placeholder={frameworkDefaults.outputDirectory}
            onChange={setOutputDirectory}
            onReset={() => setOutputDirectory(frameworkDefaults.outputDirectory)}
            isModified={isOutputModified}
          />

          {/* Fallback File (for Static adapter) */}
          {isStaticAdapter && (
            <div>
              <Label htmlFor="fallback-file" className="text-[13px]">
                Fallback File
              </Label>
              <Input
                id="fallback-file"
                value={fallbackFile}
                onChange={(e) => setFallbackFile(e.target.value)}
                placeholder="index.html"
                className="mt-2 h-9 font-mono text-[13px]"
              />
              <p className="mt-1 text-[12px] text-muted-foreground">
                File to serve for routes that don't match any static files
              </p>
            </div>
          )}

          {/* Specification (Cloud only) */}
          {isCloud && specifications.length > 0 && (
            <div>
              <Label htmlFor="specification" className="text-[13px]">
                Specification
              </Label>
              <Select
                value={specification || undefined}
                onValueChange={setSpecification}
              >
                <SelectTrigger
                  id="specification"
                  className="mt-2 h-9 border-border bg-background text-[13px]"
                >
                  <SelectValue placeholder="Select specification" />
                </SelectTrigger>
                <SelectContent>
                  {specifications
                    .filter((spec) => spec.slug && spec.slug.trim() !== '')
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
              <p className="mt-1 text-[12px] text-muted-foreground">
                Select the CPU and memory specification for your site
              </p>
              {hasUnavailableSpecifications(specifications) && (
                <div className="mt-3 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
                  <p className="text-[12px] text-muted-foreground">
                    Need more resources?{' '}
                    <a
                      href="#"
                      className="font-medium text-foreground underline hover:no-underline"
                      onClick={(e) => {
                        e.preventDefault()
                        // TODO: Navigate to upgrade page
                      }}
                    >
                      Upgrade your plan
                    </a>{' '}
                    to unlock additional specifications.
                  </p>
                </div>
              )}
            </div>
          )}
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || !framework || updateSiteMutation.isPending}
          onClick={handleSave}
        >
          Update
        </Button>
      </div>
    </div>
  )
}
