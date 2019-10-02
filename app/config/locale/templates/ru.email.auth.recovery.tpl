<style>
    * {
        font-family: sans-serif,Arial;
        -webkit-font-smoothing: antialiased;
        font-weight: lighter;
    }
</style>

<div style="direction: {{direction}}">
    Здравствуйте, {{name}},
    <br />
    <br />
    Перейдите по ссылке, чтобы сбросить пароль для проекта {{project}}.
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    Если вы не запрашивали сброс пароля, проигнорируйте это сообщение.
    <br />
    <br />
    Спасибо,
    <br />
    команда {{project}}
</div>
