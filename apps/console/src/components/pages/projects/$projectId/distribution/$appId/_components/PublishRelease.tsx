import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import type { DistributionRelease } from '@/lib/appwrite/distribution'
import type { ReleaseDraft, ReleaseMode } from '@/lib/distribution/publish'
import {
  RELEASE_NOTES_LOCALE,
  RELEASE_VERSION_MAX_LENGTH,
} from '@/lib/distribution/releases'
import { useT } from '@/lib/i18n/translate'
import { ReleaseStatusBadge } from '../../_components/status-badges'

type PublishReleaseProps = {
  release: ReleaseDraft
  releases: DistributionRelease[]
  onChange: (changes: Partial<ReleaseDraft>) => void
}

export function PublishRelease({
  release,
  releases,
  onChange,
}: PublishReleaseProps) {
  const t = useT()
  const prefix = 'publish-release'

  return (
    <RadioGroup
      value={release.mode}
      onValueChange={(mode) => onChange({ mode: mode as ReleaseMode })}
      className="gap-3"
    >
      <div className="space-y-3 rounded-lg border border-border px-4 py-3">
        <div className="flex items-center gap-2">
          <RadioGroupItem id={`${prefix}-new`} value="new" />
          <Label
            htmlFor={`${prefix}-new`}
            className="cursor-pointer text-[13px] font-medium"
          >
            {t('Create a release')}
          </Label>
        </div>
        {release.mode === 'new' ? (
          <div className="space-y-4 ps-6">
            <div className="space-y-2">
              <Label htmlFor={`${prefix}-version`} className="text-[13px]">
                {t('Version')}
              </Label>
              <Input
                id={`${prefix}-version`}
                dir="ltr"
                value={release.version}
                onChange={(event) => onChange({ version: event.target.value })}
                maxLength={RELEASE_VERSION_MAX_LENGTH}
                placeholder="1.0.0"
                autoComplete="off"
                spellCheck={false}
                autoFocus
                className="h-9 font-mono text-[13px]"
              />
              <p className="text-[12px] text-muted-foreground">
                {t('The version people see in the stores, for example 1.4.0.')}
              </p>
            </div>
            <div className="space-y-2">
              <Label htmlFor={`${prefix}-notes`} className="text-[13px]">
                {t('Release notes')}{' '}
                <span dir="ltr" className="font-mono text-muted-foreground">
                  {RELEASE_NOTES_LOCALE}
                </span>
              </Label>
              <Textarea
                id={`${prefix}-notes`}
                value={release.notes}
                onChange={(event) => onChange({ notes: event.target.value })}
                placeholder={t('What is new in this release')}
                rows={4}
                className="text-[13px]"
              />
            </div>
          </div>
        ) : null}
      </div>
      <div className="space-y-3 rounded-lg border border-border px-4 py-3">
        <div className="flex items-center gap-2">
          <RadioGroupItem
            id={`${prefix}-existing`}
            value="existing"
            className="peer"
            disabled={releases.length === 0}
          />
          <Label
            htmlFor={`${prefix}-existing`}
            className="cursor-pointer text-[13px] font-medium peer-disabled:cursor-not-allowed peer-disabled:opacity-50"
          >
            {t('Add to an open release')}
          </Label>
        </div>
        {releases.length === 0 ? (
          <p className="ps-6 text-[12px] text-muted-foreground">
            {t('This app has no draft or active releases.')}
          </p>
        ) : null}
        {release.mode === 'existing' && releases.length > 0 ? (
          <div className="space-y-2 ps-6">
            <Label htmlFor={`${prefix}-select`} className="text-[13px]">
              {t('Release')}
            </Label>
            <Select
              value={release.releaseId}
              onValueChange={(releaseId) => onChange({ releaseId })}
            >
              <SelectTrigger
                id={`${prefix}-select`}
                className="h-9 w-full text-[13px]"
              >
                <SelectValue placeholder={t('Choose a release')} />
              </SelectTrigger>
              <SelectContent>
                {releases.map((item) => (
                  <SelectItem
                    key={item.$id}
                    value={item.$id}
                    className="text-[13px]"
                  >
                    <span dir="ltr" className="font-mono">
                      {item.version}
                    </span>
                    <ReleaseStatusBadge status={item.status} />
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        ) : null}
      </div>
    </RadioGroup>
  )
}
