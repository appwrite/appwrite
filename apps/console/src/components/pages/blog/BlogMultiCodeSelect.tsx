import { useEffect, useState } from 'react'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  setPreferredCodeLanguage,
  usePreferredCodeLanguage,
} from '@/lib/blog/preferred-code-language'

export type BlogMultiCodeLanguage = { id: string; label: string }

type BlogMultiCodeSelectProps = {
  /** The `[data-blog-multicode]` card whose panels this select controls. */
  group: HTMLElement
  languages: BlogMultiCodeLanguage[]
}

/** Shows the chosen panel of a `{% multicode %}` card and retargets its copy button. */
function selectMultiCodePanel(group: HTMLElement, languageId: string) {
  let active: HTMLElement | null = null
  for (const panel of group.querySelectorAll<HTMLElement>(
    '[data-blog-multicode-panel]',
  )) {
    const isActive =
      panel.getAttribute('data-blog-multicode-panel') === languageId
    panel.hidden = !isActive
    if (isActive) active = panel
  }
  const copyButton = group.querySelector<HTMLElement>('[data-blog-copy]')
  if (copyButton && active) {
    copyButton.setAttribute(
      'data-blog-copy',
      active.getAttribute('data-blog-code') ?? '',
    )
  }
}

/**
 * Language dropdown for a `{% multicode %}` card, styled like the docs code
 * example header. Follows the page-wide preference when this card has that
 * language and otherwise keeps whatever it last showed.
 */
export function BlogMultiCodeSelect({
  group,
  languages,
}: BlogMultiCodeSelectProps) {
  const preferred = usePreferredCodeLanguage()
  const [local, setLocal] = useState(languages[0]?.id ?? '')
  const value =
    preferred && languages.some((language) => language.id === preferred)
      ? preferred
      : local

  useEffect(() => {
    setLocal(value)
    selectMultiCodePanel(group, value)
  }, [group, value])

  return (
    <Select
      value={value}
      onValueChange={(next) => {
        setLocal(next)
        setPreferredCodeLanguage(next)
      }}
    >
      <SelectTrigger
        size="sm"
        className="flex h-7 min-w-[9rem] max-w-full items-center bg-transparent text-[12px] font-medium text-muted-foreground hover:bg-transparent dark:bg-transparent dark:hover:bg-transparent"
        aria-label="Code language"
      >
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {languages.map((language) => (
          <SelectItem
            key={language.id}
            value={language.id}
            className="text-[12px]"
          >
            {language.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
