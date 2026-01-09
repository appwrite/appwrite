interface ImagineIconProps {
  className?: string
  size?: number
}

export function ImagineIcon({ className, size = 90 }: ImagineIconProps) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 300 300"
      className={className}
      style={{ display: 'block' }}
    >
      <rect width="300" height="300" fill="transparent" />
      <defs>
        <mask id="halftone-mask">
          <rect width="300" height="300" fill="white" />
          <circle cx="150" cy="150" r="70" fill="black" />
        </mask>
      </defs>
      <g mask="url(#halftone-mask)">
        <circle cx="111" cy="7" r="2.93" fill="currentColor" />
        <circle cx="137" cy="7" r="4.93" fill="currentColor" />
        <circle cx="163" cy="7" r="3.05" fill="currentColor" />
        <circle cx="189" cy="7" r="1" fill="currentColor" />
        <circle cx="59" cy="33" r="3.62" fill="currentColor" />
        <circle cx="85" cy="33" r="7.02" fill="currentColor" />
        <circle cx="111" cy="33" r="9.08" fill="currentColor" />
        <circle cx="137" cy="33" r="9.02" fill="currentColor" />
        <circle cx="163" cy="33" r="9.35" fill="currentColor" />
        <circle cx="189" cy="33" r="9.51" fill="currentColor" />
        <circle cx="215" cy="33" r="7.07" fill="currentColor" />
        <circle cx="241" cy="33" r="3.77" fill="currentColor" />
        <circle cx="33" cy="59" r="3.06" fill="currentColor" />
        <circle cx="59" cy="59" r="9.21" fill="currentColor" />
        <circle cx="85" cy="59" r="10.06" fill="currentColor" />
        <circle cx="111" cy="59" r="14" fill="currentColor" />
        <circle cx="137" cy="59" r="14" fill="currentColor" />
        <circle cx="163" cy="59" r="14" fill="currentColor" />
        <circle cx="189" cy="59" r="14" fill="currentColor" />
        <circle cx="215" cy="59" r="10.96" fill="currentColor" />
        <circle cx="241" cy="59" r="10.88" fill="currentColor" />
        <circle cx="267" cy="59" r="4.91" fill="currentColor" />
        <circle cx="33" cy="85" r="7.69" fill="currentColor" />
        <circle cx="59" cy="85" r="10.49" fill="currentColor" />
        <circle cx="85" cy="85" r="14" fill="currentColor" />
        <circle cx="111" cy="85" r="15" fill="currentColor" />
        <circle cx="137" cy="85" r="15" fill="currentColor" />
        <circle cx="163" cy="85" r="15" fill="currentColor" />
        <circle cx="189" cy="85" r="15" fill="currentColor" />
        <circle cx="215" cy="85" r="14" fill="currentColor" />
        <circle cx="241" cy="85" r="10.11" fill="currentColor" />
        <circle cx="267" cy="85" r="7.99" fill="currentColor" />
        <circle cx="7" cy="111" r="2.94" fill="currentColor" />
        <circle cx="33" cy="111" r="10.64" fill="currentColor" />
        <circle cx="59" cy="111" r="14" fill="currentColor" />
        <circle cx="85" cy="111" r="15" fill="currentColor" />
        <circle cx="111" cy="111" r="15" fill="currentColor" />
        <circle cx="137" cy="111" r="15" fill="currentColor" />
        <circle cx="163" cy="111" r="15" fill="currentColor" />
        <circle cx="189" cy="111" r="15" fill="currentColor" />
        <circle cx="215" cy="111" r="15" fill="currentColor" />
        <circle cx="241" cy="111" r="14" fill="currentColor" />
        <circle cx="267" cy="111" r="10.99" fill="currentColor" />
        <circle cx="293" cy="111" r="1.01" fill="currentColor" />
        <circle cx="7" cy="137" r="3.3" fill="currentColor" />
        <circle cx="33" cy="137" r="10.11" fill="currentColor" />
        <circle cx="59" cy="137" r="14" fill="currentColor" />
        <circle cx="85" cy="137" r="15" fill="currentColor" />
        <circle cx="111" cy="137" r="15" fill="currentColor" />
        <circle cx="137" cy="137" r="16" fill="currentColor" />
        <circle cx="163" cy="137" r="16" fill="currentColor" />
        <circle cx="189" cy="137" r="15" fill="currentColor" />
        <circle cx="215" cy="137" r="15" fill="currentColor" />
        <circle cx="241" cy="137" r="14" fill="currentColor" />
        <circle cx="267" cy="137" r="11" fill="currentColor" />
        <circle cx="293" cy="137" r="3.65" fill="currentColor" />
        <circle cx="7" cy="163" r="3.01" fill="currentColor" />
        <circle cx="33" cy="163" r="9.07" fill="currentColor" />
        <circle cx="59" cy="163" r="14" fill="currentColor" />
        <circle cx="85" cy="163" r="15" fill="currentColor" />
        <circle cx="111" cy="163" r="15" fill="currentColor" />
        <circle cx="137" cy="163" r="16" fill="currentColor" />
        <circle cx="163" cy="163" r="16" fill="currentColor" />
        <circle cx="189" cy="163" r="15" fill="currentColor" />
        <circle cx="215" cy="163" r="15" fill="currentColor" />
        <circle cx="241" cy="163" r="14" fill="currentColor" />
        <circle cx="267" cy="163" r="10.96" fill="currentColor" />
        <circle cx="293" cy="163" r="4.79" fill="currentColor" />
        <circle cx="7" cy="189" r="2.88" fill="currentColor" />
        <circle cx="33" cy="189" r="9.13" fill="currentColor" />
        <circle cx="59" cy="189" r="14" fill="currentColor" />
        <circle cx="85" cy="189" r="15" fill="currentColor" />
        <circle cx="111" cy="189" r="15" fill="currentColor" />
        <circle cx="137" cy="189" r="15" fill="currentColor" />
        <circle cx="163" cy="189" r="15" fill="currentColor" />
        <circle cx="189" cy="189" r="15" fill="currentColor" />
        <circle cx="215" cy="189" r="15" fill="currentColor" />
        <circle cx="241" cy="189" r="14" fill="currentColor" />
        <circle cx="267" cy="189" r="10.82" fill="currentColor" />
        <circle cx="293" cy="189" r="2.94" fill="currentColor" />
        <circle cx="33" cy="215" r="7.59" fill="currentColor" />
        <circle cx="59" cy="215" r="11.23" fill="currentColor" />
        <circle cx="85" cy="215" r="14" fill="currentColor" />
        <circle cx="111" cy="215" r="15" fill="currentColor" />
        <circle cx="137" cy="215" r="15" fill="currentColor" />
        <circle cx="163" cy="215" r="15" fill="currentColor" />
        <circle cx="189" cy="215" r="15" fill="currentColor" />
        <circle cx="215" cy="215" r="14" fill="currentColor" />
        <circle cx="241" cy="215" r="10.53" fill="currentColor" />
        <circle cx="267" cy="215" r="7.05" fill="currentColor" />
        <circle cx="33" cy="241" r="5" fill="currentColor" />
        <circle cx="59" cy="241" r="9.02" fill="currentColor" />
        <circle cx="85" cy="241" r="11.74" fill="currentColor" />
        <circle cx="111" cy="241" r="14" fill="currentColor" />
        <circle cx="137" cy="241" r="14" fill="currentColor" />
        <circle cx="163" cy="241" r="14" fill="currentColor" />
        <circle cx="189" cy="241" r="14" fill="currentColor" />
        <circle cx="215" cy="241" r="11.81" fill="currentColor" />
        <circle cx="241" cy="241" r="9.21" fill="currentColor" />
        <circle cx="267" cy="241" r="3.09" fill="currentColor" />
        <circle cx="59" cy="267" r="3.6" fill="currentColor" />
        <circle cx="85" cy="267" r="7.35" fill="currentColor" />
        <circle cx="111" cy="267" r="9.72" fill="currentColor" />
        <circle cx="137" cy="267" r="9.1" fill="currentColor" />
        <circle cx="163" cy="267" r="10.5" fill="currentColor" />
        <circle cx="189" cy="267" r="9.31" fill="currentColor" />
        <circle cx="215" cy="267" r="7.12" fill="currentColor" />
        <circle cx="241" cy="267" r="3.45" fill="currentColor" />
        <circle cx="111" cy="293" r="3" fill="currentColor" />
        <circle cx="137" cy="293" r="3.62" fill="currentColor" />
        <circle cx="163" cy="293" r="3.69" fill="currentColor" />
        <circle cx="189" cy="293" r="1.41" fill="currentColor" />
      </g>
    </svg>
  )
}
