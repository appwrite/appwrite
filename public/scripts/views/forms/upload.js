(function (window) {
    "use strict";

    window.ls.container.get('view').add(
        {
            selector: 'data-forms-upload',
            repeat: false,
            controller: function(element, container, alerts, expression, env) {
                var scope           = element.dataset['scope'];
                var labelButton     = element.dataset['labelButton'] || 'Upload';
                var labelLoading    = element.dataset['labelLoading'] || 'Uploading...';
                var previewWidth    = element.dataset['previewWidth'] || 200;
                var previewHeight   = element.dataset['previewHeight'] || 200;
                var accept          = element.dataset['accept'] || '';
                var required        = (element.dataset['required'] || false);
                var multiple        = (element.dataset['multiple'] || false);
                var className       = (element.dataset['class'] || 'upload');
                var max             = parseInt((element.dataset['max'] || 4));
                var sdk             = (scope === 'sdk') ? container.get('sdk') : container.get('console');
                var output          = (element.value) ? ((multiple) ? JSON.parse(element.value) : [element.value]) : [];
                var total           = 0;

                var wrapper         = document.createElement('div');
                var input           = document.createElement('input');
                var upload          = document.createElement('div'); // Fake button
                var preview         = document.createElement('ul');
                var progress        = document.createElement('div');
                var count           = document.createElement('div');

                wrapper.className = className;

                input.type = 'file';
                input.accept = accept;
                input.required = required;
                input.multiple = multiple;
                input.tabIndex = -1;

                count.className = 'count';

                upload.className = 'button reverse margin-bottom';
                upload.innerHTML = '<i class="icon icon-upload"></i> ' + labelButton;
                upload.tabIndex = 0;

                preview.className = 'preview';

                progress.className = 'progress';
                progress.style.width = '0%';
                progress.style.display = 'none';

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

                    input.disabled = false;
                    upload.classList.remove('disabled');
                    progress.style.width = '0%';
                    progress.style.display = 'none';
                };

                var render = function (files) { // Generate image previews + remove buttons + input array (array only when multiple is on)
                    if(!Array.isArray(files)) { // Support single file
                        files = [files];
                    }
                    
                    preview.innerHTML = '';
                    
                    count.innerHTML = '0 / ' + max;
                    
                    files.map(function(obj) {
                        console.log('1',obj);
                        var file = document.createElement('li');
                        var image = document.createElement('img');

                        image.src = image.src = env.API + '/storage/files/' + obj + '/preview?width=' + previewWidth + '&height=' + previewHeight;

                        file.className = 'file avatar';
                        file.tabIndex = 0;
                        file.appendChild(image);

                        count.innerHTML = files.length + ' / ' + max;

                        preview.appendChild(file);

                        if((files.length >= max)) {
                            input.disabled = true;
                            upload.classList.add('disabled');
                        }
                        else {
                            input.disabled = false;
                            upload.classList.remove('disabled');
                        }

                        var remove = (function (obj) {
                            return function (event) {
                                output = (Array.isArray(output)) ? output.filter(function(e) {return e !== obj}) : [];

                                render(output);
                            }
                        })(obj);

                        file.addEventListener('click', remove);
                        file.addEventListener('keypress', remove);

                        element.value = (multiple) ? JSON.stringify(output) : output[0];
                    });
                };

                input.addEventListener('change', function() {
                    var message     = alerts.add({text: labelLoading, class: ''}, 0);
                    var files       = input.files;
                    var read        = JSON.parse(expression.parse(element.dataset['read'] || '[]'));
                    var write       = JSON.parse(expression.parse(element.dataset['write'] || '[]'));

                    if(!multiple) {
                        output = [];
                    }

                    sdk.storage.createFile(files[0], read, write, 1)
                        .then(function (response) {
                            response.map(function(obj) {
                                if(!Array.isArray(output)) { // Support single file
                                    throw new Error('Can\'t append new file to non array value');
                                }

                                output[output.length] = obj['$uid'];

                            });

                            onComplete(message);

                            render(output);
                        }, function (error) {
                            alerts.add({text: 'An error occurred!', class: ''}, 3000); // File(s) uploaded.
                            onComplete(message);
                        });

                    input.disabled = true;
                });

                element.addEventListener('change', function () {
                    if(!element.value) {
                        return;
                    }
                    output = (multiple) ? JSON.parse(element.value) : [element.value];
                    render(output);
                });

                upload.addEventListener('keypress', function () {
                    input.click();
                });

                element.parentNode.insertBefore(wrapper, element);

                wrapper.appendChild(preview);
                wrapper.appendChild(progress);
                wrapper.appendChild(upload);

                if(multiple) {
                    wrapper.appendChild(count);
                }

                upload.appendChild(input);

                render(output);
            }
        }
    );

})(window);