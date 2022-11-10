(function (window) {
  "use strict";

  //TODO: Make this generic

  window.ls.container.get("view").add({
    selector: "data-forms-oauth-custom",
    controller: function (element) {
      // provider configuration for custom forms. Keys will be property names in JSON, values the elementIDs for the according inputs
      let providers = {
        "Microsoft": {
          "clientSecret": "oauth2MicrosoftClientSecret",
          "tenantID": "oauth2MicrosoftTenantId"
        },
        "Apple": {
          "keyID": "oauth2AppleKeyId",
          "teamID": "oauth2AppleTeamId",
          "p8": "oauth2AppleP8"
        },
        "Okta": {
          "clientSecret": "oauth2OktaClientSecret",
          "oktaDomain": "oauth2OktaDomain",
          "authorizationServerId": "oauth2OktaAuthorizationServerId"
        },
        "Auth0": {
          "clientSecret": "oauth2Auth0ClientSecret",
          "auth0Domain": "oauth2Auth0Domain"
        },
        "Authentik": {
          "clientSecret": "oauth2AuthentikClientSecret",
          "authentikDomain": "oauth2AuthentikDomain"
        },
        "Keycloak": {
          "clientSecret": "oauth2KeycloakClientSecret",
          "keycloakDomain": "oauth2KeycloakDomain",
          "keycloakRealm": "oauth2KeycloakRealm"
        },
        "Gitlab": {
          "endpoint": "oauth2GitlabEndpoint",
          "clientSecret": "oauth2GitlabClientSecret",
        },
      }
      let provider = element.getAttribute("data-forms-oauth-custom");
      if (!provider || !providers.hasOwnProperty(provider)) { console.error("Provider for custom form not set or unknown") }
      let config = providers[provider];

      // Add Change Listeners for element
      element.addEventListener('change', sync);

      // Get all inputs by id and register change event listener
      let elements = {};
      for (const key in config) {
        if (Object.hasOwnProperty.call(config, key)) {
          elements[key] = document.getElementById(config[key]);
          elements[key].addEventListener('change', update);
        }
      }


      // Build the JSON based on input in custom input fields
      function update() {
        let json = {};
        for (const key in elements) {
          if (Object.hasOwnProperty.call(elements, key)) {
            json[key] = elements[key].value
          }
        }

        element.value = JSON.stringify(json);
      }

      // When the JSON changes (on load) change values in custom input fields
      function sync() {
        if (!element.value) {
          return;
        }

        let json = {};

        try {
          json = JSON.parse(element.value);
        } catch (error) {
          console.error('Failed to parse secret key');
        }

        for (const key in elements) {
          if (Object.hasOwnProperty.call(elements, key)) {
            elements[key].value = json[key] || '';
          }
        }
      }
      sync();
    }
  });
})(window);
