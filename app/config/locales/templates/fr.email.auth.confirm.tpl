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
    Cliquez sur le lien suivant pour vérifier votre adresse mail.
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    Si vous n'avez pas demandé une vérification de cette adresse mail, vous pouvez ignorer ce message.
    <br />
    <br />
    Merci,
    <br />
    L'équipe {{project}}
</div>
