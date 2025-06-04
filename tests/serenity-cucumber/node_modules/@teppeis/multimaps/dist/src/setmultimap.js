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
exports.SetMultimap = void 0;
var multimap_1 = require("./multimap");
var SetMultimap = /** @class */ (function (_super) {
    __extends(SetMultimap, _super);
    function SetMultimap(iterable) {
        return _super.call(this, new SetOperator(), iterable) || this;
    }
    Object.defineProperty(SetMultimap.prototype, Symbol.toStringTag, {
        get: function () {
            return "SetMultimap";
        },
        enumerable: false,
        configurable: true
    });
    return SetMultimap;
}(multimap_1.Multimap));
exports.SetMultimap = SetMultimap;
var SetOperator = /** @class */ (function () {
    function SetOperator() {
    }
    SetOperator.prototype.create = function () {
        return new Set();
    };
    SetOperator.prototype.clone = function (collection) {
        return new Set(collection);
    };
    SetOperator.prototype.add = function (value, collection) {
        var prev = collection.size;
        collection.add(value);
        return prev !== collection.size;
    };
    SetOperator.prototype.size = function (collection) {
        return collection.size;
    };
    SetOperator.prototype.delete = function (value, collection) {
        return collection.delete(value);
    };
    SetOperator.prototype.has = function (value, collection) {
        return collection.has(value);
    };
    return SetOperator;
}());
