
import { Writable } from 'stream';

export = xmlbuilder;

/** 
 * Type definitions for [xmlbuilder](https://github.com/oozcitak/xmlbuilder-js)
 *
 * Original definitions on [DefinitelyTyped](https://github.com/DefinitelyTyped/DefinitelyTyped) by:
 *   - Wallymathieu <https://github.com/wallymathieu>
 *   - GaikwadPratik <https://github.com/GaikwadPratik>
 */
declare namespace xmlbuilder {
    /**
     * Creates a new XML document and returns the root element node.
     * 
     * @param nameOrObject - name of the root element or a JS object to be 
     * converted to an XML tree
     * @param xmldecOrOptions - XML declaration or create options
     * @param doctypeOrOptions - Doctype declaration or create options
     * @param options - create options
     */
    function create(nameOrObject: string | { [name: string]: Object },
        xmldecOrOptions?: CreateOptions, doctypeOrOptions?: CreateOptions,
        options?: CreateOptions): XMLElement;

    /**
     * Defines the options used while creating an XML document with the `create`
     * function.
     */
    interface CreateOptions {
        /**
         * A version number string, e.g. `1.0`
         */
        version?: string;
        /**
         * Encoding declaration, e.g. `UTF-8`
         */
        encoding?: string;
        /**
         * Standalone document declaration: `true` or `false`
         */
        standalone?: boolean;

        /**
         * Public identifier of the DTD
         */
        pubID?: string;
        /**
         * System identifier of the DTD
         */
        sysID?: string;

        /**
         * Whether XML declaration and doctype will be included
         */
        headless?: boolean;
        /**
         * Whether nodes with `null` values will be kept or ignored
         */
        keepNullNodes?: boolean;
        /**
         * Whether attributes with `null` values will be kept or ignored
         */
        keepNullAttributes?: boolean;
        /** 
         * Whether decorator strings will be ignored when converting JS 
         * objects
         */
        ignoreDecorators?: boolean;
        /** 
         * Whether array items are created as separate nodes when passed 
         * as an object value
         */
        separateArrayItems?: boolean;
        /**
         * Whether existing html entities are encoded
         */
        noDoubleEncoding?: boolean;
        /**
         * Whether values will be validated and escaped or returned as is
         */
        noValidation?: boolean;
        /**
         * A character to replace invalid characters in all values. This also
         * disables character validation.
         */
        invalidCharReplacement?: string;
        /**
         * A set of functions to use for converting values to strings
         */
        stringify?: XMLStringifier;
        /** 
         * The default XML writer to use for converting nodes to string. 
         * If the default writer is not set, the built-in `XMLStringWriter` 
         * will be used instead. 
         */
        writer?: XMLWriter;
    }

    /**
     * Defines the functions used for converting values to strings.
     */
    interface XMLStringifier {
        /**
         * Converts an element or attribute name to string
         */
        name?: (v: any) => string;
        /**
         * Converts the contents of a text node to string
         */
        text?: (v: any) => string;
        /**
         * Converts the contents of a CDATA node to string
         */
        cdata?: (v: any) => string;
        /**
         * Converts the contents of a comment node to string
         */
        comment?: (v: any) => string;
        /**
         * Converts the contents of a raw text node to string
         */
        raw?: (v: any) => string;
        /**
         * Converts attribute value to string
         */
        attValue?: (v: any) => string;
        /**
         * Converts processing instruction target to string
         */
        insTarget?: (v: any) => string;
        /**
         * Converts processing instruction value to string
         */
        insValue?: (v: any) => string;
        /**
         * Converts XML version to string
         */
        xmlVersion?: (v: any) => string;
        /**
         * Converts XML encoding to string
         */
        xmlEncoding?: (v: any) => string;
        /**
         * Converts standalone document declaration to string
         */
        xmlStandalone?: (v: any) => string;
        /**
         * Converts DocType public identifier to string
         */
        dtdPubID?: (v: any) => string;
        /**
         * Converts DocType system identifier to string
         */
        dtdSysID?: (v: any) => string;
        /**
         * Converts `!ELEMENT` node content inside Doctype to string
         */
        dtdElementValue?: (v: any) => string;
        /**
         * Converts `!ATTLIST` node type inside DocType to string
         */
        dtdAttType?: (v: any) => string;
        /**
         * Converts `!ATTLIST` node default value inside DocType to string
         */
        dtdAttDefault?: (v: any) => string;
        /**
         * Converts `!ENTITY` node content inside Doctype to string
         */
        dtdEntityValue?: (v: any) => string;
        /**
         * Converts `!NOTATION` node content inside Doctype to string
         */
        dtdNData?: (v: any) => string;

        /** 
         * When prepended to a JS object key, converts the key-value pair 
         * to an attribute. 
         */
        convertAttKey?: string;
        /** 
         * When prepended to a JS object key, converts the key-value pair 
         * to a processing instruction node. 
         */
        convertPIKey?: string;
        /** 
         * When prepended to a JS object key, converts its value to a text node. 
         * 
         * _Note:_ Since JS objects cannot contain duplicate keys, multiple text 
         * nodes can be created by adding some unique text after each object 
         * key. For example: `{ '#text1': 'some text', '#text2': 'more text' };`
         */
        convertTextKey?: string;
        /** 
         * When prepended to a JS object key, converts its value to a CDATA 
         * node. 
         */
        convertCDataKey?: string;
        /** 
         * When prepended to a JS object key, converts its value to a 
         * comment node.
         */
        convertCommentKey?: string;
        /** 
         * When prepended to a JS object key, converts its value to a raw 
         * text node. 
         */
        convertRawKey?: string;

        /**
         * Escapes special characters in text.
         */
        textEscape?: (v: string) => string;

        /**
         * Escapes special characters in attribute values.
         */
        attEscape?: (v: string) => string;
    }

    /**
     * Represents a writer which outputs an XML document.
     */
    interface XMLWriter {
        /** 
         * Writes the indentation string for the given level. 
         * 
         * @param node - current node
         * @param options - writer options and state information
         * @param level - current depth of the XML tree
         */
        indent?: (node: XMLNode, options: WriterOptions, level: number) => any

        /** 
         * Writes the newline string. 
         * 
         * @param node - current node
         * @param options - writer options and state information
         * @param level - current depth of the XML tree
         */
        endline?: (node: XMLNode, options: WriterOptions, level: number) => any

        /** 
         * Writes an attribute. 
         * 
         * @param att - current attribute
         * @param options - writer options and state information
         * @param level - current depth of the XML tree
         */
        attribute?: (att: XMLAttribute, options: WriterOptions, 
            level: number) => any

        /** 
         * Writes a CDATA node.
         * 
         * @param node - current node
         * @param options - writer options and state information
         * @param level - current depth of the XML tree
         */
        cdata?: (node: XMLCData, options: WriterOptions, level: number) => any

        /** 
         * Writes a comment node. 
         * 
         * @param node - current node
         * @param options - writer options and state information
         * @param level - current depth of the XML tree
         */
        comment?: (node: XMLComment, options: WriterOptions, 
            level: number) => any

        /** 
         * Writes the XML declaration (e.g. `<?xml version="1.0"?>`). 
         * 
         * @param node - XML declaration node
         * @param options - writer options and state information
         * @param level - current depth of the XML tree
         */
        declaration?: (node: XMLDeclaration, options: WriterOptions, 
            level: number) => any

        /** 
         * Writes the DocType node and its children. 
         * 
         * _Note:_ Be careful when overriding this function as this function
         * is also responsible for writing the internal subset of the DTD. 
         * 
         * @param node - DOCTYPE node
         * @param options - writer options and state information
         * @param level - current depth of the XML tree
         */
        docType?: (node: XMLDocType, options: WriterOptions, 
            level: number) => any

        /** 
         * Writes an element node. 
         * 
         * _Note:_ Be careful when overriding this function as this function
         * is also responsible for writing the element attributes and child 
         * nodes.
         * 
         * 
         * @param node - current node
         * @param options - writer options and state information
         * @param level - current depth of the XML tree
         */
        element?: (node: XMLElement, options: WriterOptions, 
            level: number) => any

        /** 
         * Writes a processing instruction node. 
         * 
         * @param node - current node
         * @param options - writer options and state information
         * @param level - current depth of the XML tree
         */
        processingInstruction?: (node: XMLProcessingInstruction, 
            options: WriterOptions, level: number) => any

        /** 
         * Writes a raw text node. 
         * 
         * @param node - current node
         * @param options - writer options and state information
         * @param level - current depth of the XML tree
         */
        raw?: (node: XMLRaw, options: WriterOptions, level: number) => any

        /** 
         * Writes a text node. 
         * 
         * @param node - current node
         * @param options - writer options and state information
         * @param level - current depth of the XML tree
         */
        text?: (node: XMLText, options: WriterOptions, level: number) => any

        /** 
         * Writes an attribute node (`!ATTLIST`) inside the DTD. 
         * 
         * @param node - current node
         * @param options - writer options and state information
         * @param level - current depth of the XML tree
         */
        dtdAttList?: (node: XMLDTDAttList, options: WriterOptions, 
            level: number) => any

        /** 
         * Writes an element node (`!ELEMENT`) inside the DTD. 
         * 
         * @param node - current node
         * @param options - writer options and state information
         * @param level - current depth of the XML tree
         */
        dtdElement?: (node: XMLDTDElement, options: WriterOptions, 
            level: number) => any

        /** 
         * Writes an entity node (`!ENTITY`) inside the DTD. 
         * 
         * @param node - current node
         * @param options - writer options and state information
         * @param level - current depth of the XML tree
         */
        dtdEntity?: (node: XMLDTDEntity, options: WriterOptions, 
            level: number) => any

        /** 
         * Writes a notation node (`!NOTATION`) inside the DTD. 
         * 
         * @param node - current node
         * @param options - writer options and state information
         * @param level - current depth of the XML tree
         */
        dtdNotation?: (node: XMLDTDNotation, options: WriterOptions, 
            level: number) => any

        /** 
         * Called right after starting writing a node. This function does not 
         * produce any output, but can be used to alter the state of the writer. 
         * 
         * @param node - current node
         * @param options - writer options and state information
         * @param level - current depth of the XML tree
         */
        openNode?: (node: XMLNode, options: WriterOptions, 
            level: number) => void

        /** 
         * Called right before completing writing a node. This function does not 
         * produce any output, but can be used to alter the state of the writer.
         * 
         * @param node - current node
         * @param options - writer options and state information
         * @param level - current depth of the XML tree
         */
        closeNode?: (node: XMLNode, options: WriterOptions, 
            level: number) => void

        /** 
         * Called right after starting writing an attribute. This function does 
         * not produce any output, but can be used to alter the state of the 
         * writer. 
         * 
         * @param node - current attribute
         * @param options - writer options and state information
         * @param level - current depth of the XML tree
         */
        openAttribute?: (att: XMLAttribute, options: WriterOptions, 
            level: number) => void

        /** 
         * Called right before completing writing an attribute. This function 
         * does not produce any output, but can be used to alter the state of 
         * the writer. 
         * 
         * @param node - current attribute
         * @param options - writer options and state information
         * @param level - current depth of the XML tree
         */
        closeAttribute?: (att: XMLAttribute, options: WriterOptions, 
            level: number) => void
    }

    /**
     * Defines the options passed to the XML writer.
     */
    interface WriterOptions {
        /**
         * Pretty print the XML tree
         */
        pretty?: boolean;
        /**
         * Indentation string for pretty printing
         */
        indent?: string;
        /**
         * Newline string for pretty printing
         */
        newline?: string;
        /**
         * A fixed number of indents to offset strings
         */
        offset?: number;
        /**
         * Maximum column width
         */
        width?: number;
        /**
         * Whether to output closing tags for empty element nodes
         */
        allowEmpty?: boolean;
        /**
         * Whether to pretty print text nodes
         */
        dontPrettyTextNodes?: boolean;
        /**
         * A string to insert before closing slash character
         */
        spaceBeforeSlash?: string | boolean;
        /**
         * User state object that is saved between writer functions
         */
        user?: any;
        /**
         * The current state of the writer
         */
        state?: WriterState;
        /**
         * Writer function overrides
         */
        writer?: XMLWriter;
    }

    /**
     * Defines the state of the writer.
     */
    enum WriterState {
        /**
         * Writer state is unknown
         */
        None = 0,
        /**
         * Writer is at an opening tag, e.g. `<node>`
         */
        OpenTag = 1,
        /**
         * Writer is inside an element
         */
        InsideTag = 2,
        /**
         * Writer is at a closing tag, e.g. `</node>`
         */
        CloseTag = 3
    }

    /**
     * Creates a new XML document and returns the document node.
     * This function creates an empty document without the XML prolog or
     * a root element.
     * 
     * @param options - create options
     */
    function begin(options?: BeginOptions): XMLDocument;

    /**
     * Defines the options used while creating an XML document with the `begin`
     * function.
     */
    interface BeginOptions {
        /**
         * Whether nodes with null values will be kept or ignored
         */
        keepNullNodes?: boolean;
        /**
         * Whether attributes with null values will be kept or ignored
         */
        keepNullAttributes?: boolean;
        /** 
         * Whether decorator strings will be ignored when converting JS 
         * objects
         */
        ignoreDecorators?: boolean;
        /** 
         * Whether array items are created as separate nodes when passed 
         * as an object value
         */
        separateArrayItems?: boolean;
        /**
         * Whether existing html entities are encoded
         */
        noDoubleEncoding?: boolean;
        /**
         * Whether values will be validated and escaped or returned as is
         */
        noValidation?: boolean;
        /**
         * A character to replace invalid characters in all values. This also
         * disables character validation.
         */
        invalidCharReplacement?: string;        
        /**
         * A set of functions to use for converting values to strings
         */
        stringify?: XMLStringifier;
        /** 
         * The default XML writer to use for converting nodes to string. 
         * If the default writer is not set, the built-in XMLStringWriter 
         * will be used instead. 
         */
        writer?: XMLWriter | WriterOptions;
    }

    /**
     * A function to be called when a chunk of XML is written.
     * 
     * @param chunk - a chunk of string that was written
     * @param level - current depth of the XML tree
     */
    type OnDataCallback = (chunk: string, level: number) => void;

    /**
     * A function to be called when the XML doucment is completed.
     */
    type OnEndCallback = () => void;

    /**
     * Creates a new XML document in callback mode and returns the document
     * node.
     * 
     * @param options - create options
     * @param onData - the function to be called when a new chunk of XML is
     * output. The string containing the XML chunk is passed to `onData` as
     * its first argument and the current depth of the tree is passed as its
     * second argument.
     * @param onEnd - the function to be called when the XML document is 
     * completed with `end`. `onEnd` does not receive any arguments.
     */
    function begin(options?: BeginOptions | OnDataCallback,
        onData?: OnDataCallback | OnEndCallback,
        onEnd?: OnEndCallback): XMLDocumentCB;

    /**
     * Creates and returns a default string writer.
     * 
     * @param options - writer options
     */
    function stringWriter(options?: WriterOptions): XMLWriter

    /**
     * Creates and returns a default stream writer.
     * 
     * @param stream - a writeable stream
     * @param options - writer options
     */
    function streamWriter(stream: Writable, options?: WriterOptions): XMLWriter

    /**
     * Defines the type of a node in the XML document.
     */
    enum NodeType {
        /**
         * An element node
         */
        Element = 1,
        /**
         * An attribute node
         */
        Attribute = 2,
        /**
         * A text node
         */
        Text = 3,
        /**
         * A CDATA node
         */
        CData = 4,
        /**
         * An entity reference node inside DocType
         */
        EntityReference = 5,
        /**
         * An entity declaration node inside DocType
         */
        EntityDeclaration = 6,
        /**
         * A processing instruction node
         */
        ProcessingInstruction = 7,
        /**
         * A comment node
         */
        Comment = 8,
        /**
         * A document node
         */
        Document = 9,
        /**
         * A Doctype node
         */
        DocType = 10,
        /**
         * A document fragment node
         */
        DocumentFragment = 11,
        /**
         * A notation declaration node inside DocType
         */
        NotationDeclaration = 12,
        /**
         * An XML declaration node
         */
        Declaration = 201,
        /**
         * A raw text node
         */
        Raw = 202,
        /**
         * An attribute declaraiton node inside DocType
         */
        AttributeDeclaration = 203,
        /**
         * An element declaration node inside DocType
         */
        ElementDeclaration = 204
    }

    /**
     * Defines the type of a node in the XML document.
     */
    export import nodeType = NodeType;

    /**
     * Defines the state of the writer.
     */
    export import writerState = WriterState;

    /**
     * Defines the settings used when converting the XML document to string.
     */
    interface XMLToStringOptions {
        /**
         * Pretty print the XML tree
         */
        pretty?: boolean;
        /**
         * Indentation string for pretty printing
         */
        indent?: string;
        /**
         * Newline string for pretty printing
         */
        newline?: string;
        /**
         * A fixed number of indents to offset strings
         */
        offset?: number;
        /**
         * Maximum column width
         */
        width?: number;
        /**
         * Whether to output closing tags for empty element nodes
         */
        allowEmpty?: boolean;
        /**
         * Whether to pretty print text nodes
         */
        dontPrettyTextNodes?: boolean;
        /**
         * A string to insert before closing slash character
         */
        spaceBeforeSlash?: string | boolean;
        /** 
         * The default XML writer to use for converting nodes to string. 
         * If the default writer is not set, the built-in `XMLStringWriter` 
         * will be used instead. 
         */
        writer?: XMLWriter;
    }

    /**
     * Represents the XML document.
     */
    class XMLDocument extends XMLNode {
        /** 
         * Converts the node to string 
         * 
         * @param options - conversion options
         */
        toString(options?: XMLToStringOptions): string;
    }

    /**
     * Represents an XML attribute.
     */
    class XMLAttribute {
        /** 
         * Type of the node 
         */
        type: NodeType;
        /** 
         * Parent element node 
         */
        parent: XMLElement;
        /** 
         * Attribute name 
         */
        name: string;
        /** 
         * Attribute value 
         */
        value: string;

        /** 
         * Creates a clone of this node 
         */
        clone(): XMLAttribute;

        /** 
         * Converts the node to string 
         * 
         * @param options - conversion options
         */
        toString(options?: XMLToStringOptions): string;
    }

    /**
     * Represents the base class of XML nodes.
     */
    abstract class XMLNode {
        /** 
         * Type of the node 
         */
        type: NodeType;
        /** 
         * Parent element node 
         */
        parent: XMLElement;
        /** 
         * Child nodes 
         */
        children: XMLNode[]

        /**
         * Creates a new child node and appends it to the list of child nodes.
         * 
         * _Aliases:_ `ele` and `e`
         * 
         * @param name - node name or a JS object defining the nodes to insert
         * @param attributes - node attributes
         * @param text - node text
         * 
         * @returns the last top level node created
         */
        element(name: any, attributes?: Object, text?: any): XMLElement;
        ele(name: any, attributes?: Object, text?: any): XMLElement;
        e(name: any, attributes?: Object, text?: any): XMLElement;

        /**
         * Adds or modifies an attribute.
         * 
         * _Aliases:_ `att`, `a`
         * 
         * @param name - attribute name
         * @param value - attribute value
         * 
         * @returns the parent element node
         */
        attribute(name: any, value?: any): XMLElement;
        att(name: any, value?: any): XMLElement;
        a(name: any, value?: any): XMLElement;

        /**
         * Creates a new sibling node and inserts it before this node.
         * 
         * @param name - node name or a JS object defining the nodes to insert
         * @param attributes - node attributes
         * @param text - node text
         * 
         * @returns the new node
         */
        insertBefore(name: any, attributes?: Object, text?: any): XMLElement;
        /**
         * Creates a new sibling node and inserts it after this node.
         * 
         * @param name - node name or a JS object defining the nodes to insert
         * @param attributes - node attributes
         * @param text - node text
         * 
         * @returns the new node
         */
        insertAfter(name: any, attributes?: Object, text?: any): XMLElement;
        /**
         * Removes this node from the tree.
         * 
         * @returns the parent node
         */
        remove(): XMLElement;

        /**
         * Creates a new element node and appends it to the list of child nodes.
         * 
         * _Aliases:_ `nod` and `n`
         * 
         * @param name - element node name
         * @param attributes - node attributes
         * @param text - node text
         * 
         * @returns the node created
         */
        node(name: string, attributes?: Object, text?: any): XMLElement;
        nod(name: string, attributes?: Object, text?: any): XMLElement;
        n(name: string, attributes?: Object, text?: any): XMLElement;

        /**
         * Creates a new text node and appends it to the list of child nodes.
         * 
         * _Aliases:_ `txt` and `t`
         * 
         * @param value - node value
         * 
         * @returns the parent node
         */
        text(value: string): XMLElement;
        txt(value: string): XMLElement;
        t(value: string): XMLElement;

        /**
         * Creates a new CDATA node and appends it to the list of child nodes.
         * 
         * _Aliases:_ `dat` and `d`
         * 
         * @param value - node value
         * 
         * @returns the parent node
         */
        cdata(value: string): XMLElement;
        dat(value: string): XMLElement;
        d(value: string): XMLElement;

        /**
         * Creates a new comment node and appends it to the list of child nodes.
         * 
         * _Aliases:_ `com` and `c`
         * 
         * @param value - node value
         * 
         * @returns the parent node
         */
        comment(value: string): XMLElement;
        com(value: string): XMLElement;
        c(value: string): XMLElement;

        /**
         * Creates a comment node before the current node
         * 
         * @param value - node value
         * 
         * @returns the parent node
         */
        commentBefore(value: string): XMLElement;

        /**
         * Creates a comment node after the current node
         * 
         * @param value - node value
         * 
         * @returns the parent node
         */
        commentAfter(value: string): XMLElement;

        /**
         * Creates a new raw text node and appends it to the list of child
         * nodes.
         * 
         * _Alias:_ `r`
         * 
         * @param value - node value
         * 
         * @returns the parent node
         */
        raw(value: string): XMLElement;
        r(value: string): XMLElement;

        /**
         * Creates a new processing instruction node and appends it to the list
         * of child nodes.
         * 
         * _Aliases:_ `ins` and `i`
         * 
         * @param target - node target
         * @param value - node value
         * 
         * @returns the parent node
         */
        instruction(target: string, value: any): XMLElement;
        instruction(array: Array<any>): XMLElement;
        instruction(obj: Object): XMLElement;
        ins(target: string, value: any): XMLElement;
        ins(array: Array<any>): XMLElement;
        ins(obj: Object): XMLElement;
        i(target: string, value: any): XMLElement;
        i(array: Array<any>): XMLElement;
        i(obj: Object): XMLElement;

        /**
         * Creates a processing instruction node before the current node.
         * 
         * @param target - node target
         * @param value - node value
         * 
         * @returns the parent node
         */
        instructionBefore(target: string, value: any): XMLElement;

        /**
         * Creates a processing instruction node after the current node.
         * 
         * @param target - node target
         * @param value - node value
         * 
         * @returns the parent node
         */
        instructionAfter(target: string, value: any): XMLElement;

        /**
         * Creates the XML declaration.
         * 
         * _Alias:_ `dec`
         * 
         * @param version - version number string, e.g. `1.0`
         * @param encoding - encoding declaration, e.g. `UTF-8`
         * @param standalone - standalone document declaration: `true` or `false`
         * 
         * @returns the root element node
         */
        declaration(version?: string | 
            { version?: string, encoding?: string, standalone?: boolean }, 
            encoding?: string, standalone?: boolean): XMLElement;
        dec(version?: string | 
            { version?: string, encoding?: string, standalone?: boolean }, 
            encoding?: string, standalone?: boolean): XMLElement;

        /**
         * Creates the document type definition.
         * 
         * _Alias:_ `dtd`
         * 
         * @param pubID - public identifier of the DTD
         * @param sysID - system identifier of the DTD
         * 
         * @returns the DOCTYPE node
         */
        doctype(pubID?: string | { pubID?: string, sysID?: string }, 
            sysID?: string): XMLDocType;
        dtd(pubID?: string | { pubID?: string, sysID?: string }, 
            sysID?: string): XMLDocType;

        /**
         * Takes the root node of the given XML document and appends it 
         * to child nodes.
         * 
         * @param doc - the document whose root node to import
         * 
         * @returns the current node
         */
        importDocument(doc: XMLNode): XMLElement;

        /**
         * Converts the XML document to string.
         * 
         * @param options - conversion options
         */
        end(options?: XMLWriter | XMLToStringOptions): string;

        /**
         * Returns the previous sibling node.
         */
        prev(): XMLNode;
        /**
         * Returns the next sibling node.
         */
        next(): XMLNode;
        /**
         * Returns the parent node.
         * 
         * _Alias:_ `u`
         */
        up(): XMLElement;
        u(): XMLElement;
        /**
         * Returns the document node.
         * 
         * _Alias:_ `doc`
         */
        document(): XMLDocument;
        doc(): XMLDocument;

        /**
         * Returns the root element node.
         */
        root(): XMLElement;
    }

    /**
     * Represents the base class of character data nodes.
     */
    abstract class XMLCharacterData extends XMLNode {
        /**
         * Node value
         */
        value: string;
    }

    /**
     * Represents a CDATA node.
     */
    class XMLCData extends XMLCharacterData {
        /** 
         * Converts the node to string 
         * 
         * @param options - conversion options
         */
        toString(options?: XMLToStringOptions): string;

        /**
         * Creates a clone of this node
         */
        clone(): XMLCData;
    }

    /**
     * Represents a comment node.
     */
    class XMLComment extends XMLCharacterData {
        /** 
         * Converts the node to string 
         * 
         * @param options - conversion options
         */
        toString(options?: XMLToStringOptions): string;

        /**
         * Creates a clone of this node
         */
        clone(): XMLComment;
    }

    /**
     * Represents a processing instruction node.
     */
    class XMLProcessingInstruction extends XMLCharacterData {
        /**  Instruction target
         */
        target: string;

        /** 
         * Converts the node to string 
         * 
         * @param options - conversion options
         */
        toString(options?: XMLToStringOptions): string;

        /**
         * Creates a clone of this node
         */
        clone(): XMLProcessingInstruction;
    }

    /**
     * Represents a raw text node.
     */
    class XMLRaw extends XMLCharacterData {
        /** 
         * Converts the node to string 
         * 
         * @param options - conversion options
         */
        toString(options?: XMLToStringOptions): string;

        /**
         * Creates a clone of this node
         */
        clone(): XMLRaw;
    }

    /**
     * Represents a text node.
     */
    class XMLText extends XMLCharacterData {
        /** 
         * Converts the node to string 
         * 
         * @param options - conversion options
         */
        toString(options?: XMLToStringOptions): string;

        /**
         * Creates a clone of this node
         */
        clone(): XMLText;
    }

    /**
     * Represents the XML declaration.
     */
    class XMLDeclaration {
        /**
         * A version number string, e.g. `1.0`
         */
        version: string;
        /**
         * Encoding declaration, e.g. `UTF-8`
         */
        encoding: string;
        /**
         * Standalone document declaration: `true` or `false`
         */
        standalone: boolean;

        /** 
         * Converts the node to string.
         * 
         * @param options - conversion options
         */
        toString(options?: XMLToStringOptions): string;
    }

    /**
     * Represents the document type definition.
     */
    class XMLDocType {
        /** 
         * Type of the node 
         */
        type: NodeType;
        /** 
         * Parent element node 
         */
        parent: XMLElement;
        /** 
         * Child nodes 
         */
        children: XMLNode[]

        /** 
         * Public identifier of the DTD 
         */
        pubID: string;
        /** 
         * System identifier of the DTD 
         */
        sysID: string;

        /**
         * Creates an element type declaration.
         * 
         * _Alias:_ `ele`
         * 
         * @param name - element name
         * @param value - element content (defaults to `#PCDATA`)
         * 
         * @returns the DOCTYPE node
         */
        element(name: string, value?: Object): XMLDocType;
        ele(name: string, value?: Object): XMLDocType;

        /**
         * Creates an attribute declaration.
         * 
         * _Alias:_ `att`
         * 
         * @param elementName - the name of the element containing this attribute
         * @param attributeName - attribute name
         * @param attributeType - type of the attribute
         * @param defaultValueType - default value type (either `#REQUIRED`,
         * `#IMPLIED`, `#FIXED` or `#DEFAULT`)
         * @param defaultValue - default value of the attribute (only used
         * for `#FIXED` or `#DEFAULT`)
         * 
         * @returns the DOCTYPE node
         */
        attList(elementName: string, attributeName: string, attributeType: string, 
            defaultValueType: string, defaultValue?: any): XMLDocType;
        att(elementName: string, attributeName: string, attributeType: string, 
            defaultValueType: string, defaultValue?: any): XMLDocType;

        /**
         * Creates a general entity declaration.
         * 
         * _Alias:_ `ent`
         * 
         * @param name - the name of the entity
         * @param value - entity parameters
         * 
         * @returns the DOCTYPE node
         */
        entity(name: string, value: string | 
            { pubID?: string, sysID?: string, nData?: string }): XMLDocType;
        ent(name: string, value: string | 
            { pubID?: string, sysID?: string, nData?: string }): XMLDocType;

        /**
         * Creates a parameter entity declaration.
         * 
         * _Alias:_ `pent`
         * 
         * @param name - the name of the entity
         * @param value - entity parameters
         * 
         * @returns the DOCTYPE node
         */
        pEntity(name: string, value: string | 
            { pubID?: string, sysID?: string }): XMLDocType;
        pent(name: string, value: string | 
            { pubID?: string, sysID?: string }): XMLDocType;

        /**
         * Creates a notation declaration.
         * 
         * _Alias:_ `not`
         * 
         * @param name - the name of the entity
         * @param value - entity parameters
         * 
         * @returns the DOCTYPE node
         */
        notation(name: string, 
            value: { pubID?: string, sysID?: string }): XMLDocType;
        not(name: string, 
            value: { pubID?: string, sysID?: string }): XMLDocType;

        /**
         * Creates a new CDATA node and appends it to the list of child nodes.
         * 
         * _Alias:_ `dat`
         * 
         * @param value - node value
         * 
         * @returns the DOCTYPE node
         */
        cdata(value: string): XMLDocType;
        dat(value: string): XMLDocType;

        /**
         * Creates a new comment child and appends it to the list of child
         * nodes.
         * 
         * _Alias:_ `com`
         * 
         * @param value - node value
         * 
         * @returns the DOCTYPE node
         */
        comment(value: string): XMLDocType;
        com(value: string): XMLDocType;

        /**
         * Creates a new processing instruction node and appends it to the list 
         * of child nodes.
         * 
         * _Alias:_ `ins`
         * 
         * @param target - node target
         * @param value - node value
         * 
         * @returns the DOCTYPE node
         */
        instruction(target: string, value: any): XMLDocType;
        instruction(array: Array<any>): XMLDocType;
        instruction(obj: Object): XMLDocType;
        ins(target: string, value: any): XMLDocType;
        ins(array: Array<any>): XMLDocType;
        ins(obj: Object): XMLDocType;

        /**
         * Returns the root element node.
         * 
         * _Alias:_ `up`
         */
        root(): XMLElement;
        up(): XMLElement;

        /** 
         * Converts the node to string.
         * 
         * @param options - conversion options
         */
        toString(options?: XMLToStringOptions): string;

        /** 
         * Creates a clone of this node.
         */
        clone(): XMLDocType;

        /**
         * Returns the document node.
         * 
         * _Alias:_ `doc`
         */
        document(): XMLDocument;
        doc(): XMLDocument;

        /**
         * Converts the XML document to string.
         * 
         * @param options - conversion options
         */
        end(options?: XMLWriter | XMLToStringOptions): string;
    }

    /**
     * Represents an attribute list in the DTD.
     */
    class XMLDTDAttList {
        /**
         * The name of the element containing this attribute
         */
        elementName: string;
        /**
         * Attribute name
         */
        attributeName: string;
        /**
         * Type of the attribute
         */
        attributeType: string;
        /** 
         * Default value type (either `#REQUIRED`, `#IMPLIED`, `#FIXED` 
         * or `#DEFAULT`)
         */
        defaultValueType: string;
        /** 
         * Default value of the attribute (only used for `#FIXED` or 
         * `#DEFAULT`)
         */
        defaultValue: string;

        /** 
         * Converts the node to string.
         * 
         * @param options - conversion options
         */
        toString(options?: XMLToStringOptions): string;
    }

    /**
     * Represents an element in the DTD.
     */
    class XMLDTDElement {
        /**
         * The name of the element
         */
        name: string;
        /**
         * Element content
         */
        value: string;

        /** 
         * Converts the node to string.
         * 
         * @param options - conversion options
         */
        toString(options?: XMLToStringOptions): string;
    }

    /**
     * Represents an entity in the DTD.
     */
    class XMLDTDEntity {
        /** 
         * Determines whether this is a parameter entity (`true`) or a 
         * general entity (`false`).
         */
        pe: boolean;
        /**
         * The name of the entity
         */
        name: string;
        /**
         * Public identifier
         */
        pubID: string;
        /**
         * System identifier
         */
        sysID: string;
        /**
         * Notation declaration
         */
        nData: string;

        /** 
         * Converts the node to string.
         * 
         * @param options - conversion options
         */
        toString(options?: XMLToStringOptions): string;
    }

    /**
     * Represents a notation in the DTD.
     */
    class XMLDTDNotation {
        /**
         * The name of the notation
         */
        name: string;
        /**
         * Public identifier
         */
        pubID: string;
        /**
         * System identifier
         */
        sysID: string;

        /** 
         * Converts the node to string.
         * 
         * @param options - conversion options
         */
        toString(options?: XMLToStringOptions): string;
    }

    /**
     * Represents an element node.
     */
    class XMLElement extends XMLNode {
        /**
         * Element node name
         */
        name: string;
        /**
         * Element attributes
         */
        attribs: { string: XMLAttribute };

        /** 
         * Creates a clone of this node 
         */
        clone(): XMLElement;

        /**
         * Adds or modifies an attribute.
         * 
         * _Aliases:_ `att`, `a`
         * 
         * @param name - attribute name
         * @param value - attribute value
         * 
         * @returns the parent element node
         */
        attribute(name: any, value?: any): XMLElement;
        att(name: any, value?: any): XMLElement;
        a(name: any, value?: any): XMLElement;

        /**
         * Removes an attribute.
         * 
         * @param name - attribute name
         * 
         * @returns the parent element node
         */
        removeAttribute(name: string | string[]): XMLElement;

        /** 
         * Converts the node to string.
         * 
         * @param options - conversion options
         */
        toString(options?: XMLToStringOptions): string;
    }

    /**
     * Represents an XML document builder used in callback mode with the
     * `begin` function.
     */
    class XMLDocumentCB {

        /**
         * Creates a new child node and appends it to the list of child nodes.
         * 
         * _Aliases:_ `nod` and `n`
         * 
         * @param name - element node name
         * @param attributes - node attributes
         * @param text - node text
         * 
         * @returns the document builder object
         */
        node(name: string, attributes?: Object, text?: any): XMLDocumentCB;
        nod(name: string, attributes?: Object, text?: any): XMLDocumentCB;
        n(name: string, attributes?: Object, text?: any): XMLDocumentCB;

        /**
         * Creates a child element node.
         * 
         * _Aliases:_ `ele` and `e`
         * 
         * @param name - element node name or a JS object defining the nodes 
         * to insert
         * @param attributes - node attributes
         * @param text - node text
         * 
         * @returns the document builder object
         */
        element(name: any, attributes?: Object, text?: any): XMLDocumentCB;
        ele(name: any, attributes?: Object, text?: any): XMLDocumentCB;
        e(name: any, attributes?: Object, text?: any): XMLDocumentCB;

        /**
         * Adds or modifies an attribute.
         * 
         * _Aliases:_ `att` and `a`
         * 
         * @param name - attribute name
         * @param value - attribute value
         * 
         * @returns the document builder object
         */
        attribute(name: any, value?: any): XMLDocumentCB;
        att(name: any, value?: any): XMLDocumentCB;
        a(name: any, value?: any): XMLDocumentCB;

        /**
         * Creates a new text node and appends it to the list of child nodes.
         * 
         * _Aliases:_ `txt` and `t`
         * 
         * @param value - node value
         * 
         * @returns the document builder object
         */
        text(value: string): XMLDocumentCB;
        txt(value: string): XMLDocumentCB;
        t(value: string): XMLDocumentCB;

        /**
         * Creates a new CDATA node and appends it to the list of child nodes.
         * 
         * _Aliases:_ `dat` and `d`
         * 
         * @param value - node value
         * 
         * @returns the document builder object
         */
        cdata(value: string): XMLDocumentCB;
        dat(value: string): XMLDocumentCB;
        d(value: string): XMLDocumentCB;

        /**
         * Creates a new comment node and appends it to the list of child nodes.
         * 
         * _Aliases:_ `com` and `c`
         * 
         * @param value - node value
         * 
         * @returns the document builder object
         */
        comment(value: string): XMLDocumentCB;
        com(value: string): XMLDocumentCB;
        c(value: string): XMLDocumentCB;

        /**
         * Creates a new raw text node and appends it to the list of child 
         * nodes.
         * 
         * _Alias:_ `r`
         * 
         * @param value - node value
         * 
         * @returns the document builder object
         */
        raw(value: string): XMLDocumentCB;
        r(value: string): XMLDocumentCB;

        /**
         * Creates a new processing instruction node and appends it to the list 
         * of child nodes.
         * 
         * _Aliases:_ `ins` and `i`
         * 
         * @param target - node target
         * @param value - node value
         * 
         * @returns the document builder object
         */
        instruction(target: string, value: any): XMLDocumentCB;
        instruction(array: Array<any>): XMLDocumentCB;
        instruction(obj: Object): XMLDocumentCB;
        ins(target: string, value: any): XMLDocumentCB;
        ins(array: Array<any>): XMLDocumentCB;
        ins(obj: Object): XMLDocumentCB;
        i(target: string, value: any): XMLDocumentCB;
        i(array: Array<any>): XMLDocumentCB;
        i(obj: Object): XMLDocumentCB;

        /**
         * Creates the XML declaration.
         * 
         * _Alias:_ `dec`
         * 
         * @param version - version number string, e.g. `1.0`
         * @param encoding - encoding declaration, e.g. `UTF-8`
         * @param standalone - standalone document declaration: `true` or `false`
         * 
         * @returns the document builder object
         */
        declaration(version?: string, encoding?: string, 
            standalone?: boolean): XMLDocumentCB;
        dec(version?: string, encoding?: string, 
            standalone?: boolean): XMLDocumentCB;

        /**
         * Creates the document type definition.
         * 
         * _Alias:_ `dtd`
         * 
         * @param root - the name of the root node
         * @param pubID - public identifier of the DTD
         * @param sysID - system identifier of the DTD
         * 
         * @returns the document builder object
         */
        doctype(root: string, pubID?: string, sysID?: string): XMLDocumentCB;
        dtd(root: string, pubID?: string, sysID?: string): XMLDocumentCB;

        /**
         * Creates an element type declaration.
         * 
         * _Aliases:_ `element` and `ele`
         * 
         * @param name - element name
         * @param value - element content (defaults to `#PCDATA`)
         * 
         * @returns the document builder object
         */
        dtdElement(name: string, value?: Object): XMLDocumentCB;
        element(name: string, value?: Object): XMLDocumentCB;
        ele(name: string, value?: Object): XMLDocumentCB;

        /**
         * Creates an attribute declaration.
         * 
         * _Alias:_ `att`
         * 
         * @param elementName - the name of the element containing this attribute
         * @param attributeName - attribute name
         * @param attributeType - type of the attribute (defaults to `CDATA`)
         * @param defaultValueType - default value type (either `#REQUIRED`,
         * `#IMPLIED`, `#FIXED` or `#DEFAULT`) (defaults to `#IMPLIED`)
         * @param defaultValue - default value of the attribute (only used
         * for `#FIXED` or `#DEFAULT`)
         * 
         * @returns the document builder object
         */
        attList(elementName: string, attributeName: string, 
            attributeType: string, defaultValueType?: 
            string, defaultValue?: any): XMLDocumentCB;
        att(elementName: string, attributeName: string, attributeType: string, 
            defaultValueType?: string, defaultValue?: any): XMLDocumentCB;
        a(elementName: string, attributeName: string, attributeType: string, 
            defaultValueType?: string, defaultValue?: any): XMLDocumentCB;

        /**
         * Creates a general entity declaration.
         * 
         * _Alias:_ `ent`
         * 
         * @param name - the name of the entity
         * @param value - entity parameters
         * 
         * @returns the document builder object
         */
        entity(name: string, value: string | 
            { pubID?: string, sysID?: string, nData?: string }): XMLDocumentCB;
        ent(name: string, value: string | 
            { pubID?: string, sysID?: string, nData?: string }): XMLDocumentCB;

        /**
         * Creates a parameter entity declaration.
         * 
         * _Alias:_ `pent`
         * 
         * @param name - the name of the entity
         * @param value - entity parameters
         * 
         * @returns the document builder object
         */
        pEntity(name: string, value: string | 
            { pubID?: string, sysID?: string }): XMLDocumentCB;
        pent(name: string, value: string | 
            { pubID?: string, sysID?: string }): XMLDocumentCB;

        /**
         * Creates a notation declaration.
         * 
         * _Alias:_ `not`
         * 
         * @param name - the name of the entity
         * @param value - entity parameters
         * 
         * @returns the document builder object
         */
        notation(name: string, 
            value: { pubID?: string, sysID?: string }): XMLDocumentCB;
        not(name: string, 
            value: { pubID?: string, sysID?: string }): XMLDocumentCB;

        /**
         * Ends the document and calls the `onEnd` callback function.
         */
        end(): void;

        /**
         * Moves up to the parent node.
         * 
         * _Alias:_ `u`
         * 
         * @returns the document builder object
         */
        up(): XMLDocumentCB;
        u(): XMLDocumentCB;
    }

}
