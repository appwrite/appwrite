'use client'

import { useState } from 'react'
import { CheckCircle2, Copy, Download, Search } from 'lucide-react'
import { BuildLogsView } from '@/components/global/shared/BuildLogsView'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
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
import { DEPLOYMENT_TABLE_STATUS_COLUMN_CLASS } from '@/lib/utils/deployment-status'
import { cn } from '@/lib/utils'

const SCROLL_FADE_MASK_CLASS =
  '[mask-image:linear-gradient(to_bottom,black_calc(100%-5rem),transparent)] [-webkit-mask-image:linear-gradient(to_bottom,black_calc(100%-5rem),transparent)]'

const MOCK_BUILD_LOGS = `[10:24:01] Starting build for marketing-site
[10:24:02] Restoring dependency cache
[10:24:03] \x1b[32mDependency cache restored in 1.2s\x1b[0m
[10:24:03] Running install command: pnpm install --frozen-lockfile
[10:24:05] Lockfile is up to date, resolution step skipped
[10:24:07] Packages: +42
[10:24:11] \x1b[32mDone in 8.1s (cache hit)\x1b[0m
[10:24:11] Running build command: npm run build
[10:24:14] Compiling application…
[10:24:18] Generating static pages
[10:24:21] Optimizing assets for production
[10:24:23] \x1b[32mBuild finished successfully\x1b[0m
[10:24:23] Packaging deployment artifact`

const DEPLOYMENTS = [
  {
    id: '67abc12f9e2d',
    status: 'active' as const,
    duration: '24s',
    created: '2m ago',
  },
  {
    id: '89def45a1c8b',
    status: 'ready' as const,
    duration: '28s',
    created: 'Yesterday',
  },
] as const

function MockDeploymentId({ id }: { id: string }) {
  return (
    <span className="inline-flex max-w-[7rem] items-center gap-1.5 truncate font-mono text-[11px] text-foreground">
      {id}
      <Copy className="size-3 shrink-0 text-muted-foreground/70" aria-hidden />
    </span>
  )
}

export function SitesBuildPerformanceVisual() {
  const t = useT()
  const [logsSearch, setLogsSearch] = useState('')

  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'overview', label: t('Overview') },
        { id: 'deployments', label: t('Deployments'), active: true },
        { id: 'settings', label: t('Settings') },
      ]}
      contentClassName="p-0"
    >
      <div className="grid min-h-[18rem] lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
        <div className="relative min-w-0 border-b border-border lg:border-b-0 lg:border-e">
          <div className="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
            <h3 className="text-[13px] font-semibold text-foreground">{t('Build logs')}</h3>
            <span className="shrink-0 text-[11px] text-muted-foreground">
              {t('Duration:')} <span className="font-medium text-foreground">24s</span>
            </span>
          </div>

          <div className="border-b border-border px-3 py-2.5">
            <div className="flex items-center gap-2">
              <div className="relative min-w-0 flex-1">
                <Search className="pointer-events-none absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                <Input
                  placeholder={t('Search logs...')}
                  value={logsSearch}
                  onChange={(event) => setLogsSearch(event.target.value)}
                  className="h-8 ps-8 text-[12px]"
                />
              </div>
              <span className="inline-flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-background text-muted-foreground">
                <Download className="size-3.5" aria-hidden />
              </span>
              <span className="inline-flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-background text-muted-foreground">
                <Copy className="size-3.5" aria-hidden />
              </span>
            </div>
          </div>

          <div className={cn('relative max-h-[14rem] overflow-hidden', SCROLL_FADE_MASK_CLASS)}>
            <BuildLogsView
              buildLogs={MOCK_BUILD_LOGS}
              searchTerm={logsSearch}
              highlightLineOnHover
              fontSizeClass="text-[10px] sm:text-[11px]"
              lineHorizontalPaddingClass="ps-3 pe-3"
              trailingPadding={false}
            />
          </div>
        </div>

        <div className="min-w-0 p-3 sm:p-4">
          <p className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Deployments')}
          </p>
          <div className="overflow-hidden rounded-lg border border-border bg-card">
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                    {t('Deployment ID')}
                  </TableHead>
                  <TableHead
                    className={cn(
                      'px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground',
                      DEPLOYMENT_TABLE_STATUS_COLUMN_CLASS,
                    )}
                  >
                    {t('Status')}
                  </TableHead>
                  <TableHead className="w-[72px] px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                    {t('Duration')}
                  </TableHead>
                  <TableHead className="w-[88px] px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                    {t('Created')}
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {DEPLOYMENTS.map((deployment) => {
                  const isActive = deployment.status === 'active'
                  return (
                    <TableRow
                      key={deployment.id}
                      className={cn(
                        'border-b border-border hover:bg-muted/50',
                        isActive && 'bg-muted/40 dark:bg-muted/35',
                      )}
                    >
                      <TableCell className="px-3 py-2.5">
                        <MockDeploymentId id={deployment.id} />
                      </TableCell>
                      <TableCell
                        className={cn('px-3 py-2.5', DEPLOYMENT_TABLE_STATUS_COLUMN_CLASS)}
                      >
                        {isActive ? (
                          <Badge variant="active" className="gap-1.5 text-[10px] font-medium">
                            <CheckCircle2 className="size-3" aria-hidden />
                            {t('Active')}
                          </Badge>
                        ) : (
                          <Badge
                            variant="deploymentReady"
                            className="gap-1.5 text-[10px] font-medium"
                          >
                            <CheckCircle2 className="size-3" aria-hidden />
                            {t('Ready')}
                          </Badge>
                        )}
                      </TableCell>
                      <TableCell className="px-3 py-2.5 text-[11px] text-foreground">
                        {deployment.duration}
                      </TableCell>
                      <TableCell className="px-3 py-2.5 text-[11px] text-muted-foreground">
                        {t(deployment.created)}
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </div>

          <div className="mt-3 rounded-lg border border-dashed border-border bg-muted/10 px-3 py-2.5">
            <p className="text-[11px] leading-5 text-muted-foreground">
              {t('Package manager stores are cached between builds. Deployment retention removes old inactive deployments to save storage. Path filters help Turborepo monorepos skip unnecessary deploys.')}
            </p>
          </div>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
