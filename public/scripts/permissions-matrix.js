(function(window){
    document.addEventListener('alpine:init', () => {
        Alpine.store('permissions', {
            _permissions: [],
            permissions() {
                return (this._permissions ?? []);
            },
            addRow() {

            },
            deleteRow(index) {

            }
        });
    });
})(window);
