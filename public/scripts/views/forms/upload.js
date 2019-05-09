(function (window) {
    "use strict";

    window.ls.container.get('view').add(
        {
            selector: 'data-forms-upload',
            repeat: false,
            controller: function(element, window, container, alerts, expression) {
                var scope       = element.dataset['scope'];
                var label       = element.dataset['label'] || 'Upload';
                var labelRemove = element.dataset['labelRemove'] || 'Remove';
                var accept      = element.dataset['accept'] || '';
                var required    = (element.dataset['required'] || false);
                var sdk         = (scope === 'sdk') ? container.get('sdk') : container.get('console');
                var input       = document.createElement('input');
                var upload      = document.createElement('div');
                var progress    = document.createElement('div');
                var image       = document.createElement('img');
                var info        = document.createElement('div');
                var reset       = document.createElement('button');

                element.value = '';

                input.type = 'file';
                input.accept = accept;
                input.required = required;

                upload.className = 'button margin-bottom';
                upload.innerHTML = '<i class="icon icon-upload"></i> ' + label;

                progress.style.background = 'green';
                progress.style.width = '0%';
                progress.style.height = '5px';

                image.src = '';
                image.className = 'file-preview avatar huge margin-bottom-small';

                info.innerHTML = '';

                reset.type = 'button';
                reset.className = 'tag pull-start';
                reset.innerHTML = '<i class="icon icon-cancel"></i> ' + labelRemove + '&nbsp;';
                reset.style.display = 'none';

                var humanFileSize = function (bytes, si) {
                    var thresh = si ? 1000 : 1024;

                    if(Math.abs(bytes) < thresh) {
                        return bytes + ' B';
                    }

                    var units = si
                        ? ['KB','MB','GB','TB','PB','EB','ZB','YB']
                        : ['KiB','MiB','GiB','TiB','PiB','EiB','ZiB','YiB'];

                    var u = -1;

                    do {
                        bytes /= thresh;
                        ++u;
                    } while(Math.abs(bytes) >= thresh && u < units.length - 1);

                    return bytes.toFixed(1)+' '+units[u];
                };

                var onComplete = function (message) {
                    alerts.remove(message);

                    input.disabled = '';
                    progress.style.width = '0%';
                };

                var onProgress = function (event) {
                    var percentage = (event.lengthComputable) ? Math.round(event.loaded * 100 / event.total) : '0';
                    progress.style.width = percentage + '%';
                };

                var preview = function () {
                    if(element.value === element.getAttribute('data-old-value')) { // No change from last input
                        return null;
                    }

                    element.setAttribute('data-old-value', element.value);

                    if(element.value) {
                        //image.src = element.value + '&width=300&height=300';
                        image.src = sdk.storage.getPreview(element.value, null, 300, 300);
                        reset.style.display = 'inline-block';
                    }
                    else {
                        image.src = 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs%3D';
                        info.innerHTML = '';
                    }
                };

                input.addEventListener('change', function() {
                    var message     = alerts.send({text: 'Uploading...', class: ''}, 0);
                    var files       = input.files;
                    var formData    = new FormData();
                    var read        = JSON.parse(expression.parse(element.dataset['read'] || '[]'));
                    var write       = JSON.parse(expression.parse(element.dataset['write'] || '[]'));

                    for (var i = 0; i < files.length; i++) {
                        var file = files[i];

                        formData.append('files[]', file);
                    }

                    for (var x = 0; x < read.length; x++) {
                        formData.append('read[]', read[x]);
                    }

                    for (var y = 0; y < read.length; y++) {
                        formData.append('write[]', write[y]);
                    }

                    sdk.storage.create(formData, onProgress)
                        .then(function (response) {
                            response = JSON.parse(response)[0];

                            element.value = sdk.storage.getPreview(response.$uid, response.token);
                            element.value = response.$uid;
                            element.dispatchEvent(new window.Event('change'));

                            onComplete(message);

                        }, function (error) {
                            alerts.send({text: 'An error occurred!', class: ''}, 3000); // File(s) uploaded.
                            onComplete(message);
                        });

                    input.value = '';
                    input.disabled = true;
                });

                element.addEventListener('change', function () {
                    preview();
                });

                reset.addEventListener('click', function () {
                    element.value = '';
                    element.dispatchEvent(new window.Event('change'));
                });

                element.parentNode.insertBefore(image, element);
                element.parentNode.insertBefore(upload, element);
                element.parentNode.insertBefore(reset, element);
                element.parentNode.insertBefore(info, element);
                element.parentNode.insertBefore(progress, element);

                upload.appendChild(input);

                preview();
            }
        }
    );

})(window);