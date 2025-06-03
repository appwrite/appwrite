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
Object.defineProperty(exports, "__esModule", { value: true });
function evaluateVariableExpression(expression, env) {
    if (expression === undefined) {
        return undefined;
    }
    try {
        var re = new RegExp('\\${(.*?)(?:(?<!\\\\)/(.*)/(.*))?}', 'g');
        return expression.replace(re, function (substring) {
            var e_1, _a;
            var args = [];
            for (var _i = 1; _i < arguments.length; _i++) {
                args[_i - 1] = arguments[_i];
            }
            var variable = args[0];
            var value = getValue(env, variable);
            if (value === undefined) {
                throw new Error("Undefined variable: ".concat(variable));
            }
            var pattern = args[1];
            if (!pattern) {
                return value;
            }
            var regExp = new RegExp(pattern.replace('/', '/'));
            var match = regExp.exec(value);
            if (!match) {
                throw new Error("No match for: ".concat(variable));
            }
            var replacement = args[2];
            var ref = 1;
            try {
                for (var _b = __values(match.slice(1)), _c = _b.next(); !_c.done; _c = _b.next()) {
                    var group = _c.value;
                    replacement = replacement.replace("\\".concat(ref++), group);
                }
            }
            catch (e_1_1) { e_1 = { error: e_1_1 }; }
            finally {
                try {
                    if (_c && !_c.done && (_a = _b.return)) _a.call(_b);
                }
                finally { if (e_1) throw e_1.error; }
            }
            return replacement;
        });
    }
    catch (err) {
        // There was an undefined variable
        return undefined;
    }
}
exports.default = evaluateVariableExpression;
function getValue(env, variable) {
    var e_2, _a;
    if (variable.includes('*')) {
        var regexp = new RegExp(variable.replace('*', '.*'));
        try {
            for (var _b = __values(Object.entries(env)), _c = _b.next(); !_c.done; _c = _b.next()) {
                var _d = __read(_c.value, 2), name_1 = _d[0], value = _d[1];
                if (regexp.exec(name_1)) {
                    return value;
                }
            }
        }
        catch (e_2_1) { e_2 = { error: e_2_1 }; }
        finally {
            try {
                if (_c && !_c.done && (_a = _b.return)) _a.call(_b);
            }
            finally { if (e_2) throw e_2.error; }
        }
    }
    return env[variable];
}
//# sourceMappingURL=evaluateVariableExpression.js.map