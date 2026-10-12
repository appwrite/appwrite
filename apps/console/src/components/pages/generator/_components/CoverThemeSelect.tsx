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
import { useT } from '@/lib/i18n/translate'
import {
  getCoverTheme,
  listCoverEditorThemesByFamily,
  resolveCoverEditorThemeId,
} from '@/lib/cover-generator/themes'
import { cn } from '@/lib/utils'

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
  const t = useT()
  const editorTheme = resolveCoverEditorThemeId(theme)
  const selectedTheme = getCoverTheme(editorTheme)

  return (
    <Select
      value={editorTheme}
      onValueChange={(value) => {
        onThemeChange(value as CoverTheme)
      }}
    >
      <SelectTrigger
        className={cn(
          'justify-start text-start *:data-[slot=select-value]:min-w-0 *:data-[slot=select-value]:flex-1 *:data-[slot=select-value]:justify-start',
          className ?? 'h-8 w-full text-[12px]',
        )}
      >
        <CoverThemePreviewThumb themeId={editorTheme} />
        <SelectValue>{t(selectedTheme.label)}</SelectValue>
      </SelectTrigger>
      <SelectContent
        align="start"
        className="min-w-[min(100vw-2rem,360px)] text-start"
      >
        {(['light', 'dark'] as const).map((family) => (
          <SelectGroup key={family}>
            <SelectLabel className="text-start text-[11px] font-semibold uppercase tracking-wider">
              {t(family === 'light' ? 'Light backgrounds' : 'Dark backgrounds')}
            </SelectLabel>
            {listCoverEditorThemesByFamily(family).map((themeOption) => (
              <CoverThemeSelectItem key={themeOption.id} theme={themeOption} />
            ))}
          </SelectGroup>
        ))}
      </SelectContent>
    </Select>
  )
}
