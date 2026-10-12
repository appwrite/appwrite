import { useState } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { toast } from 'sonner'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
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
import { useCreateDistributionApp } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { PlatformIcon, platformLabel } from './platform'

interface CreateAppProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

const PLATFORMS = ['android', 'ios', 'windows'] as const

const FRAMEWORKS = [
  { value: 'flutter', label: 'Flutter' },
  { value: 'react-native', label: 'React Native' },
  { value: 'expo', label: 'Expo' },
  { value: 'android', label: 'Android' },
  { value: 'ios', label: 'iOS' },
  { value: 'maui', label: '.NET MAUI' },
  { value: 'other', label: 'Other' },
]

export function CreateApp({ open, onOpenChange }: CreateAppProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const createApp = useCreateDistributionApp(projectId)

  const [name, setName] = useState('')
  const [framework, setFramework] = useState('flutter')
  const [platforms, setPlatforms] = useState<string[]>(['android'])
  const [identifier, setIdentifier] = useState('')

  const reset = () => {
    setName('')
    setFramework('flutter')
    setPlatforms(['android'])
    setIdentifier('')
  }

  const togglePlatform = (platform: string) => {
    setPlatforms((current) =>
      current.includes(platform)
        ? current.filter((item) => item !== platform)
        : [...current, platform],
    )
  }

  const canSubmit =
    !!projectId && name.trim().length > 0 && platforms.length > 0

  const handleSubmit = async () => {
    if (!canSubmit) return
    try {
      const trimmedId = identifier.trim() || undefined
      const usesBundleId =
        platforms.includes('ios') && !platforms.includes('android')
      const usesPackageIdentity =
        platforms.length === 1 && platforms[0] === 'windows'
      const app = await createApp.mutateAsync({
        name: name.trim(),
        platforms,
        framework,
        applicationId:
          trimmedId && !usesBundleId && !usesPackageIdentity
            ? trimmedId
            : undefined,
        bundleId: trimmedId && usesBundleId ? trimmedId : undefined,
        packageIdentity:
          trimmedId && usesPackageIdentity ? trimmedId : undefined,
      })
      toast.success(t('Distribution app created'))
      onOpenChange(false)
      reset()
      navigate({
        to: '/projects/$projectId/stores/$appId',
        params: { projectId: projectId!, appId: app.$id },
      })
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : t('Failed to create distribution app'),
      )
    }
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) reset()
        onOpenChange(next)
      }}
    >
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Create distribution app')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Pick a framework and platforms to start shipping builds to the app stores.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="flex flex-col gap-4 px-6 py-4">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="distribution-app-name">{t('Name')}</Label>
            <Input
              id="distribution-app-name"
              value={name}
              onChange={(event) => setName(event.target.value)}
              placeholder={t('My app')}
            />
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="distribution-app-framework">{t('Framework')}</Label>
            <Select value={framework} onValueChange={setFramework}>
              <SelectTrigger id="distribution-app-framework">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {FRAMEWORKS.map((item) => (
                  <SelectItem key={item.value} value={item.value}>
                    {item.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="flex flex-col gap-1.5">
            <Label>{t('Platforms')}</Label>
            <div className="flex flex-wrap gap-2">
              {PLATFORMS.map((platform) => {
                const active = platforms.includes(platform)
                return (
                  <button
                    key={platform}
                    type="button"
                    onClick={() => togglePlatform(platform)}
                    className={cn(
                      'flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-[13px] transition-colors',
                      active
                        ? 'border-primary bg-primary/10 text-foreground'
                        : 'border-border text-muted-foreground hover:bg-muted/50',
                    )}
                  >
                    <PlatformIcon platform={platform} />
                    {platformLabel(platform)}
                  </button>
                )
              })}
            </div>
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="distribution-app-identifier">
              {t('Identifier (optional)')}
            </Label>
            <Input
              id="distribution-app-identifier"
              value={identifier}
              onChange={(event) => setIdentifier(event.target.value)}
              placeholder="com.example.app"
            />
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            className="h-9 text-[13px]"
            onClick={() => onOpenChange(false)}
            disabled={createApp.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            className="h-9 text-[13px]"
            onClick={handleSubmit}
            disabled={!canSubmit || createApp.isPending}
          >
            {t('Create')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
