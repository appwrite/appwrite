"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
var Group = /** @class */ (function () {
    function Group(value, start, end, children) {
        this.value = value;
        this.start = start;
        this.end = end;
        this.children = children;
    }
    Object.defineProperty(Group.prototype, "values", {
        get: function () {
            return (this.children.length === 0 ? [this] : this.children).map(function (g) { return g.value; });
        },
        enumerable: false,
        configurable: true
    });
    return Group;
}());
exports.default = Group;
//# sourceMappingURL=Group.js.map