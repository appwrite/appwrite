(function(window) {
  window.ls.container.get("view").add({
    selector: "data-setup",
    controller: function(element, console, form, alerts, router) {
      element.addEventListener("submit", function(event) {
        event.preventDefault();

        let loaderId = alerts.add({ text: 'Creating new project...', class: "" }, 0);

        let formData = form.toJson(element);

        formData["name"] =
          formData["name"] || (element.dataset["defaultName"] || "");

        console.teams.create('unique()', formData["name"] || "").then(
          function(data) {
            let team = data["$id"];

            formData = JSON.parse(
              JSON.stringify(formData).replace(
                new RegExp("{{teamId}}", "g"),
                team
              )
            ); //convert to JSON string

            console.projects.create(formData["projectId"], formData["name"], team).then(
              function(project) {
                alerts.remove(loaderId);
                //router.change("/console/home?project=" + project["$id"]);
                window.location.href = "/console/home?project=" + project["$id"];
              },
              function() {
                throw new Error("Failed to setup project");
              }
            );
          },
          function() {
            throw new Error("Setup failed creating project team");
          }
        );
      });
    }
  });
})(window);
