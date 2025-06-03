"use strict";
var __generator = (this && this.__generator) || function (thisArg, body) {
    var _ = { label: 0, sent: function() { if (t[0] & 1) throw t[1]; return t[1]; }, trys: [], ops: [] }, f, y, t, g;
    return g = { next: verb(0), "throw": verb(1), "return": verb(2) }, typeof Symbol === "function" && (g[Symbol.iterator] = function() { return this; }), g;
    function verb(n) { return function (v) { return step([n, v]); }; }
    function step(op) {
        if (f) throw new TypeError("Generator is already executing.");
        while (_) try {
            if (f = 1, y && (t = op[0] & 2 ? y["return"] : op[0] ? y["throw"] || ((t = y["return"]) && t.call(y), 0) : y.next) && !(t = t.call(y, op[1])).done) return t;
            if (y = 0, t) op = [op[0] & 2, t.value];
            switch (op[0]) {
                case 0: case 1: t = op; break;
                case 4: _.label++; return { value: op[1], done: false };
                case 5: _.label++; y = op[1]; op = [0]; continue;
                case 7: op = _.ops.pop(); _.trys.pop(); continue;
                default:
                    if (!(t = _.trys, t = t.length > 0 && t[t.length - 1]) && (op[0] === 6 || op[0] === 2)) { _ = 0; continue; }
                    if (op[0] === 3 && (!t || (op[1] > t[0] && op[1] < t[3]))) { _.label = op[1]; break; }
                    if (op[0] === 6 && _.label < t[1]) { _.label = t[1]; t = op; break; }
                    if (t && _.label < t[2]) { _.label = t[2]; _.ops.push(op); break; }
                    if (t[2]) _.ops.pop();
                    _.trys.pop(); continue;
            }
            op = body.call(thisArg, _);
        } catch (e) { op = [6, e]; y = 0; } finally { f = t = 0; }
        if (op[0] & 5) throw op[1]; return { value: op[0] ? op[1] : void 0, done: true };
    }
};
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
exports.Multimap = void 0;
var Multimap = /** @class */ (function () {
    function Multimap(operator, iterable) {
        var e_1, _a;
        this.size_ = 0;
        this.map = new Map();
        this.operator = operator;
        if (iterable) {
            try {
                for (var iterable_1 = __values(iterable), iterable_1_1 = iterable_1.next(); !iterable_1_1.done; iterable_1_1 = iterable_1.next()) {
                    var _b = __read(iterable_1_1.value, 2), key = _b[0], value = _b[1];
                    this.put(key, value);
                }
            }
            catch (e_1_1) { e_1 = { error: e_1_1 }; }
            finally {
                try {
                    if (iterable_1_1 && !iterable_1_1.done && (_a = iterable_1.return)) _a.call(iterable_1);
                }
                finally { if (e_1) throw e_1.error; }
            }
        }
        return this;
    }
    Object.defineProperty(Multimap.prototype, "size", {
        get: function () {
            return this.size_;
        },
        enumerable: false,
        configurable: true
    });
    Multimap.prototype.get = function (key) {
        var values = this.map.get(key);
        if (values) {
            return this.operator.clone(values);
        }
        else {
            return this.operator.create();
        }
    };
    Multimap.prototype.put = function (key, value) {
        var values = this.map.get(key);
        if (!values) {
            values = this.operator.create();
        }
        if (!this.operator.add(value, values)) {
            return false;
        }
        this.map.set(key, values);
        this.size_++;
        return true;
    };
    Multimap.prototype.putAll = function (arg1, arg2) {
        var e_2, _a, e_3, _b;
        var pushed = 0;
        if (arg2) {
            var key = arg1;
            var values = arg2;
            try {
                for (var values_1 = __values(values), values_1_1 = values_1.next(); !values_1_1.done; values_1_1 = values_1.next()) {
                    var value = values_1_1.value;
                    this.put(key, value);
                    pushed++;
                }
            }
            catch (e_2_1) { e_2 = { error: e_2_1 }; }
            finally {
                try {
                    if (values_1_1 && !values_1_1.done && (_a = values_1.return)) _a.call(values_1);
                }
                finally { if (e_2) throw e_2.error; }
            }
        }
        else if (arg1 instanceof Multimap) {
            try {
                for (var _c = __values(arg1.entries()), _d = _c.next(); !_d.done; _d = _c.next()) {
                    var _e = __read(_d.value, 2), key = _e[0], value = _e[1];
                    this.put(key, value);
                    pushed++;
                }
            }
            catch (e_3_1) { e_3 = { error: e_3_1 }; }
            finally {
                try {
                    if (_d && !_d.done && (_b = _c.return)) _b.call(_c);
                }
                finally { if (e_3) throw e_3.error; }
            }
        }
        else {
            throw new TypeError("unexpected arguments");
        }
        return pushed > 0;
    };
    Multimap.prototype.has = function (key) {
        return this.map.has(key);
    };
    Multimap.prototype.hasEntry = function (key, value) {
        return this.operator.has(value, this.get(key));
    };
    Multimap.prototype.delete = function (key) {
        this.size_ -= this.operator.size(this.get(key));
        return this.map.delete(key);
    };
    Multimap.prototype.deleteEntry = function (key, value) {
        var current = this.get(key);
        if (!this.operator.delete(value, current)) {
            return false;
        }
        this.map.set(key, current);
        this.size_--;
        return true;
    };
    Multimap.prototype.clear = function () {
        this.map.clear();
        this.size_ = 0;
    };
    Multimap.prototype.keys = function () {
        return this.map.keys();
    };
    Multimap.prototype.entries = function () {
        var self = this;
        function gen() {
            var _a, _b, _c, key, values, values_2, values_2_1, value, e_4_1, e_5_1;
            var e_5, _d, e_4, _e;
            return __generator(this, function (_f) {
                switch (_f.label) {
                    case 0:
                        _f.trys.push([0, 11, 12, 13]);
                        _a = __values(self.map.entries()), _b = _a.next();
                        _f.label = 1;
                    case 1:
                        if (!!_b.done) return [3 /*break*/, 10];
                        _c = __read(_b.value, 2), key = _c[0], values = _c[1];
                        _f.label = 2;
                    case 2:
                        _f.trys.push([2, 7, 8, 9]);
                        values_2 = (e_4 = void 0, __values(values)), values_2_1 = values_2.next();
                        _f.label = 3;
                    case 3:
                        if (!!values_2_1.done) return [3 /*break*/, 6];
                        value = values_2_1.value;
                        return [4 /*yield*/, [key, value]];
                    case 4:
                        _f.sent();
                        _f.label = 5;
                    case 5:
                        values_2_1 = values_2.next();
                        return [3 /*break*/, 3];
                    case 6: return [3 /*break*/, 9];
                    case 7:
                        e_4_1 = _f.sent();
                        e_4 = { error: e_4_1 };
                        return [3 /*break*/, 9];
                    case 8:
                        try {
                            if (values_2_1 && !values_2_1.done && (_e = values_2.return)) _e.call(values_2);
                        }
                        finally { if (e_4) throw e_4.error; }
                        return [7 /*endfinally*/];
                    case 9:
                        _b = _a.next();
                        return [3 /*break*/, 1];
                    case 10: return [3 /*break*/, 13];
                    case 11:
                        e_5_1 = _f.sent();
                        e_5 = { error: e_5_1 };
                        return [3 /*break*/, 13];
                    case 12:
                        try {
                            if (_b && !_b.done && (_d = _a.return)) _d.call(_a);
                        }
                        finally { if (e_5) throw e_5.error; }
                        return [7 /*endfinally*/];
                    case 13: return [2 /*return*/];
                }
            });
        }
        return gen();
    };
    Multimap.prototype.values = function () {
        var self = this;
        function gen() {
            var _a, _b, _c, value, e_6_1;
            var e_6, _d;
            return __generator(this, function (_e) {
                switch (_e.label) {
                    case 0:
                        _e.trys.push([0, 5, 6, 7]);
                        _a = __values(self.entries()), _b = _a.next();
                        _e.label = 1;
                    case 1:
                        if (!!_b.done) return [3 /*break*/, 4];
                        _c = __read(_b.value, 2), value = _c[1];
                        return [4 /*yield*/, value];
                    case 2:
                        _e.sent();
                        _e.label = 3;
                    case 3:
                        _b = _a.next();
                        return [3 /*break*/, 1];
                    case 4: return [3 /*break*/, 7];
                    case 5:
                        e_6_1 = _e.sent();
                        e_6 = { error: e_6_1 };
                        return [3 /*break*/, 7];
                    case 6:
                        try {
                            if (_b && !_b.done && (_d = _a.return)) _d.call(_a);
                        }
                        finally { if (e_6) throw e_6.error; }
                        return [7 /*endfinally*/];
                    case 7: return [2 /*return*/];
                }
            });
        }
        return gen();
    };
    Multimap.prototype.forEach = function (callback, thisArg) {
        var e_7, _a;
        try {
            for (var _b = __values(this.entries()), _c = _b.next(); !_c.done; _c = _b.next()) {
                var _d = __read(_c.value, 2), key = _d[0], value = _d[1];
                callback.call(thisArg === undefined ? this : thisArg, value, key, this);
            }
        }
        catch (e_7_1) { e_7 = { error: e_7_1 }; }
        finally {
            try {
                if (_c && !_c.done && (_a = _b.return)) _a.call(_b);
            }
            finally { if (e_7) throw e_7.error; }
        }
    };
    Multimap.prototype[Symbol.iterator] = function () {
        return this.entries();
    };
    Multimap.prototype.asMap = function () {
        var e_8, _a;
        var ret = new Map();
        try {
            for (var _b = __values(this.keys()), _c = _b.next(); !_c.done; _c = _b.next()) {
                var key = _c.value;
                ret.set(key, this.operator.clone(this.get(key)));
            }
        }
        catch (e_8_1) { e_8 = { error: e_8_1 }; }
        finally {
            try {
                if (_c && !_c.done && (_a = _b.return)) _a.call(_b);
            }
            finally { if (e_8) throw e_8.error; }
        }
        return ret;
    };
    return Multimap;
}());
exports.Multimap = Multimap;
