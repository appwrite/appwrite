/**
 * Build Settings Component
 *
 * Provides build configuration inputs (install command, build command, output directory)
 * with framework defaults and reset functionality.
 */

import { useState, useEffect, useMemo } from 'react'
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
import {
  frameworkHasSsrAdapter,
  getFrameworkAdapterDefaults,
} from '@/lib/frameworks'
import { StartCommandLabel } from '../_components/StartCommandLabel'
import { useWizard } from './WizardContext'
import { useT } from '@/lib/i18n/translate'

interface BuildSettingsProps {
  installCommand: string
  buildCommand: string
  outputDirectory: string
  startCommand?: string
  onInstallCommandChange: (value: string) => void
  onBuildCommandChange: (value: string) => void
  onOutputDirectoryChange: (value: string) => void
  onStartCommandChange?: (value: string) => void
  frameworkKey?: string
  disabled?: boolean
  className?: string
  defaultOpen?: boolean
}

export function BuildSettings({
  installCommand,
  buildCommand,
  outputDirectory,
  startCommand = '',
  onInstallCommandChange,
  onBuildCommandChange,
  onOutputDirectoryChange,
  onStartCommandChange,
  frameworkKey,
  disabled = false,
  className,
  defaultOpen = false,
}: BuildSettingsProps) {
  const t = useT()
  const { getFramework, getFrameworkDefaults } = useWizard()
  const framework = frameworkKey ? getFramework(frameworkKey) : undefined
  const showStartCommand = frameworkHasSsrAdapter(framework)
  const ssrDefaults = useMemo(
    () => getFrameworkAdapterDefaults(framework, 'ssr'),
    [framework],
  )

  const [defaults, setDefaults] = useState({
    installCommand: 'npm install',
    buildCommand: 'npm run build',
    outputDirectory: '.output',
    startCommand: '',
  })

  // Update defaults when framework changes
  useEffect(() => {
    if (frameworkKey) {
      const createDefaults = getFrameworkDefaults(frameworkKey)
      setDefaults({
        installCommand: createDefaults.installCommand,
        buildCommand: createDefaults.buildCommand,
        outputDirectory: createDefaults.outputDirectory,
        startCommand: ssrDefaults.startCommand,
      })
    }
  }, [frameworkKey, getFrameworkDefaults, ssrDefaults.startCommand])

  const handleResetInstall = () => {
    onInstallCommandChange(defaults.installCommand)
  }

  const handleResetBuild = () => {
    onBuildCommandChange(defaults.buildCommand)
  }

  const handleResetStart = () => {
    onStartCommandChange?.(defaults.startCommand)
  }

  const handleResetOutput = () => {
    onOutputDirectoryChange(defaults.outputDirectory)
  }

  const isInstallModified = installCommand !== defaults.installCommand
  const isBuildModified = buildCommand !== defaults.buildCommand
  const isStartModified = startCommand !== defaults.startCommand
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
            {t('Build')}
          </span>
        </AccordionTrigger>
        <AccordionContent className="px-6 pb-4 pt-0 border-t border-border">
          <div className="space-y-4 pt-4">
            {/* Install Command */}
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label htmlFor="install-command" className="text-[13px]">
                  {t('Install command')}
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
                    <RotateCcw className="me-1 h-3 w-3" />
                    {t('Reset')}
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
                  {t('Build command')}
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
                    <RotateCcw className="me-1 h-3 w-3" />
                    {t('Reset')}
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

            {showStartCommand && onStartCommandChange && (
              <div className="space-y-2">
                <StartCommandLabel
                  htmlFor="start-command"
                  trailing={
                    isStartModified ? (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={handleResetStart}
                        disabled={disabled}
                        className="h-6 px-2 text-[11px] text-muted-foreground hover:text-foreground"
                      >
                        <RotateCcw className="me-1 h-3 w-3" />
                        {t('Reset')}
                      </Button>
                    ) : undefined
                  }
                />
                <Input
                  id="start-command"
                  value={startCommand}
                  onChange={(e) => onStartCommandChange(e.target.value)}
                  placeholder={
                    ssrDefaults.startCommand || t('Enter start command')
                  }
                  disabled={disabled}
                  className="h-9 font-mono text-[13px]"
                />
              </div>
            )}

            {/* Output Directory */}
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label htmlFor="output-directory" className="text-[13px]">
                  {t('Output directory')}
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
                    <RotateCcw className="me-1 h-3 w-3" />
                    {t('Reset')}
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
