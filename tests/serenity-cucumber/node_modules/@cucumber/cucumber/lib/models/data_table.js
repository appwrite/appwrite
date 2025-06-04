"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
class DataTable {
    constructor(sourceTable) {
        if (sourceTable instanceof Array) {
            this.rawTable = sourceTable;
        }
        else {
            this.rawTable = sourceTable.rows.map((row) => row.cells.map((cell) => cell.value));
        }
    }
    hashes() {
        const copy = this.raw();
        const keys = copy[0];
        const valuesArray = copy.slice(1);
        return valuesArray.map((values) => {
            const rowObject = {};
            keys.forEach((key, index) => (rowObject[key] = values[index]));
            return rowObject;
        });
    }
    raw() {
        return this.rawTable.slice(0);
    }
    rows() {
        const copy = this.raw();
        copy.shift();
        return copy;
    }
    rowsHash() {
        const rows = this.raw();
        const everyRowHasTwoColumns = rows.every((row) => row.length === 2);
        if (!everyRowHasTwoColumns) {
            throw new Error('rowsHash can only be called on a data table where all rows have exactly two columns');
        }
        const result = {};
        rows.forEach((x) => (result[x[0]] = x[1]));
        return result;
    }
    transpose() {
        const transposed = this.rawTable[0].map((x, i) => this.rawTable.map((y) => y[i]));
        return new DataTable(transposed);
    }
}
exports.default = DataTable;
//# sourceMappingURL=data_table.js.map