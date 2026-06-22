import Markdoc, { type Config } from '@markdoc/markdoc'
import { extractMarkdocTableColumnWidthsFromAst } from './markdoc-table'

export const docsMarkdocConfig: Config = {
  tags: {
    partial: { selfClosing: true },
    section: { selfClosing: true, render: 'Section' },
    multicode: { render: 'MultiCode' },
    info: { render: 'Info', attributes: { title: { type: String, required: true } } },
    tabs: { render: 'Tabs' },
    tabsitem: { render: 'TabsItem', attributes: { id: { type: String }, title: { type: String } } },
    cards: { render: 'Cards' },
    cards_item: {
      render: 'CardsItem',
      attributes: {
        href: { type: String },
        title: { type: String },
        icon: { type: String },
        image: { type: String },
      },
    },
    only_light: { render: 'OnlyLight' },
    only_dark: { render: 'OnlyDark' },
    accordion: { render: 'Accordion' },
    accordion_item: { render: 'AccordionItem', attributes: { title: { type: String } } },
    video: { render: 'Video', attributes: { src: { type: String }, title: { type: String } } },
    youtube: {
      render: 'Youtube',
      attributes: {
        id: { type: String },
        src: { type: String },
        thumbnail: { type: String },
        title: { type: String },
      },
    },
    arrow_link: { render: 'ArrowLink', attributes: { href: { type: String }, title: { type: String } } },
    call_to_action: { render: 'CallToAction', attributes: { href: { type: String }, title: { type: String } } },
    blockquote: { render: 'Blockquote' },
    icon: {
      selfClosing: true,
      render: 'MarkdocIcon',
      attributes: { icon: { type: String }, size: { type: String } },
    },
    icon_image: {
      selfClosing: true,
      render: 'MarkdocIconImage',
      attributes: { src: { type: String }, alt: { type: String }, size: { type: String } },
    },
    prompt_content: { selfClosing: true, render: 'PromptContent' },
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
    table: {
      render: 'MarkdocTableRoot',
      transform(node, config) {
        const columnWidths = extractMarkdocTableColumnWidthsFromAst(node)
        const attributes = Markdoc.transformer.attributes(node, config)
        const children = Markdoc.transformer.children(node, config)
        return new Markdoc.Tag('MarkdocTableRoot', { ...attributes, columnWidths }, children)
      },
    },
    thead: { render: 'MarkdocTableHeader' },
    tbody: { render: 'MarkdocTableBody' },
    tr: { render: 'MarkdocTableRow' },
    th: { render: 'MarkdocTableHead', attributes: { width: { type: Number } } },
    td: { render: 'MarkdocTableCell', attributes: { width: { type: Number } } },
  },
}

export function parseDocsMarkdoc(content: string) {
  const ast = Markdoc.parse(content)
  const transformed = Markdoc.transform(ast, docsMarkdocConfig)
  return transformed
}
