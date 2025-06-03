"use strict";
var __values = (this && this.__values) || function(o) {
    var s = typeof Symbol === "function" && Symbol.iterator, m = s && o[s], i = 0;
    if (m) return m.call(o);
    if (o && typeof o.length === "number") return {
        next: function () {
            if (o && i >= o.length) o = void 0;
            return { value: o && o[i++], done: !o };
        }
    };
    throw new TypeError(s ? "Object is not iterable." : "Symbol.iterator is not defined.");
};
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
var Argument_js_1 = __importDefault(require("./Argument.js"));
var Ast_js_1 = require("./Ast.js");
var CucumberExpressionParser_js_1 = __importDefault(require("./CucumberExpressionParser.js"));
var Errors_js_1 = require("./Errors.js");
var TreeRegexp_js_1 = __importDefault(require("./TreeRegexp.js"));
var ESCAPE_PATTERN = function () { return /([\\^[({$.|?*+})\]])/g; };
var CucumberExpression = /** @class */ (function () {
    /**
     * @param expression
     * @param parameterTypeRegistry
     */
    function CucumberExpression(expression, parameterTypeRegistry) {
        this.expression = expression;
        this.parameterTypeRegistry = parameterTypeRegistry;
        this.parameterTypes = [];
        var parser = new CucumberExpressionParser_js_1.default();
        this.ast = parser.parse(expression);
        var pattern = this.rewriteToRegex(this.ast);
        this.treeRegexp = new TreeRegexp_js_1.default(pattern);
    }
    CucumberExpression.prototype.rewriteToRegex = function (node) {
        switch (node.type) {
            case Ast_js_1.NodeType.text:
                return CucumberExpression.escapeRegex(node.text());
            case Ast_js_1.NodeType.optional:
                return this.rewriteOptional(node);
            case Ast_js_1.NodeType.alternation:
                return this.rewriteAlternation(node);
            case Ast_js_1.NodeType.alternative:
                return this.rewriteAlternative(node);
            case Ast_js_1.NodeType.parameter:
                return this.rewriteParameter(node);
            case Ast_js_1.NodeType.expression:
                return this.rewriteExpression(node);
            default:
                // Can't happen as long as the switch case is exhaustive
                throw new Error(node.type);
        }
    };
    CucumberExpression.escapeRegex = function (expression) {
        return expression.replace(ESCAPE_PATTERN(), '\\$1');
    };
    CucumberExpression.prototype.rewriteOptional = function (node) {
        var _this = this;
        this.assertNoParameters(node, function (astNode) {
            return (0, Errors_js_1.createParameterIsNotAllowedInOptional)(astNode, _this.expression);
        });
        this.assertNoOptionals(node, function (astNode) {
            return (0, Errors_js_1.createOptionalIsNotAllowedInOptional)(astNode, _this.expression);
        });
        this.assertNotEmpty(node, function (astNode) { return (0, Errors_js_1.createOptionalMayNotBeEmpty)(astNode, _this.expression); });
        var regex = (node.nodes || []).map(function (node) { return _this.rewriteToRegex(node); }).join('');
        return "(?:".concat(regex, ")?");
    };
    CucumberExpression.prototype.rewriteAlternation = function (node) {
        var e_1, _a;
        var _this = this;
        try {
            // Make sure the alternative parts aren't empty and don't contain parameter types
            for (var _b = __values(node.nodes || []), _c = _b.next(); !_c.done; _c = _b.next()) {
                var alternative = _c.value;
                if (!alternative.nodes || alternative.nodes.length == 0) {
                    throw (0, Errors_js_1.createAlternativeMayNotBeEmpty)(alternative, this.expression);
                }
                this.assertNotEmpty(alternative, function (astNode) {
                    return (0, Errors_js_1.createAlternativeMayNotExclusivelyContainOptionals)(astNode, _this.expression);
                });
            }
        }
        catch (e_1_1) { e_1 = { error: e_1_1 }; }
        finally {
            try {
                if (_c && !_c.done && (_a = _b.return)) _a.call(_b);
            }
            finally { if (e_1) throw e_1.error; }
        }
        var regex = (node.nodes || []).map(function (node) { return _this.rewriteToRegex(node); }).join('|');
        return "(?:".concat(regex, ")");
    };
    CucumberExpression.prototype.rewriteAlternative = function (node) {
        var _this = this;
        return (node.nodes || []).map(function (lastNode) { return _this.rewriteToRegex(lastNode); }).join('');
    };
    CucumberExpression.prototype.rewriteParameter = function (node) {
        var name = node.text();
        var parameterType = this.parameterTypeRegistry.lookupByTypeName(name);
        if (!parameterType) {
            throw (0, Errors_js_1.createUndefinedParameterType)(node, this.expression, name);
        }
        this.parameterTypes.push(parameterType);
        var regexps = parameterType.regexpStrings;
        if (regexps.length == 1) {
            return "(".concat(regexps[0], ")");
        }
        return "((?:".concat(regexps.join(')|(?:'), "))");
    };
    CucumberExpression.prototype.rewriteExpression = function (node) {
        var _this = this;
        var regex = (node.nodes || []).map(function (node) { return _this.rewriteToRegex(node); }).join('');
        return "^".concat(regex, "$");
    };
    CucumberExpression.prototype.assertNotEmpty = function (node, createNodeWasNotEmptyException) {
        var textNodes = (node.nodes || []).filter(function (astNode) { return Ast_js_1.NodeType.text == astNode.type; });
        if (textNodes.length == 0) {
            throw createNodeWasNotEmptyException(node);
        }
    };
    CucumberExpression.prototype.assertNoParameters = function (node, createNodeContainedAParameterError) {
        var parameterNodes = (node.nodes || []).filter(function (astNode) { return Ast_js_1.NodeType.parameter == astNode.type; });
        if (parameterNodes.length > 0) {
            throw createNodeContainedAParameterError(parameterNodes[0]);
        }
    };
    CucumberExpression.prototype.assertNoOptionals = function (node, createNodeContainedAnOptionalError) {
        var parameterNodes = (node.nodes || []).filter(function (astNode) { return Ast_js_1.NodeType.optional == astNode.type; });
        if (parameterNodes.length > 0) {
            throw createNodeContainedAnOptionalError(parameterNodes[0]);
        }
    };
    CucumberExpression.prototype.match = function (text) {
        var group = this.treeRegexp.match(text);
        if (!group) {
            return null;
        }
        return Argument_js_1.default.build(group, this.parameterTypes);
    };
    Object.defineProperty(CucumberExpression.prototype, "regexp", {
        get: function () {
            return this.treeRegexp.regexp;
        },
        enumerable: false,
        configurable: true
    });
    Object.defineProperty(CucumberExpression.prototype, "source", {
        get: function () {
            return this.expression;
        },
        enumerable: false,
        configurable: true
    });
    return CucumberExpression;
}());
exports.default = CucumberExpression;
//# sourceMappingURL=CucumberExpression.js.map