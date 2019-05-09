(function (window) {
    window.ls.container.get('view').add({
        selector: 'data-setup',
        controller: function(element, console, form) {
            element.addEventListener('submit', function (event) {
                event.preventDefault();

                let formData    = form.toJson(element);

                delete formData.vault;
                delete formData.plan;

                formData['name'] = formData['name'] || (element.dataset['defaultName'] || '');

                console.teams.create(formData['name'] || '')
                    .then(function (data) {

                        let team = JSON.parse(data)['$uid'];

                        formData = JSON.parse(JSON.stringify(formData).replace(new RegExp('{{teamId}}', 'g'), team)); //convert to JSON string

                        console.projects.create(formData['name'], team)
                            .then(function (data) {
                                let project= JSON.parse(data);

                                //router.change();
                                window.location.href = '/console?project=' + project['$uid'];
                            }, function () {
                                throw new Error('Failed to setup project');
                            });

                    }, function () {
                        throw new Error('Setup failed creating project team');
                    });
            })
        }
    });
})(window);