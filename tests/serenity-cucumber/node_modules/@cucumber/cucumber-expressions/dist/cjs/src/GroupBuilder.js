"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
var Group_js_1 = __importDefault(require("./Group.js"));
var GroupBuilder = /** @class */ (function () {
    function GroupBuilder() {
        this.capturing = true;
        this.groupBuilders = [];
    }
    GroupBuilder.prototype.add = function (groupBuilder) {
        this.groupBuilders.push(groupBuilder);
    };
    GroupBuilder.prototype.build = function (match, nextGroupIndex) {
        var groupIndex = nextGroupIndex();
        var children = this.groupBuilders.map(function (gb) { return gb.build(match, nextGroupIndex); });
        var value = match[groupIndex];
        var index = match.indices[groupIndex];
        var start = index ? index[0] : undefined;
        var end = index ? index[1] : undefined;
        return new Group_js_1.default(value, start, end, children);
    };
    GroupBuilder.prototype.setNonCapturing = function () {
        this.capturing = false;
    };
    Object.defineProperty(GroupBuilder.prototype, "children", {
        get: function () {
            return this.groupBuilders;
        },
        enumerable: false,
        configurable: true
    });
    GroupBuilder.prototype.moveChildrenTo = function (groupBuilder) {
        this.groupBuilders.forEach(function (child) { return groupBuilder.add(child); });
    };
    return GroupBuilder;
}());
exports.default = GroupBuilder;
//# sourceMappingURL=GroupBuilder.js.map