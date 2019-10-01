<style>
    * {
        font-family: sans-serif,Arial;
        -webkit-font-smoothing: antialiased;
        font-weight: lighter;
    }
</style>

<div style="direction: {{direction}}">
    Bonjour {{name}},
    <br />
    <br />
    Cliquez sur le lien suivant pour réinitialiser votre mot de passe pour le projet {{project}}.
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    Si vous n'êtes pas à l'origine de cette demande de réinitialisation de mot de passe, vous pouvez ignorer ce message.
    <br />
    <br />
    Merci,
    <br />
    L'équipe {{project}}
</div>
