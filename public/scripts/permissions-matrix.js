(function (window) {
    document.addEventListener('alpine:init', () => {
        Alpine.data('permissionsMatrix', () => ({
            permissions: [],
            rawPermissions: [],
            load(permissions) {
                if (permissions === undefined) {
                    return;
                }

                this.rawPermissions = permissions;

                permissions.map(p => {
                    let {type, role} = this.parsePermission(p);
                    type = this.parseInputPermission(type);

                    let index = -1;
                    let existing = this.permissions.find((p, idx) => {
                        if (p.role === role) {
                            index = idx;
                            return true;
                        }
                    })
                    if (existing === undefined) {
                        this.permissions.push({
                            role,
                            [type]: true,
                        });
                    }
                    if (index !== -1) {
                        existing[type] = true;
                        this.permissions[index] = existing;
                    }
                });
            },
            addPermission(formId, role, permissions) {
                if (!document.getElementById(formId).reportValidity()) {
                    return;
                }
                Object.entries(permissions).forEach(entry => {
                    let [type, enabled] = entry;
                    type = this.parseOutputPermission(type);
                    if (enabled) {
                        this.rawPermissions.push(`${type}(${role})`);
                    }
                });
                this.permissions.push({
                    role,
                    ...permissions,
                });
                this.reset();
            },
            updatePermission(index) {
                // Because the x-model does not update before the click event,
                // we setTimeout to give Alpine enough time to update the model.
                setTimeout(() => {
                    const permission = this.permissions[index];

                    Object.keys(permission).forEach(key => {
                        if (key === 'role') {
                            return;
                        }
                        const parsedKey = this.parseOutputPermission(key);
                        if (permission[key]) {
                            if (!this.rawPermissions.includes(`${parsedKey}(${permission.role})`)) {
                                this.rawPermissions.push(`${parsedKey}(${permission.role})`);
                            }
                        } else {
                            this.rawPermissions = this.rawPermissions.filter(p => {
                                return !p.includes(`${parsedKey}(${permission.role})`);
                            });
                        }
                    });
                });
            },
            removePermission(index) {
                let row = this.permissions.splice(index, 1);
                if (row.length === 1) {
                    this.rawPermissions = this.rawPermissions.filter(p => !p.includes(row[0].role));
                }
            },
            parsePermission(permission) {
                let parts = permission.split('(');
                let type = parts[0];
                let role = parts[1].replace(')', '').replace(' ', '');
                return {type, role};
            },
            parseInputPermission(key) {
                // Can't bind to a property named delete
                if (key === 'delete') {
                    return 'xdelete';
                }
                return key;
            },
            parseOutputPermission(key) {
                // Can't bind to a property named delete
                if (key === 'xdelete') {
                    return 'delete';
                }
                return key;
            }
        }));
        Alpine.data('permissionsRow', () => ({
            role: '',
            read: false,
            create: false,
            update: false,
            xdelete: false,
            reset() {
                this.role = '';
                this.read = this.create = this.update = this.xdelete = false;
            }
        }));
    });
})(window);
