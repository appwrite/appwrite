import { isHanukkahBranchLit } from '@/lib/hanukkah'

const BRANCH_XS = [-24, -18, -12, -6, 6, 12, 18, 24] as const

function Flame({ delay, large = false }: { delay: string; large?: boolean }) {
  const height = large ? 2.8 : 2.2
  return (
    <g className="hanukkah-flicker" style={{ animationDelay: delay }}>
      <ellipse
        cy={-height * 0.75}
        rx={large ? 1.4 : 1.15}
        ry={height}
        fill="#FDE68A"
      />
      <ellipse
        cy={-height * 1.15}
        rx={large ? 0.7 : 0.55}
        ry={height * 0.55}
        fill="#FDBA74"
      />
    </g>
  )
}

function Branch({
  x,
  lit,
  shamash = false,
  delay,
}: {
  x: number
  lit: boolean
  shamash?: boolean
  delay: string
}) {
  const stem = shamash ? 16 : 10
  return (
    <g transform={`translate(${x} 0)`}>
      <rect
        x={shamash ? -2 : -1.7}
        y="-1.6"
        width={shamash ? 4 : 3.4}
        height="1.8"
        rx="0.6"
        fill="#C4930A"
      />
      {lit ? (
        <>
          <rect
            x={shamash ? -1.35 : -1.15}
            y={-stem}
            width={shamash ? 2.7 : 2.3}
            height={stem}
            rx="0.8"
            fill="#FEF3C7"
          />
          <g transform={`translate(0 ${-stem})`}>
            <Flame delay={delay} large={shamash} />
          </g>
        </>
      ) : null}
    </g>
  )
}

export function Hanukkiah({
  night,
  className,
}: {
  night: number
  className?: string
}) {
  const shamashLit = night > 0

  return (
    <svg viewBox="0 0 80 64" className={className} aria-hidden>
      <g transform="translate(40 50)">
        {shamashLit ? (
          <ellipse
            className="hanukkah-glow"
            cy="-22"
            rx="28"
            ry="16"
            fill="#FBBF24"
            fillOpacity="0.16"
          />
        ) : null}
        <path
          d="M-26 0h52v3c0 1.6-1.3 3-3 3h-46c-1.7 0-3-1.4-3-3Z"
          fill="#D4A017"
        />
        <rect x="-2.6" y="-10" width="5.2" height="10" rx="1" fill="#C4930A" />
        <rect x="-28" y="-13" width="56" height="2.4" rx="1.2" fill="#E8C547" />
        <g transform="translate(0 -13)">
          {BRANCH_XS.map((x, index) => (
            <Branch
              key={x}
              x={x}
              lit={isHanukkahBranchLit(index, night)}
              delay={`${(index % 5) * -0.35}s`}
            />
          ))}
          <Branch x={0} lit={shamashLit} shamash delay="-0.1s" />
        </g>
      </g>
    </svg>
  )
}
