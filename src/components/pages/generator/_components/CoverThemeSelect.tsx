import {
  Select,
  SelectContent,
  SelectGroup,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { CoverThemePreviewThumb } from '@/components/pages/generator/_components/CoverThemePreviewThumb'
import { CoverThemeSelectItem } from '@/components/pages/generator/_components/CoverThemeSelectItem'
import type { CoverTheme } from '@/lib/cover-generator/constants'
import { getCoverTheme, listCoverThemesByFamily } from '@/lib/cover-generator/themes'

type CoverThemeSelectProps = {
  theme: CoverTheme
  onThemeChange: (theme: CoverTheme) => void
  className?: string
}

export function CoverThemeSelect({
  theme,
  onThemeChange,
  className,
}: CoverThemeSelectProps) {
  const selectedTheme = getCoverTheme(theme)

  return (
    <Select
      value={theme}
      onValueChange={(value) => {
        onThemeChange(value as CoverTheme)
      }}
    >
      <SelectTrigger className={className ?? 'h-8 w-full text-[12px]'}>
        <CoverThemePreviewThumb themeId={theme} />
        <SelectValue>{selectedTheme.label}</SelectValue>
      </SelectTrigger>
      <SelectContent className="min-w-[min(100vw-2rem,360px)]">
        {(['light', 'dark'] as const).map((family) => (
          <SelectGroup key={family}>
            <SelectLabel className="text-[11px] font-semibold uppercase tracking-wider">
              {family === 'light' ? 'Light backgrounds' : 'Dark backgrounds'}
            </SelectLabel>
            {listCoverThemesByFamily(family).map((themeOption) => (
              <CoverThemeSelectItem key={themeOption.id} theme={themeOption} />
            ))}
          </SelectGroup>
        ))}
      </SelectContent>
    </Select>
  )
}
