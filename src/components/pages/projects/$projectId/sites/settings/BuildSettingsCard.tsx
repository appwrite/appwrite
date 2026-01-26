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
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { RotateCcw } from 'lucide-react'
import {
  useSiteFrameworks,
  useSiteSpecifications,
} from '@/lib/react-query/hooks'

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

  // Update site mutation
  const updateSiteMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Site>) => {
      if (!projectId || !siteId || !site)
        throw new Error('Project ID, Site ID, and Site are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update({
        siteId,
        name: site.name,
        ...updates,
      })
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

  // Get available adapters for the framework
  const availableAdapters = useMemo(() => {
    if (!currentFramework) return []
    // Most frameworks support both SSR and Static
    // Some frameworks might have specific adapters
    return ['ssr', 'static']
  }, [currentFramework])

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
            <div>
              <Label htmlFor="adapter" className="text-[13px]">
                Adapter
              </Label>
              <Select value={adapter} onValueChange={setAdapter}>
                <SelectTrigger
                  id="adapter"
                  className="mt-2 h-9 border-border bg-background text-[13px]"
                >
                  <SelectValue placeholder="Select adapter" />
                </SelectTrigger>
                <SelectContent>
                  {availableAdapters.map((a) => (
                    <SelectItem key={a} value={a}>
                      {a === 'ssr' ? 'SSR' : 'Static'}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}

          {/* Install Command */}
          <div>
            <div className="flex items-center justify-between mb-2">
              <Label htmlFor="install-command" className="text-[13px]">
                Install Command
              </Label>
              {isInstallModified && (
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => setInstallCommand(frameworkDefaults.installCommand)}
                  className="h-6 px-2 text-[11px] text-muted-foreground hover:text-foreground"
                >
                  <RotateCcw className="mr-1 h-3 w-3" />
                  Reset
                </Button>
              )}
            </div>
            <Input
              id="install-command"
              value={installCommand}
              onChange={(e) => setInstallCommand(e.target.value)}
              placeholder={frameworkDefaults.installCommand}
              className="h-9 font-mono text-[13px]"
            />
          </div>

          {/* Build Command */}
          <div>
            <div className="flex items-center justify-between mb-2">
              <Label htmlFor="build-command" className="text-[13px]">
                Build Command
              </Label>
              {isBuildModified && (
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => setBuildCommand(frameworkDefaults.buildCommand)}
                  className="h-6 px-2 text-[11px] text-muted-foreground hover:text-foreground"
                >
                  <RotateCcw className="mr-1 h-3 w-3" />
                  Reset
                </Button>
              )}
            </div>
            <Input
              id="build-command"
              value={buildCommand}
              onChange={(e) => setBuildCommand(e.target.value)}
              placeholder={frameworkDefaults.buildCommand}
              className="h-9 font-mono text-[13px]"
            />
          </div>

          {/* Output Directory */}
          <div>
            <div className="flex items-center justify-between mb-2">
              <Label htmlFor="output-directory" className="text-[13px]">
                Output Directory
              </Label>
              {isOutputModified && (
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => setOutputDirectory(frameworkDefaults.outputDirectory)}
                  className="h-6 px-2 text-[11px] text-muted-foreground hover:text-foreground"
                >
                  <RotateCcw className="mr-1 h-3 w-3" />
                  Reset
                </Button>
              )}
            </div>
            <Input
              id="output-directory"
              value={outputDirectory}
              onChange={(e) => setOutputDirectory(e.target.value)}
              placeholder={frameworkDefaults.outputDirectory}
              className="h-9 font-mono text-[13px]"
            />
          </div>

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
                      <SelectItem key={spec.slug} value={spec.slug}>
                        {spec.cpus} CPU, {spec.memory}MB RAM
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
              <p className="mt-1 text-[12px] text-muted-foreground">
                Select the CPU and memory specification for your site
              </p>
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
