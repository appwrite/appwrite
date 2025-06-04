import Argument from './Argument.js';
import { NodeType } from './Ast.js';
import CucumberExpressionParser from './CucumberExpressionParser.js';
import { createAlternativeMayNotBeEmpty, createAlternativeMayNotExclusivelyContainOptionals, createOptionalIsNotAllowedInOptional, createOptionalMayNotBeEmpty, createParameterIsNotAllowedInOptional, createUndefinedParameterType, } from './Errors.js';
import TreeRegexp from './TreeRegexp.js';
const ESCAPE_PATTERN = () => /([\\^[({$.|?*+})\]])/g;
export default class CucumberExpression {
    /**
     * @param expression
     * @param parameterTypeRegistry
     */
    constructor(expression, parameterTypeRegistry) {
        this.expression = expression;
        this.parameterTypeRegistry = parameterTypeRegistry;
        this.parameterTypes = [];
        const parser = new CucumberExpressionParser();
        this.ast = parser.parse(expression);
        const pattern = this.rewriteToRegex(this.ast);
        this.treeRegexp = new TreeRegexp(pattern);
    }
    rewriteToRegex(node) {
        switch (node.type) {
            case NodeType.text:
                return CucumberExpression.escapeRegex(node.text());
            case NodeType.optional:
                return this.rewriteOptional(node);
            case NodeType.alternation:
                return this.rewriteAlternation(node);
            case NodeType.alternative:
                return this.rewriteAlternative(node);
            case NodeType.parameter:
                return this.rewriteParameter(node);
            case NodeType.expression:
                return this.rewriteExpression(node);
            default:
                // Can't happen as long as the switch case is exhaustive
                throw new Error(node.type);
        }
    }
    static escapeRegex(expression) {
        return expression.replace(ESCAPE_PATTERN(), '\\$1');
    }
    rewriteOptional(node) {
        this.assertNoParameters(node, (astNode) => createParameterIsNotAllowedInOptional(astNode, this.expression));
        this.assertNoOptionals(node, (astNode) => createOptionalIsNotAllowedInOptional(astNode, this.expression));
        this.assertNotEmpty(node, (astNode) => createOptionalMayNotBeEmpty(astNode, this.expression));
        const regex = (node.nodes || []).map((node) => this.rewriteToRegex(node)).join('');
        return `(?:${regex})?`;
    }
    rewriteAlternation(node) {
        // Make sure the alternative parts aren't empty and don't contain parameter types
        for (const alternative of node.nodes || []) {
            if (!alternative.nodes || alternative.nodes.length == 0) {
                throw createAlternativeMayNotBeEmpty(alternative, this.expression);
            }
            this.assertNotEmpty(alternative, (astNode) => createAlternativeMayNotExclusivelyContainOptionals(astNode, this.expression));
        }
        const regex = (node.nodes || []).map((node) => this.rewriteToRegex(node)).join('|');
        return `(?:${regex})`;
    }
    rewriteAlternative(node) {
        return (node.nodes || []).map((lastNode) => this.rewriteToRegex(lastNode)).join('');
    }
    rewriteParameter(node) {
        const name = node.text();
        const parameterType = this.parameterTypeRegistry.lookupByTypeName(name);
        if (!parameterType) {
            throw createUndefinedParameterType(node, this.expression, name);
        }
        this.parameterTypes.push(parameterType);
        const regexps = parameterType.regexpStrings;
        if (regexps.length == 1) {
            return `(${regexps[0]})`;
        }
        return `((?:${regexps.join(')|(?:')}))`;
    }
    rewriteExpression(node) {
        const regex = (node.nodes || []).map((node) => this.rewriteToRegex(node)).join('');
        return `^${regex}$`;
    }
    assertNotEmpty(node, createNodeWasNotEmptyException) {
        const textNodes = (node.nodes || []).filter((astNode) => NodeType.text == astNode.type);
        if (textNodes.length == 0) {
            throw createNodeWasNotEmptyException(node);
        }
    }
    assertNoParameters(node, createNodeContainedAParameterError) {
        const parameterNodes = (node.nodes || []).filter((astNode) => NodeType.parameter == astNode.type);
        if (parameterNodes.length > 0) {
            throw createNodeContainedAParameterError(parameterNodes[0]);
        }
    }
    assertNoOptionals(node, createNodeContainedAnOptionalError) {
        const parameterNodes = (node.nodes || []).filter((astNode) => NodeType.optional == astNode.type);
        if (parameterNodes.length > 0) {
            throw createNodeContainedAnOptionalError(parameterNodes[0]);
        }
    }
    match(text) {
        const group = this.treeRegexp.match(text);
        if (!group) {
            return null;
        }
        return Argument.build(group, this.parameterTypes);
    }
    get regexp() {
        return this.treeRegexp.regexp;
    }
    get source() {
        return this.expression;
    }
}
//# sourceMappingURL=CucumberExpression.js.map