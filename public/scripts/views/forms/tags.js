(function (window) {
    "use strict";

    window.ls.container.get('view').add(
        {
            selector: 'data-forms-tags',
            controller: function(element) {
                let array   = [];
                let tags    = window.document.createElement('div');
                let preview = window.document.createElement('ul');
                let add     = window.document.createElement('input');
                
                tags.className = 'tags';
                
                preview.className = 'tags-list';

                add.type = 'text';
                add.className = 'add';
                
                //add.required = element.required;

                tags.addEventListener('click', function() {
                    add.focus();
                });

                add.addEventListener('keydown', function(event) {
                    if (((event.key === "Enter" || event.key === " ")) && (add.value.length > 0)) {
                        array.push(add.value);
                        
                        add.value = '';
                        
                        element.value = JSON.stringify(array);
                        
                        check();

                        event.preventDefault();
                    }
                    if (((event.key === "Backspace" || event.key === "Delete")) && (add.value === '')) {
                        array.splice(-1, 1)
                        
                        element.value = JSON.stringify(array);
                        
                        check();
                    }

                    return false;
                });

                let check = function() {
                    try {
                        array = JSON.parse(element.value) || [];
                    }
                    catch(error) {
                        array = [];
                    }

                    if(!Array.isArray(array)) {
                        array = [];
                    }

                    preview.innerHTML = '';

                    for (let index = 0; index < array.length; index++) {
                        let value = array[index];
                        let tag = window.document.createElement('li');

                        tag.className = 'tag';
                        tag.textContent = value;

                        tag.addEventListener('click', function () {                            
                            array.splice(index, 1);

                            element.value = JSON.stringify(array);

                            check();
                        });

                        preview.appendChild(tag);
                    }

                    if(element.required && array.length === 0) {
                        add.setCustomValidity('Please add permissions');
                    }
                    else {
                        add.setCustomValidity('');
                    }
                };

                tags.appendChild(preview);
                tags.appendChild(add);

                element.parentNode.insertBefore(tags, element);

                element.addEventListener('change', check);

                check();
            }
        }
    );

})(window);