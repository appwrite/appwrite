(function (window) {
    window.ls.container.get('view').add({
        selector: 'data-setup',
        controller: function(element, console, form) {
            element.addEventListener('submit', function (event) {
                event.preventDefault();

                let formData    = form.toJson(element);

                formData['name'] = formData['name'] || (element.dataset['defaultName'] || '');

                console.teams.createTeam(formData['name'] || '')
                    .then(function (data) {
                        let team = data['$uid'];

                        formData = JSON.parse(JSON.stringify(formData).replace(new RegExp('{{teamId}}', 'g'), team)); //convert to JSON string

                        console.projects.createProject(formData['name'], team)
                            .then(function (project) {

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