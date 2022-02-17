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
                this.files = this.files.filter((file) => file.id !== id);
            },
            async uploadFile(target) {
                const formData = new FormData(target);
                const sdk = window.ls.container.get('sdk');
                const file = formData.get('file');
                const fileId = formData.get('fileId');
                const id = fileId === 'unique()' ? performance.now() : fileId;
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
                    isCancelled: false,
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
                            /*if cancelled
                            throw something
                                - When cancelled we need to delete the file
                                - but we don't yet have the id of the file,
                                - after resumable upload change, we will have the id
                            */
                            this.updateFile(id, {
                                id: id,
                                progress: Math.round(progress),
                            });
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