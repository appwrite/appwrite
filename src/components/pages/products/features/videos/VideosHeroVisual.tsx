import { ArrowRight, Cpu, FileVideo, Radio, type LucideIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'

const PIPELINE_STEPS: { id: string; label: string; detail: string; icon: LucideIcon }[] = [
  { id: 'source', label: 'Storage file', detail: 'launch.mp4', icon: FileVideo },
  { id: 'encode', label: 'Encode', detail: '1080p · 720p · 480p · 360p', icon: Cpu },
  { id: 'stream', label: 'Stream', detail: 'master.m3u8', icon: Radio },
]

const OUTPUTS = ['HLS', 'DASH', 'CMAF'] as const

export function VideosHeroVisual() {
  const t = useT()

  return (
    <div className="mt-10 w-full">
      <div className="mx-auto flex max-w-3xl flex-col items-center gap-5">
        <div className="flex w-full flex-col gap-2 sm:flex-row sm:items-stretch">
          {PIPELINE_STEPS.map((step, index) => {
            const Icon = step.icon
            return (
              <div key={step.id} className="flex min-w-0 flex-1 items-stretch gap-2">
                <div className="flex min-w-0 flex-1 items-center gap-2.5 rounded-lg border border-border bg-background/80 px-3 py-2.5 text-start">
                  <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
                    <Icon className="size-3.5 text-muted-foreground" aria-hidden />
                  </span>
                  <div className="min-w-0">
                    <p className="text-[12px] font-medium text-foreground">{t(step.label)}</p>
                    <p className="truncate font-mono text-[10px] text-muted-foreground">
                      {step.detail}
                    </p>
                  </div>
                </div>
                {index < PIPELINE_STEPS.length - 1 ? (
                  <div className="hidden shrink-0 items-center sm:flex">
                    <ArrowRight
                      className="size-3.5 text-muted-foreground rtl:rotate-180"
                      aria-hidden
                    />
                  </div>
                ) : null}
              </div>
            )
          })}
        </div>

        <div className="flex flex-wrap items-center justify-center gap-2">
          {OUTPUTS.map((output) => (
            <Badge key={output} variant="inactive" className="h-7 px-2.5 text-[11px]">
              {output}
            </Badge>
          ))}
        </div>
      </div>
    </div>
  )
}
