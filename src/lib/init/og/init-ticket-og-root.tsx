import {
  INIT_TICKET_IMAGE_HEIGHT,
  INIT_TICKET_IMAGE_WIDTH,
  initTicketOgContentBox,
  initTicketOgStubAnchorBox,
} from '@/lib/init/ticket-layout'
import { getInitTicketOgUiPalette } from '@/lib/init/og/init-ticket-og-background'
import { InitTicketOgWordmark } from '@/lib/init/og/init-ticket-wordmark'
import {
  getInitTicketOgPalette,
  INIT_TICKET_OG_LAYOUT,
  initTicketOgEmTracking,
  initTicketOgHolderNameSize,
  initTicketOgNameTracking,
  initTicketOgStubTitleSize,
  initTicketOgTextStyle,
  resolveInitTicketOgAccentColor,
  truncateInitTicketOgText,
} from '@/lib/init/og/init-ticket-og-styles'
import type { PreparedInitTicketOgData } from '@/lib/init/og/prepare-init-ticket-og-data'
import type { InitTicketRenderData } from '@/lib/init/ticket-render-data'

type InitTicketOgSharedProps = {
  data: InitTicketRenderData
  prepared: PreparedInitTicketOgData
}

function TicketStubOg({
  ticketNumber,
  holderName,
  holderTitle,
  passLabel,
  dateRangeLabel,
  accentColor,
  palette,
  stubW,
  contentH,
}: {
  ticketNumber: string
  holderName: string
  holderTitle: string
  passLabel: string
  dateRangeLabel: string
  accentColor: string
  palette: ReturnType<typeof getInitTicketOgPalette>
  stubW: number
  contentH: number
}) {
  const { left, right, bottom } = initTicketOgStubAnchorBox(stubW, contentH)
  const layout = INIT_TICKET_OG_LAYOUT

  return (
    <div
      style={{
        position: 'relative',
        display: 'flex',
        height: contentH,
        width: stubW,
      }}
    >
      <div
        style={{
          position: 'absolute',
          display: 'flex',
          alignItems: 'flex-end',
          left,
          right,
          bottom,
        }}
      >
        <div
          style={{
            display: 'flex',
            flexDirection: 'column',
            alignItems: 'flex-start',
            gap: layout.stubGap,
            transform: 'rotate(-90deg)',
            transformOrigin: 'bottom left',
          }}
        >
          <InitTicketOgWordmark
            height={layout.stubWordmarkHeight}
            textColor={palette.text}
            accentColor={accentColor}
          />
          <div
            style={initTicketOgTextStyle(layout.stubTicketSize, accentColor, {
              fontWeight: 600,
              letterSpacing: 0.5,
            })}
          >
            {ticketNumber}
          </div>
          <div
            style={initTicketOgTextStyle(layout.stubNameSize, palette.label, {
              fontWeight: 400,
            })}
          >
            {holderName}
          </div>
          <div
            style={initTicketOgTextStyle(
              initTicketOgStubTitleSize(holderTitle),
              palette.muted,
              { fontWeight: 500, lineHeight: 1 },
            )}
          >
            {holderTitle}
          </div>
          <div
            style={initTicketOgTextStyle(layout.stubPassSize, palette.muted, {
              fontWeight: 600,
              textTransform: 'uppercase',
              letterSpacing: initTicketOgEmTracking(layout.stubPassSize, 0.22),
            })}
          >
            {passLabel}
          </div>
          <div
            style={initTicketOgTextStyle(layout.stubPassSize, palette.muted, {
              fontWeight: 600,
              textTransform: 'uppercase',
              letterSpacing: initTicketOgEmTracking(layout.stubPassSize, 0.14),
            })}
          >
            {dateRangeLabel}
          </div>
        </div>
      </div>
    </div>
  )
}

export function InitTicketOgBackgroundRoot({ data, prepared }: InitTicketOgSharedProps) {
  const usesDarkChrome = data.ticketAppearance.usesDarkChrome

  return (
    <div
      style={{
        position: 'relative',
        display: 'flex',
        width: INIT_TICKET_IMAGE_WIDTH,
        height: INIT_TICKET_IMAGE_HEIGHT,
        overflow: 'hidden',
        backgroundColor: getInitTicketOgUiPalette(usesDarkChrome).background,
      }}
    >
      <img
        src={prepared.uiBackgroundSrc}
        alt=""
        width={INIT_TICKET_IMAGE_WIDTH}
        height={INIT_TICKET_IMAGE_HEIGHT}
        style={{
          position: 'absolute',
          top: 0,
          left: 0,
          width: INIT_TICKET_IMAGE_WIDTH,
          height: INIT_TICKET_IMAGE_HEIGHT,
        }}
      />
    </div>
  )
}

export function InitTicketOgTicketLayer({ data, prepared }: InitTicketOgSharedProps) {
  const usesDarkChrome = data.ticketAppearance.usesDarkChrome
  const palette = getInitTicketOgPalette(usesDarkChrome)
  const accentColor = resolveInitTicketOgAccentColor(
    data.ticketAppearance.accentColor,
  )
  const { contentX, contentY, contentH, mainW, stubW } = initTicketOgContentBox()
  const layout = INIT_TICKET_OG_LAYOUT
  const holderTitle =
    data.prefs.holderTitle?.trim() || data.ticketAppearance.holderTitle
  const holderName = truncateInitTicketOgText(data.holderName, 34)
  const holderNameSize = initTicketOgHolderNameSize(data.holderName)

  return (
    <div
      style={{
        position: 'relative',
        display: 'flex',
        width: INIT_TICKET_IMAGE_WIDTH,
        height: INIT_TICKET_IMAGE_HEIGHT,
        backgroundColor: 'rgba(0, 0, 0, 0)',
      }}
    >
      {prepared.backgroundSrc ? (
        <img
          src={prepared.backgroundSrc}
          alt=""
          width={INIT_TICKET_IMAGE_WIDTH}
          height={INIT_TICKET_IMAGE_HEIGHT}
          style={{
            position: 'absolute',
            top: 0,
            left: 0,
            width: INIT_TICKET_IMAGE_WIDTH,
            height: INIT_TICKET_IMAGE_HEIGHT,
          }}
        />
      ) : null}

      <div
        style={{
          position: 'absolute',
          display: 'flex',
          top: contentY,
          left: contentX,
          width: mainW + stubW,
          height: contentH,
          color: palette.text,
        }}
      >
        <div
          style={{
            display: 'flex',
            flexDirection: 'column',
            justifyContent: 'space-between',
            width: mainW,
            height: contentH,
            paddingRight: Math.round(mainW * 0.08),
          }}
        >
          <div
            style={{
              display: 'flex',
              flexDirection: 'column',
              gap: layout.headerGap,
            }}
          >
            <InitTicketOgWordmark
              height={layout.wordmarkHeight}
              textColor={palette.text}
              accentColor={accentColor}
            />
            <div
              style={initTicketOgTextStyle(layout.dateSize, palette.date, {
                fontWeight: 600,
                textTransform: 'uppercase',
                letterSpacing: initTicketOgEmTracking(layout.dateSize, 0.16),
              })}
            >
              {data.dateRangeLabel}
            </div>
          </div>

          <div
            style={{
              display: 'flex',
              flexDirection: 'column',
              gap: layout.bodyGap,
            }}
          >
            <div style={{ display: 'flex', gap: layout.stackGap }}>
              {prepared.stackIcons.map((icon, index) =>
                icon.src ? (
                  <img
                    key={`${icon.label}-${index}`}
                    src={icon.src}
                    alt=""
                    width={layout.stackIconSize}
                    height={layout.stackIconSize}
                    style={{
                      width: layout.stackIconSize,
                      height: layout.stackIconSize,
                      objectFit: 'contain',
                    }}
                  />
                ) : null,
              )}
            </div>

            <div
              style={{
                display: 'flex',
                flexDirection: 'column',
                gap: layout.detailGap,
                borderTop: `1px dashed ${palette.separator}`,
                paddingTop: layout.detailPaddingTop,
              }}
            >
              <div
                style={initTicketOgTextStyle(holderNameSize, palette.text, {
                  fontWeight: 400,
                  letterSpacing: initTicketOgNameTracking(holderNameSize),
                  lineHeight: 1.02,
                })}
              >
                {holderName}
              </div>
              <div
                style={initTicketOgTextStyle(layout.titleSize, palette.label, {
                  fontWeight: 500,
                  lineHeight: 1.625,
                })}
              >
                {truncateInitTicketOgText(holderTitle, 40)}
              </div>
              {data.githubUsername ? (
                <div
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: layout.githubGap,
                  }}
                >
                  {prepared.githubIconSrc ? (
                    <img
                      src={prepared.githubIconSrc}
                      alt=""
                      width={layout.githubIconSize}
                      height={layout.githubIconSize}
                      style={{
                        width: layout.githubIconSize,
                        height: layout.githubIconSize,
                        objectFit: 'contain',
                        opacity: usesDarkChrome ? 0.75 : 1,
                      }}
                    />
                  ) : null}
                  <div
                    style={initTicketOgTextStyle(layout.githubSize, palette.github, {
                      fontWeight: 500,
                    })}
                  >
                    {`@${truncateInitTicketOgText(data.githubUsername, 24)}`}
                  </div>
                </div>
              ) : null}
              <div
                style={initTicketOgTextStyle(layout.passSize, palette.muted, {
                  fontWeight: 600,
                  textTransform: 'uppercase',
                  letterSpacing: initTicketOgEmTracking(layout.passSize, 0.2),
                })}
              >
                {data.ticketAppearance.passLabel}
              </div>
              <div
                style={initTicketOgTextStyle(layout.ticketSize, accentColor, {
                  fontWeight: 500,
                  letterSpacing: 0.5,
                })}
              >
                {data.ticketNumber}
              </div>
            </div>
          </div>
        </div>

        <TicketStubOg
          ticketNumber={data.ticketNumber}
          holderName={holderName}
          holderTitle={holderTitle}
          passLabel={data.ticketAppearance.passLabel}
          dateRangeLabel={data.dateRangeLabel}
          accentColor={accentColor}
          palette={palette}
          stubW={stubW}
          contentH={contentH}
        />
      </div>
    </div>
  )
}

