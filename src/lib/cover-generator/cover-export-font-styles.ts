/** Minimal SVG text styles for cover export and in-browser SVG previews. */
export function buildCoverExportFontStyleBlock(fontFaceCss = ''): string {
  return `
    <style>
      ${fontFaceCss}
      .cover-title {
        font-family: 'Aeonik Pro', Arial, Helvetica, sans-serif;
        font-weight: 400;
        letter-spacing: -0.022em;
      }
      .cover-eyebrow {
        font-family: 'Inter', Arial, Helvetica, sans-serif;
        font-weight: 600;
        letter-spacing: 0.25em;
        text-transform: uppercase;
      }
      .cover-body {
        font-family: 'Inter', Arial, Helvetica, sans-serif;
        font-weight: 400;
      }
      .cover-code {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
        font-weight: 400;
        font-variant-ligatures: none;
        letter-spacing: 0;
        white-space: pre;
      }
    </style>
  `
}

/** @deprecated Export uses buildCoverExportFontStyleBlock; kept for compatibility. */
export function buildCoverFontStyleBlock(fontFaceCss = ''): string {
  return buildCoverExportFontStyleBlock(fontFaceCss)
}
