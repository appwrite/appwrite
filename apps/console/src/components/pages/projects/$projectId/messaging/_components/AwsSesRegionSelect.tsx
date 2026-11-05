import { useMemo } from 'react'
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import {
  AWS_SES_REGION_OPTIONS,
  awsSesRegionSelectOptions,
} from '@/lib/messaging/aws-ses-regions'
import { useT } from '@/lib/i18n/translate'

type AwsSesRegionSelectProps = {
  value: string
  onValueChange: (value: string) => void
  id?: string
  disabled?: boolean
  className?: string
}

export function AwsSesRegionSelect({
  value,
  onValueChange,
  id,
  disabled,
  className,
}: AwsSesRegionSelectProps) {
  const t = useT()

  const items = useMemo(
    () =>
      awsSesRegionSelectOptions(value).map((option) => {
        const known = AWS_SES_REGION_OPTIONS.find(
          (region) => region.value === option.value,
        )
        const label = known?.name ?? option.label
        return {
          value: option.value,
          label: t(label),
          description: option.value,
          inlineDescription: true,
          searchText: `${label} ${option.value}`,
        }
      }),
    [value, t],
  )

  return (
    <SearchableSelect
      id={id}
      value={value}
      onValueChange={onValueChange}
      items={items}
      placeholder={t('Select a region')}
      searchPlaceholder={t('Search...')}
      emptyMessage={t('No results')}
      disabled={disabled}
      triggerClassName={className}
      listClassName="min-h-0"
    />
  )
}
