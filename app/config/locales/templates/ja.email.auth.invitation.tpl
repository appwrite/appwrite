<style>
    * {
        font-family: sans-serif,Arial;
        -webkit-font-smoothing: antialiased;
        font-weight: lighter;
    }
</style>

<div style="direction: {{direction}}">
    こんにちは。
    <br />
    <br />
    <b>{{owner}}</b> さんから {{project}} プロジェクトの <b>{{team}}</b> チームへの参加招待が届きました。
    <br />
    <br />
    下記のリンクから <b>{{team}}</b> へ参加してください。
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    お手数ですが、心当たりがない場合このメールを破棄してください。
    <br />
    <br />
    ありがとうございます。
    <br />
    {{project}} チーム
</div>
