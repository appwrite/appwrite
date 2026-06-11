import Markdoc, { type Config } from '@markdoc/markdoc'

export const docsMarkdocConfig: Config = {
  tags: {
    partial: { selfClosing: true },
    section: { selfClosing: true, render: 'Section' },
    multicode: { render: 'MultiCode' },
    info: { render: 'Info', attributes: { title: { type: String, required: true } } },
    tabs: { render: 'Tabs' },
    tabsitem: { render: 'TabsItem', attributes: { id: { type: String }, title: { type: String } } },
    cards: { render: 'Cards' },
    cards_item: { render: 'CardsItem', attributes: { href: { type: String }, title: { type: String } } },
    only_light: { render: 'OnlyLight' },
    only_dark: { render: 'OnlyDark' },
    accordion: { render: 'Accordion' },
    accordion_item: { render: 'AccordionItem', attributes: { title: { type: String } } },
    video: { render: 'Video', attributes: { src: { type: String }, title: { type: String } } },
    youtube: { render: 'Youtube', attributes: { id: { type: String }, title: { type: String } } },
    arrow_link: { render: 'ArrowLink', attributes: { href: { type: String }, title: { type: String } } },
    call_to_action: { render: 'CallToAction', attributes: { href: { type: String }, title: { type: String } } },
    blockquote: { render: 'Blockquote' },
    table: { render: 'MarkdocTableTag' },
  },
  nodes: {
    fence: { render: 'Fence', attributes: { content: { type: String }, language: { type: String } } },
    heading: {
      render: 'Heading',
      attributes: {
        level: { type: Number, required: true },
        id: { type: String },
      },
    },
    link: {
      render: 'Link',
      attributes: {
        href: { type: String },
        title: { type: String },
      },
    },
    image: {
      render: 'Image',
      attributes: {
        src: { type: String },
        alt: { type: String },
        title: { type: String },
      },
    },
    table: { render: 'MarkdocTableRoot' },
    thead: { render: 'MarkdocTableHeader' },
    tbody: { render: 'MarkdocTableBody' },
    tr: { render: 'MarkdocTableRow' },
    th: { render: 'MarkdocTableHead' },
    td: { render: 'MarkdocTableCell' },
  },
}

export function parseDocsMarkdoc(content: string) {
  const ast = Markdoc.parse(content)
  const transformed = Markdoc.transform(ast, docsMarkdocConfig)
  return transformed
}
