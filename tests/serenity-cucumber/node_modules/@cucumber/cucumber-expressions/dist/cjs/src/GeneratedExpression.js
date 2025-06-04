"use strict";
var __read = (this && this.__read) || function (o, n) {
    var m = typeof Symbol === "function" && o[Symbol.iterator];
    if (!m) return o;
    var i = m.call(o), r, ar = [], e;
    try {
        while ((n === void 0 || n-- > 0) && !(r = i.next()).done) ar.push(r.value);
    }
    catch (error) { e = { error: error }; }
    finally {
        try {
            if (r && !r.done && (m = i["return"])) m.call(i);
        }
        finally { if (e) throw e.error; }
    }
    return ar;
};
var __spreadArray = (this && this.__spreadArray) || function (to, from, pack) {
    if (pack || arguments.length === 2) for (var i = 0, l = from.length, ar; i < l; i++) {
        if (ar || !(i in from)) {
            if (!ar) ar = Array.prototype.slice.call(from, 0, i);
            ar[i] = from[i];
        }
    }
    return to.concat(ar || Array.prototype.slice.call(from));
};
Object.defineProperty(exports, "__esModule", { value: true });
var GeneratedExpression = /** @class */ (function () {
    function GeneratedExpression(expressionTemplate, parameterTypes) {
        this.expressionTemplate = expressionTemplate;
        this.parameterTypes = parameterTypes;
    }
    Object.defineProperty(GeneratedExpression.prototype, "source", {
        get: function () {
            return format.apply(void 0, __spreadArray([this.expressionTemplate], __read(this.parameterTypes.map(function (t) { return t.name || ''; })), false));
        },
        enumerable: false,
        configurable: true
    });
    Object.defineProperty(GeneratedExpression.prototype, "parameterNames", {
        /**
         * Returns an array of parameter names to use in generated function/method signatures
         *
         * @returns {ReadonlyArray.<String>}
         */
        get: function () {
            return this.parameterInfos.map(function (i) { return "".concat(i.name).concat(i.count === 1 ? '' : i.count.toString()); });
        },
        enumerable: false,
        configurable: true
    });
    Object.defineProperty(GeneratedExpression.prototype, "parameterInfos", {
        /**
         * Returns an array of ParameterInfo to use in generated function/method signatures
         */
        get: function () {
            var usageByTypeName = {};
            return this.parameterTypes.map(function (t) { return getParameterInfo(t, usageByTypeName); });
        },
        enumerable: false,
        configurable: true
    });
    return GeneratedExpression;
}());
exports.default = GeneratedExpression;
function getParameterInfo(parameterType, usageByName) {
    var name = parameterType.name || '';
    var counter = usageByName[name];
    counter = counter ? counter + 1 : 1;
    usageByName[name] = counter;
    var type;
    if (parameterType.type) {
        if (typeof parameterType.type === 'string') {
            type = parameterType.type;
        }
        else if ('name' in parameterType.type) {
            type = parameterType.type.name;
        }
        else {
            type = null;
        }
    }
    else {
        type = null;
    }
    return {
        type: type,
        name: name,
        count: counter,
    };
}
function format(pattern) {
    var args = [];
    for (var _i = 1; _i < arguments.length; _i++) {
        args[_i - 1] = arguments[_i];
    }
    return pattern.replace(/{(\d+)}/g, function (match, number) { return args[number]; });
}
//# sourceMappingURL=GeneratedExpression.js.map