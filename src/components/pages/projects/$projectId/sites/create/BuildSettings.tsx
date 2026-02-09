/**
 * Build Settings Component
 *
 * Provides build configuration inputs (install command, build command, output directory)
 * with framework defaults and reset functionality.
 */

import { useState, useEffect } from 'react'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import { RotateCcw } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useWizard } from './WizardContext'

interface BuildSettingsProps {
  installCommand: string
  buildCommand: string
  outputDirectory: string
  onInstallCommandChange: (value: string) => void
  onBuildCommandChange: (value: string) => void
  onOutputDirectoryChange: (value: string) => void
  frameworkKey?: string
  disabled?: boolean
  className?: string
  defaultOpen?: boolean
}

export function BuildSettings({
  installCommand,
  buildCommand,
  outputDirectory,
  onInstallCommandChange,
  onBuildCommandChange,
  onOutputDirectoryChange,
  frameworkKey,
  disabled = false,
  className,
  defaultOpen = false,
}: BuildSettingsProps) {
  const { getFrameworkDefaults } = useWizard()
  const [defaults, setDefaults] = useState({
    installCommand: 'npm install',
    buildCommand: 'npm run build',
    outputDirectory: '.output',
  })

  // Update defaults when framework changes
  useEffect(() => {
    if (frameworkKey) {
      setDefaults(getFrameworkDefaults(frameworkKey))
    }
  }, [frameworkKey, getFrameworkDefaults])

  const handleResetInstall = () => {
    onInstallCommandChange(defaults.installCommand)
  }

  const handleResetBuild = () => {
    onBuildCommandChange(defaults.buildCommand)
  }

  const handleResetOutput = () => {
    onOutputDirectoryChange(defaults.outputDirectory)
  }

  const isInstallModified = installCommand !== defaults.installCommand
  const isBuildModified = buildCommand !== defaults.buildCommand
  const isOutputModified = outputDirectory !== defaults.outputDirectory

  return (
    <Accordion
      type="single"
      collapsible
      defaultValue={defaultOpen ? 'build-settings' : undefined}
      className={cn(
        'rounded-xl border border-border bg-card/50 overflow-hidden',
        className,
      )}
    >
      <AccordionItem value="build-settings" className="border-none">
        <AccordionTrigger className="px-6 py-4 hover:no-underline hover:bg-transparent cursor-pointer">
          <span className="text-[15px] font-semibold text-foreground">
            Build
          </span>
        </AccordionTrigger>
        <AccordionContent className="px-6 pb-4 pt-0 border-t border-border">
          <div className="space-y-4 pt-4">
            {/* Install Command */}
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label htmlFor="install-command" className="text-[13px]">
                  Install command
                </Label>
                {isInstallModified && (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={handleResetInstall}
                    disabled={disabled}
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
                onChange={(e) => onInstallCommandChange(e.target.value)}
                placeholder={defaults.installCommand}
                disabled={disabled}
                className="h-9 font-mono text-[13px]"
              />
            </div>

            {/* Build Command */}
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label htmlFor="build-command" className="text-[13px]">
                  Build command
                </Label>
                {isBuildModified && (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={handleResetBuild}
                    disabled={disabled}
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
                onChange={(e) => onBuildCommandChange(e.target.value)}
                placeholder={defaults.buildCommand}
                disabled={disabled}
                className="h-9 font-mono text-[13px]"
              />
            </div>

            {/* Output Directory */}
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label htmlFor="output-directory" className="text-[13px]">
                  Output directory
                </Label>
                {isOutputModified && (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={handleResetOutput}
                    disabled={disabled}
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
                onChange={(e) => onOutputDirectoryChange(e.target.value)}
                placeholder={defaults.outputDirectory}
                disabled={disabled}
                className="h-9 font-mono text-[13px]"
              />
            </div>
          </div>
        </AccordionContent>
      </AccordionItem>
    </Accordion>
  )
}
