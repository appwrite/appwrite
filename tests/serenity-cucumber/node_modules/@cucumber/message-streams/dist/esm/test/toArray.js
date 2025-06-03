export default function toArray(input) {
    return new Promise((resolve, reject) => {
        const result = [];
        input.on('data', (wrapper) => result.push(wrapper));
        input.on('end', () => resolve(result));
        input.on('error', (err) => reject(err));
    });
}
//# sourceMappingURL=toArray.js.map