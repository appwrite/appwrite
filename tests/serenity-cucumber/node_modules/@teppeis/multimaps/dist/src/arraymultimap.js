"use strict";
var __extends = (this && this.__extends) || (function () {
    var extendStatics = function (d, b) {
        extendStatics = Object.setPrototypeOf ||
            ({ __proto__: [] } instanceof Array && function (d, b) { d.__proto__ = b; }) ||
            function (d, b) { for (var p in b) if (Object.prototype.hasOwnProperty.call(b, p)) d[p] = b[p]; };
        return extendStatics(d, b);
    };
    return function (d, b) {
        extendStatics(d, b);
        function __() { this.constructor = d; }
        d.prototype = b === null ? Object.create(b) : (__.prototype = b.prototype, new __());
    };
})();
Object.defineProperty(exports, "__esModule", { value: true });
exports.ArrayMultimap = void 0;
var multimap_1 = require("./multimap");
var ArrayMultimap = /** @class */ (function (_super) {
    __extends(ArrayMultimap, _super);
    function ArrayMultimap(iterable) {
        return _super.call(this, new ArrayOperator(), iterable) || this;
    }
    Object.defineProperty(ArrayMultimap.prototype, Symbol.toStringTag, {
        get: function () {
            return "ArrayMultimap";
        },
        enumerable: false,
        configurable: true
    });
    return ArrayMultimap;
}(multimap_1.Multimap));
exports.ArrayMultimap = ArrayMultimap;
var ArrayOperator = /** @class */ (function () {
    function ArrayOperator() {
    }
    ArrayOperator.prototype.create = function () {
        return [];
    };
    ArrayOperator.prototype.clone = function (collection) {
        return collection.slice();
    };
    ArrayOperator.prototype.add = function (value, collection) {
        collection.push(value);
        return true;
    };
    ArrayOperator.prototype.size = function (collection) {
        return collection.length;
    };
    ArrayOperator.prototype.delete = function (value, collection) {
        var index = collection.indexOf(value);
        if (index > -1) {
            collection.splice(index, 1);
            return true;
        }
        return false;
    };
    ArrayOperator.prototype.has = function (value, collection) {
        return collection.includes(value);
    };
    return ArrayOperator;
}());
