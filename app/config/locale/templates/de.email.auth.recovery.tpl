<style>
    * {
        font-family: sans-serif,Arial;
        -webkit-font-smoothing: antialiased;
        font-weight: lighter;
    }
</style>

<div style="direction: {{direction}}">
    Hallo {{name}},
    <br />
    <br />
    Folge diesem Link um dein Passwort für {{project}} zurückzusetzen.
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    Bitte ignoriere diese Nachricht, wenn du das Zurücksetzen deines Passworts nicht beantragt hast.
    <br />
    <br />
    Vielen Dank,
    <br />
    {{project}} Team
</div>
