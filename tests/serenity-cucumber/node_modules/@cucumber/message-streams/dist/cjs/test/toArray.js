"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
function toArray(input) {
    return new Promise(function (resolve, reject) {
        var result = [];
        input.on('data', function (wrapper) { return result.push(wrapper); });
        input.on('end', function () { return resolve(result); });
        input.on('error', function (err) { return reject(err); });
    });
}
exports.default = toArray;
//# sourceMappingURL=toArray.js.map