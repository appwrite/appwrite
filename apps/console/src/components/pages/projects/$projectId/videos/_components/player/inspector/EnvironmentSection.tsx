import { useEffect, useState, type ReactNode } from 'react'
import { CheckCircle2, MinusCircle, XCircle } from 'lucide-react'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { cn } from '@/lib/utils'
import { formatBitrate, formatResolution } from '@/lib/utils/video-format'
import { useT } from '@/lib/i18n/translate'
import type { StreamPlayerState, StreamVariantInfo } from '../useStreamPlayer'
import {
  CELL_CLASS,
  Empty,
  HEAD_CLASS,
  SectionCard,
  levelColor,
  levelName,
} from './shared'

type Support = boolean | null

type DecodeResult = {
  supported: boolean
  smooth: boolean
  powerEfficient: boolean
}

type NetworkInformation = {
  effectiveType?: string
  downlink?: number
  rtt?: number
  saveData?: boolean
}

const CODEC_PROBES: Array<{ label: string; mime: string }> = [
  { label: 'H.264 / AVC', mime: 'video/mp4; codecs="avc1.640028"' },
  { label: 'H.265 / HEVC', mime: 'video/mp4; codecs="hvc1.1.6.L93.B0"' },
  { label: 'VP9', mime: 'video/mp4; codecs="vp09.00.10.08"' },
  { label: 'AV1', mime: 'video/mp4; codecs="av01.0.05M.08"' },
  { label: 'AAC', mime: 'audio/mp4; codecs="mp4a.40.2"' },
  { label: 'Opus', mime: 'audio/mp4; codecs="opus"' },
  { label: 'Dolby Digital (AC-3)', mime: 'audio/mp4; codecs="ac-3"' },
  { label: 'Dolby Digital Plus (E-AC-3)', mime: 'audio/mp4; codecs="ec-3"' },
  { label: 'FLAC', mime: 'audio/mp4; codecs="flac"' },
]

const KEY_SYSTEMS: Array<{ label: string; id: string }> = [
  { label: 'Widevine', id: 'com.widevine.alpha' },
  { label: 'PlayReady', id: 'com.microsoft.playready' },
  { label: 'FairPlay', id: 'com.apple.fps' },
  { label: 'Clear Key', id: 'org.w3.clearkey' },
]

function mediaSourceCtor(): { isTypeSupported(type: string): boolean } | null {
  const scope = window as unknown as {
    MediaSource?: { isTypeSupported(type: string): boolean }
    ManagedMediaSource?: { isTypeSupported(type: string): boolean }
  }
  return scope.MediaSource ?? scope.ManagedMediaSource ?? null
}

function isMimeSupported(mime: string): boolean {
  return mediaSourceCtor()?.isTypeSupported(mime) ?? false
}

function variantKey(level: StreamVariantInfo): string {
  return `${level.index}-${level.videoCodec ?? ''}-${level.width}x${level.height}`
}

async function probeVariant(
  level: StreamVariantInfo,
): Promise<DecodeResult | null> {
  if (!level.videoCodec || !navigator.mediaCapabilities?.decodingInfo) {
    return null
  }
  try {
    const info = await navigator.mediaCapabilities.decodingInfo({
      type: 'media-source',
      video: {
        contentType: `video/mp4; codecs="${level.videoCodec}"`,
        width: level.width || 1280,
        height: level.height || 720,
        bitrate: level.bitrate || 1_000_000,
        framerate: level.frameRate || 30,
      },
    })
    return {
      supported: info.supported,
      smooth: info.smooth,
      powerEfficient: info.powerEfficient,
    }
  } catch {
    return null
  }
}

async function probeKeySystem(id: string): Promise<boolean> {
  if (!navigator.requestMediaKeySystemAccess) return false
  try {
    await navigator.requestMediaKeySystemAccess(id, [
      {
        initDataTypes: ['cenc', 'sinf', 'skd', 'keyids'],
        videoCapabilities: [{ contentType: 'video/mp4; codecs="avc1.42E01E"' }],
      },
    ])
    return true
  } catch {
    return false
  }
}

function useVariantDecodeInfo(levels: StreamVariantInfo[]) {
  const [results, setResults] = useState<Record<string, DecodeResult | null>>(
    {},
  )
  const signature = levels.map(variantKey).join('|')
  useEffect(() => {
    let cancelled = false
    void Promise.all(
      levels.map(
        async (level) =>
          [variantKey(level), await probeVariant(level)] as const,
      ),
    ).then((entries) => {
      if (!cancelled) setResults(Object.fromEntries(entries))
    })
    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [signature])
  return results
}

function useKeySystems() {
  const [results, setResults] = useState<Record<string, Support>>({})
  useEffect(() => {
    let cancelled = false
    void Promise.all(
      KEY_SYSTEMS.map(
        async (system) => [system.id, await probeKeySystem(system.id)] as const,
      ),
    ).then((entries) => {
      if (!cancelled) setResults(Object.fromEntries(entries))
    })
    return () => {
      cancelled = true
    }
  }, [])
  return results
}

function SupportIcon({ value }: { value: Support }) {
  if (value == null) {
    return <MinusCircle className="h-3.5 w-3.5 text-muted-foreground/60" />
  }
  return value ? (
    <CheckCircle2 className="h-3.5 w-3.5 text-emerald-500" />
  ) : (
    <XCircle className="h-3.5 w-3.5 text-red-500" />
  )
}

function SupportCell({ value, label }: { value: Support; label?: string }) {
  const t = useT()
  return (
    <span className="inline-flex items-center gap-1.5">
      <SupportIcon value={value} />
      <span
        className={cn(
          'text-[12px]',
          value === false ? 'text-foreground' : 'text-muted-foreground',
        )}
      >
        {label ?? (value == null ? t('Unknown') : value ? t('Yes') : t('No'))}
      </span>
    </span>
  )
}

function DetailList({ rows }: { rows: Array<[string, ReactNode]> }) {
  return (
    <dl className="divide-y divide-border">
      {rows.map(([label, value]) => (
        <div
          key={label}
          className="flex items-center justify-between gap-4 px-4 py-2.5"
        >
          <dt className="text-[12px] text-muted-foreground">{label}</dt>
          <dd className="min-w-0 truncate text-end font-mono text-[12px] text-foreground">
            {value}
          </dd>
        </div>
      ))}
    </dl>
  )
}

export function EnvironmentSection({
  player,
  renderedHeight,
}: {
  player: StreamPlayerState
  renderedHeight: number | null
}) {
  const t = useT()
  const decode = useVariantDecodeInfo(player.levels)
  const keySystems = useKeySystems()

  const connection = (
    navigator as Navigator & { connection?: NetworkInformation }
  ).connection
  const hasMse = mediaSourceCtor() != null
  const managedMse = 'ManagedMediaSource' in window
  const nativeHls =
    document
      .createElement('video')
      .canPlayType('application/vnd.apple.mpegurl') !== ''
  const hdr = window.matchMedia('(dynamic-range: high)').matches
  const wideGamut = window.matchMedia('(color-gamut: p3)').matches
  const pip =
    'pictureInPictureEnabled' in document && document.pictureInPictureEnabled

  const unsupportedLevels = player.levels.filter(
    (level) => decode[variantKey(level)]?.supported === false,
  )
  const choppyLevels = player.levels.filter((level) => {
    const info = decode[variantKey(level)]
    return info?.supported && !info.smooth
  })

  return (
    <div className="space-y-4">
      <SectionCard
        title={t('This stream on this device')}
        description={t(
          'The browser decoder checks each rendition: whether it can play it, play it without dropping frames, and play it with hardware acceleration.',
        )}
        bodyClassName="p-0"
      >
        {player.levels.length === 0 ? (
          <div className="p-4">
            <Empty>
              {player.engine === 'native'
                ? t('Rendition checks are only available for adaptive streams.')
                : t('No levels parsed yet.')}
            </Empty>
          </div>
        ) : (
          <>
            {unsupportedLevels.length > 0 || choppyLevels.length > 0 ? (
              <p className="border-b border-border bg-amber-500/5 px-4 py-2.5 text-[12px] text-amber-700 dark:text-amber-400">
                {unsupportedLevels.length > 0
                  ? t(
                      'Some renditions cannot be decoded here. The player skips them, so viewers on this device never get that quality.',
                    )
                  : t(
                      'Some renditions may drop frames on this device. Consider a lighter codec profile or a lower frame rate.',
                    )}
              </p>
            ) : null}
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className={HEAD_CLASS}>
                      {t('Rendition')}
                    </TableHead>
                    <TableHead className={HEAD_CLASS}>{t('Codecs')}</TableHead>
                    <TableHead className={HEAD_CLASS}>{t('Bitrate')}</TableHead>
                    <TableHead className={HEAD_CLASS}>
                      {t('Supported')}
                    </TableHead>
                    <TableHead className={HEAD_CLASS}>{t('Smooth')}</TableHead>
                    <TableHead className={HEAD_CLASS}>
                      {t('Hardware decoding')}
                    </TableHead>
                    <TableHead className={HEAD_CLASS}>
                      {t('Fits player')}
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {[...player.levels]
                    .sort((a, b) => b.bitrate - a.bitrate)
                    .map((level) => {
                      const info = decode[variantKey(level)]
                      const fits =
                        renderedHeight && level.height
                          ? level.height <= renderedHeight * 1.25
                          : null
                      return (
                        <TableRow key={variantKey(level)}>
                          <TableCell className={CELL_CLASS}>
                            <span className="inline-flex items-center gap-1.5">
                              <span
                                className="size-2 rounded-[2px]"
                                style={{ background: levelColor(level.index) }}
                              />
                              <span className="font-semibold">
                                {levelName(level)}
                              </span>
                              <span className="text-muted-foreground">
                                {formatResolution(level.width, level.height)}
                                {level.frameRate ? ` @ ${level.frameRate}` : ''}
                              </span>
                            </span>
                          </TableCell>
                          <TableCell className={CELL_CLASS}>
                            {[level.videoCodec, level.audioCodec]
                              .filter(Boolean)
                              .join(', ') || '-'}
                          </TableCell>
                          <TableCell className={CELL_CLASS}>
                            {formatBitrate(level.bitrate)}
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <SupportCell value={info ? info.supported : null} />
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <SupportCell
                              value={info?.supported ? info.smooth : null}
                            />
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <SupportCell
                              value={
                                info?.supported ? info.powerEfficient : null
                              }
                            />
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <SupportCell
                              value={fits}
                              label={
                                fits == null
                                  ? undefined
                                  : fits
                                    ? t('Yes')
                                    : t('Larger than the player')
                              }
                            />
                          </TableCell>
                        </TableRow>
                      )
                    })}
                </TableBody>
              </Table>
            </div>
          </>
        )}
      </SectionCard>

      <div className="grid gap-4 xl:grid-cols-2">
        <SectionCard title={t('Codec support')} bodyClassName="p-0">
          <DetailList
            rows={CODEC_PROBES.map((probe) => [
              probe.label,
              <SupportCell
                key={probe.mime}
                value={isMimeSupported(probe.mime)}
              />,
            ])}
          />
        </SectionCard>

        <div className="space-y-4">
          <SectionCard title={t('Playback capabilities')} bodyClassName="p-0">
            <DetailList
              rows={[
                [
                  t('Media Source Extensions'),
                  <SupportCell
                    key="mse"
                    value={hasMse}
                    label={
                      hasMse
                        ? managedMse
                          ? 'ManagedMediaSource'
                          : 'MediaSource'
                        : t('No')
                    }
                  />,
                ],
                [t('Native HLS'), <SupportCell key="hls" value={nativeHls} />],
                [
                  t('Picture in picture'),
                  <SupportCell key="pip" value={pip} />,
                ],
                ...KEY_SYSTEMS.map(
                  (system) =>
                    [
                      `DRM: ${system.label}`,
                      <SupportCell
                        key={system.id}
                        value={keySystems[system.id] ?? null}
                      />,
                    ] as [string, ReactNode],
                ),
              ]}
            />
          </SectionCard>

          <SectionCard title={t('Display and network')} bodyClassName="p-0">
            <DetailList
              rows={[
                [
                  t('Screen'),
                  `${window.screen.width}×${window.screen.height} @ ${window.devicePixelRatio}x`,
                ],
                [
                  t('Rendered height'),
                  renderedHeight ? `${Math.round(renderedHeight)}px` : '-',
                ],
                [t('HDR display'), <SupportCell key="hdr" value={hdr} />],
                [
                  t('Wide color (P3)'),
                  <SupportCell key="p3" value={wideGamut} />,
                ],
                [
                  t('Connection type'),
                  connection?.effectiveType?.toUpperCase() ?? '-',
                ],
                [
                  t('Downlink estimate'),
                  connection?.downlink != null
                    ? formatBitrate(connection.downlink * 1_000_000)
                    : '-',
                ],
                [
                  t('Round trip time'),
                  connection?.rtt != null ? `${connection.rtt} ms` : '-',
                ],
                [
                  t('Data saver'),
                  <SupportCell
                    key="save"
                    value={connection?.saveData ?? null}
                  />,
                ],
              ]}
            />
          </SectionCard>
        </div>
      </div>

      <SectionCard title={t('Browser')} bodyClassName="p-0">
        <p className="break-all px-4 py-3 font-mono text-[12px] text-muted-foreground">
          {navigator.userAgent}
        </p>
      </SectionCard>
    </div>
  )
}
