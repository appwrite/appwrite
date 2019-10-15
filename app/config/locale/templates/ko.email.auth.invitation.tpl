<style>
    * {
        font-family: sans-serif,Arial;
        -webkit-font-smoothing: antialiased;
        font-weight: lighter;
    }
</style>

<div style="direction: {{direction}}">
   안녕하세요,
    <br />
    <br />
    <b>{{owner}}</b>님이 회원님을 {{project}}프로젝트의 <b>{{team}}</b>팀에 초대했습니다.
    <br />
    <br />
    아래 링크를 통하여 <b>{{team}}</b>팀에 합류해주시면 됩니다.
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    만약 합류에 관심 없으시면 이 이메일을 무시하세요.
    <br />
    <br />
    감사합니다!
    <br />
    {{project}}팀 드림
</div>
