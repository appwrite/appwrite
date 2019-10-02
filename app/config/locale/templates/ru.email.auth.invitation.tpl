<style>
    * {
        font-family: sans-serif,Arial;
        -webkit-font-smoothing: antialiased;
        font-weight: lighter;
    }
</style>

<div style="direction: {{direction}}">
    Здравствуйте,
    <br />
    <br />
    Это письмо было отправлено вам потому что <b>{{owner}}</b> хочет пригласить вас стать членом команды <b>{{team}}</b> в {{project}}.
    <br />
    <br />
    Перейдите по ссылке чтобы присоединиться к команде <b>{{team}}</b> :
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    Если вы не заинтересованы, проигнорируйте это сообщение.
    <br />
    <br />
    Спасибо,
    <br />
    команда {{project}}
</div>
