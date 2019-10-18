<style>
    * {
        font-family: sans-serif,Arial;
        -webkit-font-smoothing: antialiased;
        font-weight: lighter;
    }
</style>

<div style="direction: {{direction}}">
    Bonjour,
    <br />
    <br />
    Ce courriel vous a été envoyé car <b>{{owner}}</b> vous invite à devenir membre de l'équipe <b>{{team}}</b> sur le projet {{project}}.
    <br />
    <br />
    Cliquez sur le lien suivant pour rejoindre l'équipe <b>{{team}}</b>:
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    Si vous n'êtes pas intéressé, vous pouvez ignorer ce message.
    <br />
    <br />
    Merci,
    <br />
    L'équipe {{project}}
</div>
