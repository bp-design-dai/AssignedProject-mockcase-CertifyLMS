# 外部APIの実接続確認結果

利用者本人が各専用環境で確認した結果を、秘密情報を含まない形でまとめています。今回の提出前検証は、キーなしの新規環境とモックテストで行いました。各PRの実接続前の記録は、その時点の検証結果として参照できます。

## Google Calendar (S-A-01)

- 本人がコーチとしてGoogle OAuthを完了しました。
- Googleカレンダーの「予定あり」の時間がLMSの予約候補から外れました。別の空き枠をLMSで予約すると対応するGoogle予定が作成され、LMSからキャンセルすると削除されました。無関係な手動予定は残りました。
- 連携解除後の旧予定の扱い、終日・繰り返し、トークン更新、障害時のフォールバックや並行予約はPR記載のモック・HTTP・DBテストの範囲です。
- 実接続時の画像と動画は収録していません。OAuth認証情報やGoogle予定の詳細は証跡に含めていません。[実装とモックの証跡（PR #33）](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/33)

## Gemini (S-A-02)

- 本人が質問を送信し、実Geminiの回答と最初のタイトル生成を確認しました。
- 保存した会話は再読み込み後に再表示され、全画面・ウィジェットの間で再開できました。同じ教材Sectionから既存の会話が再利用され、教材を踏まえた回答を確認しました。
- 当日の利用回数は読み取り専用DB監査で2/30回でした。利用上限・失敗時の保存と再送・同時実行・他ロールの認可は自動テストで確認した範囲です。
- 既存PRの画像はUIとモック検証の資料です。実接続時の連続録画はありません。[機能実装（PR #34）](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/34)・[UIの補足（PR #35）](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/35)

## Stripe (S-A-03)

- 本人がStripeサンドボックスのCheckoutで、3,000円・面談1回のパックをテスト決済しました。`checkout.session.completed` のWebhookはHTTP 200で受理され、残回数が3から4へ変化しました。
- 読み取り専用DB監査では、購入が `succeeded`、確定時刻あり、購入IDに紐づく `purchased` 台帳が1件、付与が1回でした。公開資料にはSession IDやイベントIDを含めていません。
- 署名不正、未払い、重複イベント、並行Webhookの付与一意性は自動テストの範囲です。本番決済と返金はこの確認の対象外です。
- 実決済の連続録画はありません。PRの画面はモック検証時の資料です。[実装とモックの証跡（PR #36）](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/36)
