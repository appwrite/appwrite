(function(window){
    document.addEventListener('alpine:init', () => {
        Alpine.store('uploader', {
            _files: [],
            files() {
                return (this._files ?? []).filter((file) => !file.cancelled);
            },
            isOpen: true,
            init() {
                window.addEventListener('beforeunload', (event) => {
                    if(this.hasOngoingUploads()) {
                        let confirmationMessage = "There are incomplete uploads, are you sure you want to leave?";
                        event.returnValue = confirmationMessage;
                        return confirmationMessage;
                    }
                });
            },
            cancelAll() {
                if(this.hasOngoingUploads() ? confirm("Are you sure? This will cancel and remove any ongoing uploads?") : true){
                   this._files.forEach(file => {
                        if(file.completed || file.failed) {
                            this.removeFile(file.id);
                        } else {
                            this.updateFile(file.id, {cancelled: true});
                        }
                    });
                }
            },
            hasOngoingUploads() {
                let ongoing = false;
                this._files.some((file) => {
                    if(!file.completed && !file.failed) {
                        ongoing = true;
                        return;
                    }
                });
                return ongoing;
            },
            toggle() {
                this.isOpen = !this.isOpen;
            },
            addFile(file) {
                this._files.push(file);
            },
            updateFile(id, file) {
                this._files = this._files.map((oldFile) => id == oldFile.id ? {...oldFile, ...file} : oldFile);
            },
            removeFile(id) {
                const file = this.getFile(id) ?? {};
                if(file.completed || file.failed) {
                    this._files = this._files.filter((file) => file.id !== id);
                } else {
                    if(confirm("Are you sure you want to cancel the upload?")) {
                        this.updateFile(id, {cancelled: true});
                    }
                }
            },
            getFile(id) {
                return this._files.find((file) => file.id === id);
            },
            async uploadFile(target) {
                const formData = new FormData(target);
                const sdk = window.ls.container.get('sdk');
                const bucketId = formData.get('bucketId');
                const file = formData.get('file');
                const fileId = formData.get('fileId');
                let id = fileId === 'unique()' ? performance.now() : fileId;
                if(!file || !fileId) {
                    return;
                }
                let permissions = formData.get('permissions');
                if(permissions) {
                    permissions = permissions.split(',');
                }

                if(this.getFile(id)) {
                    this.updateFile(id, {
                        name: file.name,
                        completed: false,
                        failed: false,
                        cancelled: false,
                        error: "",
                    });
                } else {
                    this.addFile({
                        id: id,
                        name: file.name,
                        progress: 0,
                        completed: false,
                        failed: false,
                        cancelled: false,
                        error: "",
                    });
                }

                target.reset();
                try {
                    const response = await sdk.storage.createFile(
                        bucketId,
                        fileId,
                        file,
                        permissions,
                        (progress) => {
                            this.updateFile(id, {
                                id: progress.$id,
                                progress: Math.round(progress.progress),
                                error: "",
                            });
                            id = progress.$id;

                            const file = this.getFile(id) ?? {};
                            if(file.cancelled === true) {
                                throw 'USER_CANCELLED';
                            }
                        });
                    const existingFile = this.getFile(id) ?? {};
                    if(existingFile.cancelled) {
                        this.updateFile(id,{
                            id: response.$id,
                            name: response.name,
                            failed: false,
                        });
                        id = response.$id;
                        throw 'USER_CANCELLED'
                    } else {
                        this.updateFile(id,{
                            id: response.$id,
                            name: response.name,
                            progress: 100,
                            completed: true,
                            failed: false,
                        });
                        id = response.$id;
                    }
                    document.dispatchEvent(new CustomEvent('storage.createFile'));
                } catch(error) {
                    if(error === 'USER_CANCELLED') {
                        await sdk.storage.deleteFile(bucketId, id);
                        this.updateFile(id, {
                            cancelled: false,
                            failed: true,
                        });
                        this.removeFile(id);
                    } else {
                        this.updateFile(id, {
                            id: id,
                            failed: true,
                            error: error.message ?? error
                        });
                    }
                    document.dispatchEvent(new CustomEvent('storage.createFile'));
                }
            }

        });
    });
})(window);
