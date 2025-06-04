import * as rp from "regexp-tree";
import * as ast from "regexp-tree/ast";

declare module "regexp-tree/ast" {
    type AstNode = AstTypes[keyof AstTypes];

    interface AstTypes {
        "RegExp": AstRegExp;
        "Disjunction": Disjunction;
        "Alternative": Alternative;
        "Assertion": Assertion;
        "Char": Char;
        "CharacterClass": CharacterClass;
        "ClassRange": ClassRange;
        "Backreference": Backreference;
        "Group": Group;
        "Repetition": Repetition;
        "Quantifier": Quantifier;
    }

    namespace AstPath {
        interface RegExp {
            node: ast.AstRegExp;
            parentPath: null;
            parent: null;
            property: null;
            index: null;
            getParent(): null;
            setChild<T extends ast.Expression>(node: T, index: null | undefined, property: "body"): AstPath<T>;
            setChild(node: null, index: null | undefined, property: "body"): null;
            setChild<T extends ast.Expression>(node: T | null, index: null | undefined, property: "body"): AstPath<T> | null;
            getChild(n?: number): AstPath<ast.Expression> | null;
            getPreviousSibling(): null;
            getNextSibling(): null;
            update(nodeProps: Partial<ast.AstRegExp>): void;
            remove(): void;
            isRemoved(): boolean;
            hasEqualSource(path: AstPath<ast.AstNode>): boolean;
            jsonEncode(options?: { format?: string | number, useLoc?: boolean }): string;
        }
        interface Disjunction {
            node: ast.Disjunction;
            parent: ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition | null;
            parentPath: AstPath<ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition> | null;
            property: string | null;
            index: number | null;
            getParent(): AstPath<ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition> | null;
            setChild<T extends ast.Expression>(node: T, index: number, property?: "expressions"): AstPath<T>;
            setChild(node: null, index: number, property?: "expressions"): null;
            setChild<T extends ast.Expression>(node: T | null, index: number, property?: "expressions"): AstPath<T> | null;
            appendChild<T extends ast.Expression>(node: T, property?: "expressions"): AstPath<T>;
            appendChild(node: null, property?: "expressions"): null;
            appendChild<T extends ast.Expression>(node: T | null, property?: "expressions"): AstPath<T> | null;
            insertChildAt<T extends ast.Expression>(node: T | null, index: number, property?: "expressions"): void;
            getChild(n?: number): AstPath<ast.Expression> | null;
            getPreviousSibling(): AstPath<ast.Expression> | null;
            getNextSibling(): AstPath<ast.Expression> | null;
            replace<T extends ast.Expression>(node: T): AstPath<T> | null;
            update(nodeProps: Partial<ast.Disjunction>): void;
            remove(): void;
            isRemoved(): boolean;
            hasEqualSource(path: AstPath<ast.AstNode>): boolean;
            jsonEncode(options?: { format?: string | number, useLoc?: boolean }): string;
        }
        interface Alternative {
            node: ast.Alternative;
            parent: ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition | null;
            parentPath: AstPath<ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition> | null;
            property: string | null;
            index: number | null;
            getParent(): AstPath<ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition> | null;
            setChild<T extends ast.Expression>(node: T, index: number, property?: "expressions"): AstPath<T>;
            appendChild<T extends ast.Expression>(node: T, property?: "expressions"): AstPath<T>;
            getChild(n?: number): AstPath<ast.Expression>;
            getPreviousSibling(): AstPath<ast.Expression> | null;
            getNextSibling(): AstPath<ast.Expression> | null;
            replace<T extends ast.Expression>(node: T): AstPath<T> | null;
            update(nodeProps: Partial<ast.Alternative>): void;
            remove(): void;
            isRemoved(): boolean;
            hasEqualSource(path: AstPath<ast.AstNode>): boolean;
            jsonEncode(options?: { format?: string | number, useLoc?: boolean }): string;
        }
        interface Assertion {
            node: ast.Assertion;
            parent: ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition | null;
            parentPath: AstPath<ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition> | null;
            property: string | null;
            index: number | null;
            getParent(): AstPath<ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition> | null;
            setChild<T extends ast.Expression>(node: T, index: null | undefined, property: "assertion"): AstPath<T>;
            setChild(node: null, index: null | undefined, property: "assertion"): null;
            setChild<T extends ast.Expression>(node: T | null, index: null | undefined, property: "assertion"): AstPath<T> | null;
            getPreviousSibling(): AstPath<ast.Expression> | null;
            getNextSibling(): AstPath<ast.Expression> | null;
            replace<T extends ast.Expression>(node: T): AstPath<T> | null;
            update(nodeProps: Partial<ast.Assertion>): void;
            remove(): void;
            isRemoved(): boolean;
            hasEqualSource(path: AstPath<ast.AstNode>): boolean;
            jsonEncode(options?: { format?: string | number, useLoc?: boolean }): string;
        }
        interface Char {
            node: ast.Char;
            parent: ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition | ast.CharacterClass | ast.ClassRange | null;
            parentPath: AstPath<ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition | ast.CharacterClass | ast.ClassRange> | null;
            property: string | null;
            index: number | null;
            getParent(): AstPath<ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition | ast.CharacterClass | ast.ClassRange> | null;
            getPreviousSibling(): AstPath<ast.Expression | ast.ClassRange> | null;
            getNextSibling(): AstPath<ast.Expression | ast.ClassRange> | null;
            replace<T extends ast.Expression | ast.ClassRange>(node: T): AstPath<T> | null;
            update(nodeProps: Partial<ast.Char>): void;
            remove(): void;
            isRemoved(): boolean;
            hasEqualSource(path: AstPath<ast.AstNode>): boolean;
            jsonEncode(options?: { format?: string | number, useLoc?: boolean }): string;
        }
        interface CharacterClass {
            node: ast.CharacterClass;
            parent: ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition | null;
            parentPath: AstPath<ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition> | null;
            property: string | null;
            index: number | null;
            getParent(): AstPath<ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition> | null;
            setChild<T extends ast.Char | ast.ClassRange>(node: T, index: number, property?: "expressions"): AstPath<T>;
            appendChild<T extends ast.Char | ast.ClassRange>(node: T, property?: "expressions"): AstPath<T>;
            insertChildAt<T extends ast.Char | ast.ClassRange>(node: T, index: number, property?: "expressions"): void;
            getChild(n?: number): AstPath<ast.Char | ast.ClassRange>;
            getPreviousSibling(): AstPath<ast.Expression> | null;
            getNextSibling(): AstPath<ast.Expression> | null;
            replace<T extends ast.Expression>(node: T): AstPath<T> | null;
            update(nodeProps: Partial<ast.CharacterClass>): void;
            remove(): void;
            isRemoved(): boolean;
            hasEqualSource(path: AstPath<ast.AstNode>): boolean;
            jsonEncode(options?: { format?: string | number, useLoc?: boolean }): string;
        }
        interface ClassRange {
            node: ast.ClassRange;
            parent: ast.CharacterClass | null;
            parentPath: AstPath<ast.CharacterClass> | null;
            property: string | null;
            index: number | null;
            getParent(): AstPath<ast.CharacterClass> | null;
            setChild(node: ast.Char, index: null | undefined, property: "from" | "to"): AstPath<ast.Char>;
            getPreviousSibling(): AstPath<ast.Char | ast.ClassRange> | null;
            getNextSibling(): AstPath<ast.Char | ast.ClassRange> | null;
            replace<T extends ast.Char | ast.ClassRange>(node: T): AstPath<T> | null;
            update(nodeProps: Partial<ast.ClassRange>): void;
            remove(): void;
            isRemoved(): boolean;
            hasEqualSource(path: AstPath<ast.AstNode>): boolean;
            jsonEncode(options?: { format?: string | number, useLoc?: boolean }): string;
        }
        interface Backreference {
            node: ast.Backreference;
            parent: ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition | null;
            parentPath: AstPath<ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition> | null;
            property: string | null;
            index: number | null;
            getParent(): AstPath<ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition> | null;
            getPreviousSibling(): AstPath<ast.Expression> | null;
            getNextSibling(): AstPath<ast.Expression> | null;
            replace<T extends ast.Expression>(node: T): AstPath<T> | null;
            update(nodeProps: Partial<ast.Backreference>): void;
            remove(): void;
            isRemoved(): boolean;
            hasEqualSource(path: AstPath<ast.AstNode>): boolean;
            jsonEncode(options?: { format?: string | number, useLoc?: boolean }): string;
        }
        interface Group {
            node: ast.Group;
            parent: ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition | null;
            parentPath: AstPath<ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition> | null;
            property: string | null;
            index: number | null;
            getParent(): AstPath<ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition> | null;
            setChild<T extends ast.Expression>(node: T, index: null | undefined, property?: "expression"): AstPath<T>;
            setChild(node: null, index: null | undefined, property?: "expression"): null;
            setChild<T extends ast.Expression>(node: T | null, index: null | undefined, property?: "expression"): AstPath<T> | null;
            getChild(n?: 0): AstPath<ast.Expression> | null;
            getPreviousSibling(): AstPath<ast.Expression> | null;
            getNextSibling(): AstPath<ast.Expression> | null;
            replace<T extends ast.Expression>(node: T): AstPath<T> | null;
            update(nodeProps: Partial<ast.Group>): void;
            remove(): void;
            isRemoved(): boolean;
            hasEqualSource(path: AstPath<ast.AstNode>): boolean;
            jsonEncode(options?: { format?: string | number, useLoc?: boolean }): string;
        }
        interface Repetition {
            node: ast.Repetition;
            parent: ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition | null;
            parentPath: AstPath<ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition> | null;
            property: string | null;
            index: number | null;
            getParent(): AstPath<ast.AstRegExp | ast.Disjunction | ast.Alternative | ast.Assertion | ast.Group | ast.Repetition> | null;
            setChild<T extends ast.Expression>(node: T, index: null | undefined, property?: "expression"): AstPath<T>;
            setChild<T extends ast.Quantifier>(node: T, index: null | undefined, property: "quantifier"): AstPath<T>;
            getChild(n?: 0): AstPath<ast.Expression> | null;
            getPreviousSibling(): AstPath<ast.Expression> | null;
            getNextSibling(): AstPath<ast.Expression> | null;
            replace<T extends ast.Expression>(node: T): AstPath<T> | null;
            update(nodeProps: Partial<ast.Repetition>): void;
            remove(): void;
            isRemoved(): boolean;
            hasEqualSource(path: AstPath<ast.AstNode>): boolean;
            jsonEncode(options?: { format?: string | number, useLoc?: boolean }): string;
        }
        interface Quantifier {
            node: ast.Quantifier;
            parent: ast.Repetition | null;
            parentPath: AstPath<ast.Repetition> | null;
            property: string | null;
            index: number | null;
            getParent(): AstPath<ast.Repetition> | null;
            getPreviousSibling(): null;
            getNextSibling(): null;
            replace<T extends ast.Quantifier>(node: T): AstPath<T> | null;
            update(nodeProps: Partial<ast.Quantifier>): void;
            remove(): void;
            isRemoved(): boolean;
            hasEqualSource(path: AstPath<ast.AstNode>): boolean;
            jsonEncode(options?: { format?: string | number, useLoc?: boolean }): string;
        }
    }

    interface AstPathTypes {
        "RegExp": AstPath.RegExp;
        "Disjunction": AstPath.Disjunction;
        "Alternative": AstPath.Alternative;
        "Assertion": AstPath.Assertion;
        "Char": AstPath.Char;
        "CharacterClass": AstPath.CharacterClass;
        "ClassRange": AstPath.ClassRange;
        "Backreference": AstPath.Backreference;
        "Group": AstPath.Group;
        "Repetition": AstPath.Repetition;
        "Quantifier": AstPath.Quantifier;
    }

    type AstPath<T extends AstNode = AstNode> = AstPathTypes[T["type"]];
}

declare module "regexp-tree" {
    type TraversalCallback<T extends ast.AstNode = ast.AstNode, TraversalKind extends "Ast" | "AstPath" = "AstPath"> = {
        Ast: (node: T, parent: ast.AstPath<T>["parent"] | null, prop?: string, index?: number) => void | boolean;
        AstPath: (path: ast.AstPath<T>) => void | boolean;
    }[TraversalKind];

    type TraversalCallbacks<T extends ast.AstNode = ast.AstNode, TraversalKind extends "Ast" | "AstPath" = "AstPath"> = {
        pre?: TraversalCallback<T, TraversalKind>;
        post?: TraversalCallback<T, TraversalKind>;
    };

    type Traversal<T extends ast.AstNode = ast.AstNode, TraversalKind extends "Ast" | "AstPath" = "AstPath"> =
        TraversalCallback<T, TraversalKind> | TraversalCallbacks<T, TraversalKind>;

    type CommonTraversalHandlers<T extends ast.AstNode, TraversalKind extends "Ast" | "AstPath" = "AstPath"> = {
        "*"?: TraversalCallback<ast.AstNode, TraversalKind>;
        shouldRun?(ast: T): boolean;
        init?(ast: T): void;
    };

    type SpecificTraversalHandlers<TraversalKind extends "Ast" | "AstPath" = "AstPath"> = {
        [N in keyof ast.AstTypes]?: Traversal<ast.AstTypes[N], TraversalKind>;
    };

    type TraversalHandlers<T extends ast.AstNode = ast.AstNode, TraversalKind extends "Ast" | "AstPath" = "AstPath"> =
        & CommonTraversalHandlers<T, TraversalKind>
        & SpecificTraversalHandlers<TraversalKind>;

    type TransformHandlers<T extends ast.AstNode = ast.AstNode> = TraversalHandlers<T, "AstPath">;

    class TransformResult<T extends ast.AstNode, E = unknown> {
        private _ast;
        private _source;
        private _string;
        private _regexp;
        private _extra;
        constructor(ast: T, extra?: E);
        getAST(): T;
        setExtra(extra: E): void;
        getExtra(): E;
        toRegExp(): RegExp;
        getSource(): string;
        getFlags(): string;
        toString(): string;
    }

    function traverse<T extends ast.AstNode>(ast: T, handlers: TraversalHandlers<T, "Ast"> | ReadonlyArray<TraversalHandlers<T, "Ast">>, options: { TraversalKind: true }): void;
    function traverse<T extends ast.AstNode>(ast: T, handlers: TraversalHandlers<T, "AstPath"> | ReadonlyArray<TraversalHandlers<T, "AstPath">>, options?: { TraversalKind?: false }): void;
    function transform<T extends ast.AstNode>(ast: T, handlers: TransformHandlers<T> | ReadonlyArray<TransformHandlers<T>>): TransformResult<T>;
    function transform(regexp: string | RegExp, handlers: TransformHandlers<ast.AstRegExp> | ReadonlyArray<TransformHandlers<ast.AstRegExp>>): TransformResult<ast.AstRegExp>;
}