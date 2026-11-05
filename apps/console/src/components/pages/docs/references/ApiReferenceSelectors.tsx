'use client'

import type { ReactNode } from 'react'
import * as SelectPrimitive from '@radix-ui/react-select'
import { CheckIcon } from 'lucide-react'
import {
  Select,
  SelectContent,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  PLATFORM_LABELS,
  REFERENCE_PLATFORMS,
  REFERENCE_VERSIONS,
  type ReferencePlatform,
  type ReferenceVersion,
} from '@/lib/docs/references/constants'
import { cn } from '@/lib/utils'
import {
  ReferencePlatformIcon,
  ReferenceVersionIcon,
} from './ReferenceSelectIcons'

type ApiReferenceSidebarSelectorsProps = {
  version: ReferenceVersion
  platform: ReferencePlatform
  platformMode: 'client' | 'server'
  onVersionChange: (version: ReferenceVersion) => void
  onPlatformChange: (platform: ReferencePlatform) => void
}

const REFERENCE_SELECT_ITEM_CLASS =
  "focus:bg-accent focus:text-accent-foreground [&_svg:not([class*='text-'])]:text-muted-foreground relative flex w-full cursor-pointer items-center gap-2 rounded-sm py-1.5 pe-8 ps-2 text-sm outline-hidden select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4"

const REFERENCE_SELECT_TRIGGER_VALUE_CLASS =
  'flex min-w-0 flex-1 items-center justify-start gap-1.5 overflow-hidden'

function ReferenceSelectTriggerValue({
  icon,
  children,
}: {
  icon: ReactNode
  children: ReactNode
}) {
  return (
    <span className={REFERENCE_SELECT_TRIGGER_VALUE_CLASS}>
      {icon}
      {children}
    </span>
  )
}

function ReferenceSelectItem({
  value,
  textValue,
  icon,
  label,
  className,
}: {
  value: string
  textValue: string
  icon: ReactNode
  label: string
  className?: string
}) {
  return (
    <SelectPrimitive.Item
      value={value}
      textValue={textValue}
      className={cn(REFERENCE_SELECT_ITEM_CLASS, className)}
    >
      <span className="absolute end-2 flex size-3.5 items-center justify-center">
        <SelectPrimitive.ItemIndicator>
          <CheckIcon className="size-4" />
        </SelectPrimitive.ItemIndicator>
      </span>
      {icon}
      <SelectPrimitive.ItemText>{label}</SelectPrimitive.ItemText>
    </SelectPrimitive.Item>
  )
}

export function ApiReferenceSidebarSelectors({
  version,
  platform,
  platformMode,
  onVersionChange,
  onPlatformChange,
}: ApiReferenceSidebarSelectorsProps) {
  const platformsForMode = REFERENCE_PLATFORMS.filter((item) =>
    platformMode === 'client' ? item.startsWith('client-') : item.startsWith('server-'),
  )

  return (
    <div className="space-y-3 px-2">
      <div className="space-y-1.5">
        <label
          htmlFor="api-ref-sidebar-platform"
          className="block px-0.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground"
        >
          SDK
        </label>
        <Select value={platform} onValueChange={(value) => onPlatformChange(value as ReferencePlatform)}>
          <SelectTrigger id="api-ref-sidebar-platform" className="h-9 w-full text-[13px]">
            <ReferenceSelectTriggerValue
              icon={<ReferencePlatformIcon platform={platform} />}
            >
              <SelectValue />
            </ReferenceSelectTriggerValue>
          </SelectTrigger>
          <SelectContent>
            {platformsForMode.map((item) => (
              <ReferenceSelectItem
                key={item}
                value={item}
                textValue={PLATFORM_LABELS[item]}
                className="text-[13px]"
                icon={<ReferencePlatformIcon platform={item} />}
                label={PLATFORM_LABELS[item]}
              />
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="space-y-1.5">
        <label
          htmlFor="api-ref-sidebar-version"
          className="block px-0.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground"
        >
          Version
        </label>
        <Select
          value={version}
          onValueChange={(value) => onVersionChange(value as ReferenceVersion)}
        >
          <SelectTrigger id="api-ref-sidebar-version" className="h-9 w-full text-[13px]">
            <ReferenceSelectTriggerValue
              icon={<ReferenceVersionIcon version={version} />}
            >
              <SelectValue />
            </ReferenceSelectTriggerValue>
          </SelectTrigger>
          <SelectContent>
            <ReferenceSelectItem
              value="cloud"
              textValue="Cloud"
              className="text-[13px]"
              icon={<ReferenceVersionIcon version="cloud" />}
              label="Cloud"
            />
            {REFERENCE_VERSIONS.filter((item) => item !== 'cloud').map((item) => (
              <ReferenceSelectItem
                key={item}
                value={item}
                textValue={item}
                className="text-[13px]"
                icon={<ReferenceVersionIcon version={item} />}
                label={item}
              />
            ))}
          </SelectContent>
        </Select>
      </div>
    </div>
  )
}
