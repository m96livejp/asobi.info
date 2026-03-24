/**
 * 利用規約ページ
 */
const TermsPage = {
    render(container) {
        container.innerHTML = `
            <div style="padding:16px;">
                <div class="card-title mb-8">利用規約</div>
                <div class="card" style="font-size:13px;line-height:1.8;color:var(--text-primary);">
                    <p><b>1. サービス</b><br>本サービスはオンラインゲームとして提供されます。内容は予告なく変更・終了する場合があります。</p>
                    <p style="margin-top:12px;"><b>2. アカウント</b><br>ゲストデータはデバイスに紐付けられます。データ保護のためアカウント連携を推奨します。</p>
                    <p style="margin-top:12px;"><b>3. 禁止事項</b><br>不正アクセス・チート・他ユーザーへの迷惑行為を禁止します。違反時はアカウントを停止します。</p>
                    <p style="margin-top:12px;"><b>4. 免責</b><br>サービス中断・終了によるデータ損失について運営は責任を負いません。</p>
                    <p style="margin-top:12px;"><b>5. 個人情報</b><br>ソーシャルログインで取得した情報はログイン認証のみに使用し、第三者へは提供しません。</p>
                </div>
            </div>
        `;
    },
};
