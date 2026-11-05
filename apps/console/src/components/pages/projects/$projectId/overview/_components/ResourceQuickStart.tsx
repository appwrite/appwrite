import { useState, type ReactNode } from 'react'
import { Link, type LinkProps } from '@tanstack/react-router'
import { useTheme } from 'next-themes'
import {
  ArrowRight,
  Bell,
  Fingerprint,
  FileArchive,
  KeyRound,
  Link2,
  Lock,
  Mail,
  MessageSquareText,
  Table2,
  Ticket,
  Zap,
} from 'lucide-react'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { BitbucketIcon, GitHubIcon, GitLabIcon } from '@/lib/vcs/providers'
import {
  MySQLDolphinIcon,
  PostgresElephantIcon,
} from '../../databases/_components/database-mascot-icons'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { isCloudDedicatedDatabasesEnabled } from '@/lib/database-routes'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import type { OverviewResourceId } from '@/lib/overview-resources'

type QuickStartTile = {
  key: string
  /** Proper noun or API name; shown as is. */
  label: string
  visual: ReactNode
  link?: LinkProps
}

const DOT_GRID_STYLE = {
  backgroundImage:
    'radial-gradient(color-mix(in oklch, var(--muted-foreground) 28%, transparent) 1px, transparent 1px)',
  backgroundSize: '10px 10px',
  maskImage: 'radial-gradient(ellipse at center, black 35%, transparent 85%)',
  WebkitMaskImage:
    'radial-gradient(ellipse at center, black 35%, transparent 85%)',
}

/** Templates with screenshots bundled in `public/images/sites/templates`. */
const SITE_TEMPLATES = [
  { key: 'template-for-store', label: 'Store template' },
  { key: 'template-for-blog', label: 'Blog template' },
  { key: 'template-for-portfolio', label: 'Portfolio template' },
]

/** The starter function template supports each of these runtimes. */
const FUNCTION_RUNTIMES = [
  { runtime: 'node', label: 'Node.js' },
  { runtime: 'python', label: 'Python' },
  { runtime: 'go', label: 'Go' },
]

const FUNCTION_STARTER_TEMPLATE_ID = 'starter'

// English source copy; translated at render.
// Sites lead with the Git import instead.
const PITCH: Record<Exclude<OverviewResourceId, 'sites'>, string> = {
  databases: 'Store, query, and sync your app data with a managed database.',
  functions: 'Run backend code on HTTP requests, events, or a schedule.',
  storage:
    'Upload and serve files with permissions, encryption, and image previews.',
  auth: 'Sign users in with email, OAuth providers, phone, or passkeys.',
  messaging: 'Send email, SMS, and push notifications to your users.',
}

/**
 * Body of an empty service card: a one-line pitch and three quick starts that
 * open the matching flow one step in, or show what the service does.
 */
export function ResourceQuickStart({
  id,
  projectId,
  canStart,
}: {
  id: OverviewResourceId
  projectId: string
  /** False disables the quick starts (no permission). */
  canStart: boolean
}) {
  const t = useT()
  const { resolvedTheme } = useTheme()
  const isDark = resolvedTheme !== 'light'
  const tiles = quickStartTiles(id, projectId, isDark, t)

  return (
    <div className="flex flex-1 flex-col gap-3 px-2.5 pb-1.5 pt-2">
      {id === 'sites' ? (
        <GitImport projectId={projectId} disabled={!canStart} />
      ) : (
        <p className="line-clamp-2 text-[12px] leading-5 text-muted-foreground">
          {t(PITCH[id])}
        </p>
      )}
      <div className="mt-auto grid grid-cols-3 gap-2">
        {tiles.map((tile) => (
          <QuickStartTileLink key={tile.key} tile={tile} disabled={!canStart} />
        ))}
      </div>
    </div>
  )
}

/**
 * Sites deploy best from Git, so the card leads with importing a repository
 * and offers the templates below it.
 */
function GitImport({
  projectId,
  disabled,
}: {
  projectId: string
  disabled: boolean
}) {
  const t = useT()
  const content = (
    <>
      <span className="flex shrink-0 items-center -space-x-1.5">
        {[GitHubIcon, GitLabIcon, BitbucketIcon].map((Icon, index) => (
          <span
            key={index}
            className="flex h-7 w-7 items-center justify-center rounded-full border border-border bg-background text-foreground"
          >
            <Icon className="h-3.5 w-3.5" />
          </span>
        ))}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-[13px] font-medium text-foreground">
          {t('Import from Git')}
        </span>
        <span className="block truncate text-[12px] text-muted-foreground">
          {t('Auto-deploy on every push')}
        </span>
      </span>
      <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover/git:translate-x-0.5 group-hover/git:text-foreground rtl:-scale-x-100" />
    </>
  )
  const className =
    'group/git flex min-h-14 items-center gap-3 rounded-lg border border-border bg-muted/30 px-3 py-2 text-start transition-colors'
  if (disabled) {
    return <div className={cn(className, 'opacity-60')}>{content}</div>
  }
  return (
    <Link
      to="/projects/$projectId/sites/create"
      params={{ projectId }}
      className={cn(
        className,
        'hover:border-foreground/25 hover:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
      )}
      {...analyticsAttrs('overview-quick-start')}
    >
      {content}
    </Link>
  )
}

/** A tile with a link opens it; one without shows what the service does. */
function QuickStartTileLink({
  tile,
  disabled,
}: {
  tile: QuickStartTile
  /** No permission to follow the link: the tile dims. */
  disabled: boolean
}) {
  const interactive = !!tile.link && !disabled
  const content = (
    <>
      <span
        className={cn(
          'relative block aspect-[16/10] w-full overflow-hidden rounded-lg border border-border bg-muted/30 transition-colors',
          interactive && 'group-hover/tile:border-foreground/25',
        )}
      >
        {tile.visual}
      </span>
      <span
        className={cn(
          'mt-1.5 block truncate text-center text-[12px] text-muted-foreground transition-colors',
          interactive && 'group-hover/tile:text-foreground',
        )}
        title={tile.label}
      >
        {tile.label}
      </span>
    </>
  )
  if (!tile.link) return <div className="min-w-0">{content}</div>
  if (disabled) return <div className="min-w-0 opacity-60">{content}</div>
  return (
    <Link
      {...tile.link}
      className="group/tile min-w-0 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
      {...analyticsAttrs('overview-quick-start')}
    >
      {content}
    </Link>
  )
}

const TILE_ICON_CLASSNAME = 'h-5 w-5'

/** Icon chip over a dot grid, lifted on hover. */
function IconTileVisual({ children }: { children: ReactNode }) {
  return (
    <>
      <span aria-hidden className="absolute inset-0" style={DOT_GRID_STYLE} />
      <span className="absolute inset-0 flex items-center justify-center">
        <span className="flex h-10 w-10 items-center justify-center rounded-xl border border-border bg-card text-muted-foreground shadow-md transition-[transform,color] duration-200 group-hover/tile:-translate-y-0.5 group-hover/tile:text-foreground motion-reduce:transform-none">
          {children}
        </span>
      </span>
    </>
  )
}

function TemplateScreenshot({ src, alt }: { src: string; alt: string }) {
  const [loaded, setLoaded] = useState(false)
  return (
    <img
      src={src}
      alt={alt}
      loading="lazy"
      onLoad={() => setLoaded(true)}
      className={cn(
        'absolute inset-0 h-full w-full object-cover object-top transition-[opacity,transform] duration-300 group-hover/tile:scale-[1.04] motion-reduce:transform-none',
        loaded ? 'opacity-100' : 'opacity-0',
      )}
    />
  )
}

function quickStartTiles(
  id: OverviewResourceId,
  projectId: string,
  isDark: boolean,
  t: (text: string) => string,
): QuickStartTile[] {
  switch (id) {
    case 'databases':
      return databaseTiles(projectId, t)
    case 'sites':
      return siteTiles(projectId, isDark)
    case 'functions':
      return functionTiles(projectId)
    case 'storage':
      return storageTiles(t)
    case 'auth':
      return authTiles(projectId, t)
    case 'messaging':
      return messagingTiles(projectId, t)
  }
}

function databaseTiles(
  projectId: string,
  t: (text: string) => string,
): QuickStartTile[] {
  if (!isCloudDedicatedDatabasesEnabled()) {
    // Self-hosted creates TablesDB only, so the tiles show what it does.
    return [
      {
        key: 'tables',
        label: 'TablesDB',
        visual: (
          <IconTileVisual>
            <Table2 className={TILE_ICON_CLASSNAME} />
          </IconTileVisual>
        ),
      },
      {
        key: 'relationships',
        label: t('Relationships'),
        visual: (
          <IconTileVisual>
            <Link2 className={TILE_ICON_CLASSNAME} />
          </IconTileVisual>
        ),
      },
      {
        key: 'realtime',
        label: t('Realtime'),
        visual: (
          <IconTileVisual>
            <Zap className={TILE_ICON_CLASSNAME} />
          </IconTileVisual>
        ),
      },
    ]
  }
  const wizard = (type: string): LinkProps => ({
    to: '/projects/$projectId/databases/create',
    params: { projectId },
    search: { type },
  })
  return [
    {
      key: 'tablesdb',
      label: 'TablesDB',
      visual: (
        <IconTileVisual>
          <Table2 className={TILE_ICON_CLASSNAME} />
        </IconTileVisual>
      ),
      link: wizard('tablesdb'),
    },
    {
      key: 'postgres',
      label: 'PostgreSQL',
      visual: (
        <IconTileVisual>
          <PostgresElephantIcon className={TILE_ICON_CLASSNAME} />
        </IconTileVisual>
      ),
      link: wizard('postgres'),
    },
    {
      key: 'mysql',
      label: 'MySQL',
      visual: (
        <IconTileVisual>
          <MySQLDolphinIcon className={TILE_ICON_CLASSNAME} />
        </IconTileVisual>
      ),
      link: wizard('mysql'),
    },
  ]
}

function siteTiles(projectId: string, isDark: boolean): QuickStartTile[] {
  return SITE_TEMPLATES.map((template) => ({
    key: template.key,
    label: template.label,
    visual: (
      <TemplateScreenshot
        src={`/images/sites/templates/${template.key}-${isDark ? 'dark' : 'light'}.png`}
        alt=""
      />
    ),
    link: {
      to: '/projects/$projectId/sites/create/templates/$template',
      params: { projectId, template: encodeURIComponent(template.key) },
    },
  }))
}

function functionTiles(projectId: string): QuickStartTile[] {
  return FUNCTION_RUNTIMES.map(({ runtime, label }) => ({
    key: runtime,
    label,
    visual: (
      <IconTileVisual>
        <RuntimeIcon
          runtime={runtime}
          size="md"
          className={TILE_ICON_CLASSNAME}
        />
      </IconTileVisual>
    ),
    link: {
      to: '/projects/$projectId/functions/create/template/$templateId',
      params: { projectId, templateId: FUNCTION_STARTER_TEMPLATE_ID },
      search: { runtime },
    },
  }))
}

/** Bucket creation has no presets, so the tiles show what a bucket does. */
function storageTiles(t: (text: string) => string): QuickStartTile[] {
  return [
    {
      key: 'encryption',
      label: t('Encryption'),
      visual: (
        <IconTileVisual>
          <Lock className={TILE_ICON_CLASSNAME} />
        </IconTileVisual>
      ),
    },
    {
      key: 'compression',
      label: t('Compression'),
      visual: (
        <IconTileVisual>
          <FileArchive className={TILE_ICON_CLASSNAME} />
        </IconTileVisual>
      ),
    },
    {
      key: 'file-tokens',
      label: t('File tokens'),
      visual: (
        <IconTileVisual>
          <Ticket className={TILE_ICON_CLASSNAME} />
        </IconTileVisual>
      ),
    },
  ]
}

/** Sign-in methods, each opening the settings page that turns it on. */
function authTiles(
  projectId: string,
  t: (text: string) => string,
): QuickStartTile[] {
  const params = { projectId }
  return [
    {
      key: 'email-password',
      label: t('Email/Password'),
      visual: (
        <IconTileVisual>
          <Mail className={TILE_ICON_CLASSNAME} />
        </IconTileVisual>
      ),
      link: { to: '/projects/$projectId/auth/settings', params },
    },
    {
      key: 'oauth',
      label: t('OAuth providers'),
      visual: (
        <IconTileVisual>
          <KeyRound className={TILE_ICON_CLASSNAME} />
        </IconTileVisual>
      ),
      link: { to: '/projects/$projectId/auth/social-providers', params },
    },
    {
      key: 'passkeys',
      label: t('Passkeys'),
      visual: (
        <IconTileVisual>
          <Fingerprint className={TILE_ICON_CLASSNAME} />
        </IconTileVisual>
      ),
      link: { to: '/projects/$projectId/auth/policies/passkeys', params },
    },
  ]
}

/** Channels; each opens the provider wizard, where the channel is picked. */
function messagingTiles(
  projectId: string,
  t: (text: string) => string,
): QuickStartTile[] {
  const link: LinkProps = {
    to: '/projects/$projectId/messaging/providers/create',
    params: { projectId },
  }
  return [
    {
      key: 'email',
      label: t('Email'),
      visual: (
        <IconTileVisual>
          <Mail className={TILE_ICON_CLASSNAME} />
        </IconTileVisual>
      ),
      link,
    },
    {
      key: 'sms',
      label: 'SMS',
      visual: (
        <IconTileVisual>
          <MessageSquareText className={TILE_ICON_CLASSNAME} />
        </IconTileVisual>
      ),
      link,
    },
    {
      key: 'push',
      label: t('Push notifications'),
      visual: (
        <IconTileVisual>
          <Bell className={TILE_ICON_CLASSNAME} />
        </IconTileVisual>
      ),
      link,
    },
  ]
}
