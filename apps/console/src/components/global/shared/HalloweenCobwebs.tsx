import { Spider } from '@/components/global/shared/HalloweenScene'

const SPOKES = 7
const RINGS = [0.16, 0.3, 0.45, 0.61, 0.78, 0.95]

function point(radius: number, angle: number): [number, number] {
  return [radius * Math.cos(angle), radius * Math.sin(angle)]
}

/** Quarter web anchored at the top-left corner; sagging threads between spokes. */
function webPath(size: number): string {
  const angles = Array.from(
    { length: SPOKES },
    (_, index) => (index / (SPOKES - 1)) * (Math.PI / 2),
  )
  const wobble = (spoke: number, ring: number) =>
    1 + 0.07 * Math.sin(spoke * 2.3 + ring * 1.7)

  const spokes = angles.map((angle) => {
    const [x, y] = point(size, angle)
    return `M0 0L${x.toFixed(1)} ${y.toFixed(1)}`
  })

  const rings = RINGS.flatMap((ring, ringIndex) =>
    angles.slice(0, -1).map((angle, spoke) => {
      const next = angles[spoke + 1]
      const [x1, y1] = point(size * ring * wobble(spoke, ringIndex), angle)
      const [x2, y2] = point(size * ring * wobble(spoke + 1, ringIndex), next)
      const [cx, cy] = point(size * ring * 0.8, (angle + next) / 2)
      return `M${x1.toFixed(1)} ${y1.toFixed(1)}Q${cx.toFixed(1)} ${cy.toFixed(1)} ${x2.toFixed(1)} ${y2.toFixed(1)}`
    }),
  )

  return [...spokes, ...rings].join('')
}

export function Cobweb({
  size,
  className,
}: {
  size: number
  className: string
}) {
  return (
    <svg
      width={size}
      height={size}
      viewBox={`0 0 ${size} ${size}`}
      className={className}
      aria-hidden
    >
      <path
        d={webPath(size)}
        fill="none"
        stroke="currentColor"
        strokeOpacity="0.32"
        strokeWidth="0.7"
        strokeLinecap="round"
      />
    </svg>
  )
}

/** Cobweb in the end corner under the header, with a spider dropping from it; parent must be `relative`. */
export function HalloweenCobwebs() {
  return (
    <div
      className="pointer-events-none absolute inset-x-0 top-full h-0 text-foreground"
      aria-hidden
    >
      <Cobweb
        size={150}
        className="absolute end-0 top-0 -scale-x-100 rtl:scale-x-100"
      />
      <svg
        width="24"
        height="150"
        viewBox="-12 0 24 150"
        overflow="visible"
        className="absolute end-[92px] top-0"
      >
        <g className="halloween-scene-spider">
          <line
            x1="0"
            y1="-10"
            x2="0"
            y2="96"
            stroke="currentColor"
            strokeOpacity="0.35"
            strokeWidth="0.6"
          />
          <Spider x={0} y={98.5} />
        </g>
      </svg>
    </div>
  )
}
