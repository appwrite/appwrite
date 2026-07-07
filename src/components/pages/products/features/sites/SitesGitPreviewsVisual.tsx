import { CheckCircle2, ChevronDown, QrCode } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

function BotAvatar() {
  return (
    <span className="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-background">
      <img src="/icons/appwrite.svg" alt="" className="size-4" aria-hidden />
    </span>
  )
}

export function SitesGitPreviewsVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      eyebrow="GitHub"
      title={t('Pull request')}
      headerIconSrc="/icons/github.svg"
    >
      <div className="flex gap-3">
        <BotAvatar />
        <div className="min-w-0 flex-1">
          <p className="text-[11px] text-muted-foreground">
            <span className="font-semibold text-foreground">appwrite</span> {t('bot')} · {t('2m ago')} {/* pragma: allowlist secret */}
          </p>

          <div className="mt-2 overflow-hidden rounded-lg border border-border bg-background/90 transition-colors group-hover/visual:border-foreground/10">
            <div className="flex items-center gap-2 border-b border-border px-3 py-2.5">
              <img src="/icons/appwrite.svg" alt="" className="size-4 shrink-0" aria-hidden />
              <p className="text-[12px] font-semibold text-foreground">{t('Appwrite Sites')}</p>
            </div>

            <div className="space-y-3 px-3 py-3">
              <div className="rounded-md border border-border bg-muted/20 px-2.5 py-1.5">
                <p className="text-[10px] text-muted-foreground">{t('Project ID')}</p>
                <p className="mt-0.5 font-mono text-[10px] text-foreground">69d7efb…8d27</p>
              </div>

              <div>
                <div className="flex items-center gap-1.5 text-[11px] font-medium text-foreground">
                  <ChevronDown className="size-3.5 text-muted-foreground" aria-hidden />
                  {t('Sites')} (1)
                </div>

                <div className="mt-2 overflow-x-auto rounded-lg border border-border">
                  <Table>
                    <TableHeader>
                      <TableRow className="hover:bg-transparent border-b border-border">
                        <TableHead className="px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                          {t('Site')}
                        </TableHead>
                        <TableHead className="px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                          {t('Status')}
                        </TableHead>
                        <TableHead className="px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                          {t('Logs')}
                        </TableHead>
                        <TableHead className="px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                          {t('Preview')}
                        </TableHead>
                        <TableHead className="w-10 px-2 py-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                          {t('QR')}
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      <TableRow className="hover:bg-muted/20">
                        <TableCell className="px-3 py-2.5">
                          <p className="text-[11px] font-medium text-foreground">marketing-site</p>
                        </TableCell>
                        <TableCell className="px-3 py-2.5">
                          <span className="inline-flex items-center gap-1">
                            <CheckCircle2
                              className="size-3.5 text-green-600 dark:text-green-400"
                              aria-hidden
                            />
                            <Badge variant="success" className="text-[9px]">
                              {t('Ready')}
                            </Badge>
                          </span>
                        </TableCell>
                        <TableCell className="px-3 py-2.5">
                          <span className="text-[10px] font-medium text-primary">{t('View Logs')}</span>
                        </TableCell>
                        <TableCell className="px-3 py-2.5">
                          <span className="text-[10px] font-medium text-primary transition-colors group-hover/visual:text-primary/80">
                            {t('Preview URL')}
                          </span>
                        </TableCell>
                        <TableCell className="px-2 py-2.5">
                          <span className="inline-flex size-7 items-center justify-center rounded-md border border-border bg-muted/20 text-muted-foreground">
                            <QrCode className="size-3.5" aria-hidden />
                          </span>
                        </TableCell>
                      </TableRow>
                    </TableBody>
                  </Table>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
