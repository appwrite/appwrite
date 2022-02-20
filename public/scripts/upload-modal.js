(function(window){
    document.addEventListener('alpine:init', () => {
        Alpine.store('uploader', {
            files: [],
            isOpen: true,
            init() {
                window.addEventListener('beforeunload', (event) => {
                    this.files.forEach((file) => {
                        if(!file.completed && !file.failed) {
                            let confirmationMessage = "There are incomplete uploads, are you sure you want to leave?";
                            event.returnValue = confirmationMessage;
                            return confirmationMessage;
                        }
                    });
                });
            },
            toggle() {
                this.isOpen = !this.isOpen;
            },
            addFile(file) {
                this.files.push(file);
            },
            updateFile(id, file) {
                this.files = this.files.map((oldFile) => id == oldFile.id ? {...oldFile, ...file} : oldFile);
            },
            removeFile(id) {
                const file = this.getFile(id) ?? {};
                if(file.completed || file.failed) {
                    this.files = this.files.filter((file) => file.id !== id);
                } else {
                    this.updateFile(id, {cancelled: true});
                }
            },
            getFile(id) {
                return this.files.find((file) => file.id === id);
            },
            async uploadFile(target) {
                const formData = new FormData(target);
                const sdk = window.ls.container.get('sdk');
                const file = formData.get('file');
                const fileId = formData.get('fileId');
                let id = fileId === 'unique()' ? performance.now() : fileId;
                let read = formData.get('read');
                if(read) {
                    read = JSON.parse(read);
                }
                let write = formData.get('write');
                if(write) {
                    write = JSON.parse(wirte);
                }

                this.addFile({
                    id: id,
                    name: file.name,
                    progress: 0,
                    completed: false,
                    failed: false,
                    cancelled: false,
                });
                target.reset();
                try {
                    const response = await sdk.storage.createFile(
                        formData.get('bucketId'),
                        fileId,
                        file,
                        read,
                        write,
                        (progress) => {
                            this.updateFile(id, {
                                id: progress.$id,
                                progress: Math.round(progress.progress),
                            });
                            id = progress.$id;

                            const file = this.getFile(id) ?? {};
                            if(file.cancelled === true) {
                                throw 'Cancelled by user';
                            }
                        });
                    this.updateFile(id,{
                        id: response.$id,
                        name: response.name,
                        progress: 100,
                        completed: true,
                        failed: false,
                    });
                    document.dispatchEvent(new CustomEvent('storage.createFile'));
                } catch(error) {
                    console.error(error);
                    this.updateFile(id, {
                        id: id,
                        failed: true,
                    });
                    document.dispatchEvent(new CustomEvent('storage.createFile'));
                }
            }

        });
    });
})(window);