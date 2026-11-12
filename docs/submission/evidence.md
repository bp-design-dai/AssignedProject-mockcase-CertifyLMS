# 提出前の証跡一覧

確認日 2026-11-11（JST）。対象はPR #46を含む `main` です。Basic 27件、Advance 14件を次の一覧から確認できます。

PR #1〜#46のマージ、本文の「概要」「調査・設計判断」「実装内容」「自動テスト」「動作確認」の5セクション、テスト結果の記載を確認しました。本文中のGitHub添付306件は取得可能でした。表の数は本文のリンクを重複除外して数えたものです。ZIP内の画像やコメントだけにある添付は数に含めていません。

各PRの「自動テスト」には当時のコマンドと結果、「動作確認」には添付と手順があります。現時点の全体・JS・外部APIモックの再検証結果は[環境構築記録](environment.md)に記載しています。

## Basic（27件）

| ID | 機能・修正内容 | PR（自動テストと動作確認） | PR本文の添付 |
|---|---|---|---|
| S-B-01 | 質問掲示板 | [#10](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/10) | 画像 8 / GIF 2 / 資料 3 |
| S-B-02 | 面談パックのマスタ管理 | [#21](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/21) | 画像 8 / GIF 2 / 資料 2 |
| S-B-03 | プランのマスタ管理 | [#22](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/22) | 画像 9 / GIF 2 / 資料 2 |
| S-B-04 | 通知基盤（アプリ内＋メール） | [#13](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/13) | 画像 6 / GIF 4 / 資料 2 |
| S-B-05 | 個人学習目標の管理 | [#23](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/23) | 画像 8 / GIF 2 / 資料 2 |
| S-B-06 | 設定・プロフィール | [#24](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/24) | 画像 9 / GIF 1 / 資料 2 |
| S-B-07 | 受講生メモの管理（コーチ） | [#25](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/25) | 画像 12 / GIF 1 / 資料 2 |
| S-B-08 | お知らせ配信（管理者） | [#14](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/14) | 画像 4 / GIF 2 / 資料 2 |
| S-B-09 | 面談リマインダー通知（前日・1時間前） | [#15](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/15) | 画像 6 / GIF 2 / 資料 2 |
| B-B-01 | コーチが担当資格の教材管理にアクセスすると 403 | [#19](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/19) | 画像 11 / 資料 2 |
| B-B-02 | 教材管理の階層一覧（Part / Chapter / Section）が並び順を無視して表示される | [#11](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/11) | 画像 6 / 資料 1 |
| B-B-03 | 公開停止した資格の教材が受講生に閲覧できてしまう | [#12](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/12) | 画像 9 / 資料 2 |
| B-B-04 | 管理者の受講生一覧に退会済みユーザーが表示される | [#7](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/7) | 画像 3 / 資料 1 |
| B-B-05 | 模試の合格点を 100% 超に設定でき誰も合格できなくなる | [#3](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/3) | 画像 2 / 資料 3 |
| B-B-06 | 招待トークンが使い回せてしまう | [#6](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/6) | 画像 2 / 資料 1 |
| B-B-07 | 資格分類マスタの削除後に成功メッセージが表示されない | [#1](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/1) | 本文のみ |
| B-B-08 | 目標受験日の更新後にダッシュボードへ遷移してしまう | [#2](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/2) | GIF 1 |
| B-B-09 | 受講登録していない資格の教材詳細を直リンクで閲覧できてしまう | [#18](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/18) | 画像 10 / 資料 2 |
| B-B-10 | 面談をキャンセルしても面談回数が返却されない | [#16](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/16) | 画像 6 / GIF 2 / 資料 2 |
| B-B-11 | 資格マスタ一覧の状態フィルタが正しく絞り込めない | [#8](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/8) | 画像 4 / 資料 1 |
| B-B-12 | オンボーディング完了後に再ログインできなくなる | [#5](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/5) | 画像 1 / 資料 1 |
| B-B-13 | オンボーディングで確認用パスワードが一致しなくても登録できる | [#4](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/4) | 資料 5 |
| B-B-14 | チャットの未読バッジに自分の発言までカウントされる | [#9](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/9) | 画像 2 / GIF 2 / 資料 1 |
| B-B-15 | コーチの受講生一覧に担当外の受講生まで表示される | [#17](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/17) | 画像 7 / 資料 2 |
| B-B-16 | 修了したユーザーがプラン機能にアクセスできてしまう | [#20](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/20) | 画像 11 / 資料 2 |
| T-B-01 | コーチダッシュボードの担当受講生一覧のクエリ最適化 | [#26](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/26) | 画像 4 / 資料 2 |
| T-B-02 | 大量レコードを扱う定期実行処理のメモリ最適化（chunk 処理） | [#27](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/27) | 資料 3 |

## Advance（14件）

| ID | 機能・修正内容 | PR（自動テストと動作確認） | PR本文の添付 |
|---|---|---|---|
| S-A-01 | Google Calendar 連携（面談予約） | [#33](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/33) | 資料 4 |
| S-A-02 | Gemini AI チャットボット | [#34](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/34) / [#35](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/35) | #34 画像 13 / 資料 2 / #35 画像 9 / 資料 3 |
| S-A-03 | Stripe 連携（追加面談購入） | [#36](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/36) | 画像 2 / 資料 2 |
| S-A-04 | 修了証 PDF 出力 | [#37](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/37) | 画像 1 / 資料 2 |
| S-A-05 | Sanctum Cookie 認証 + JS フロント通知表示 | [#38](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/38) | 画像 2 / 資料 1 |
| B-A-01 | 面談予約で同一コーチ・同一時刻枠が二重予約される | [#31](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/31) | 資料 3 |
| B-A-02 | 模試の採点結果が常に不合格になる | [#29](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/29) | 資料 3 |
| B-A-03 | 模試をキャンセルしても学習タームが基礎タームに戻らない | [#30](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/30) | 資料 3 |
| T-A-01 | 模試マスタ一覧のクエリ最適化 | [#40](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/40) | 資料 5 |
| T-A-02 | 面談機能のロジックを Action へ分離 | [#32](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/32) | 資料 3 |
| T-A-03 | 学習進捗の集計ロジックを専用 Service へ集約するリファクタリング | [#42](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/42) | 資料 4 |
| T-A-04 | 外部 API 連携のモックを使ったテスト追加 | [#43](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/43) | 資料 4 |
| T-A-05 | 通知・メール配信の非同期化（キュー） | [#39](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/39) | 画像 3 / 資料 7 |
| T-A-06 | 管理者ダッシュボード集計のキャッシュ化 | [#41](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/41) | 資料 4 |

## 補足PR

| PR | 内容 | 検証・証跡 |
|---|---|---|
| [#28](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/28) | Basicの学習閲覧テストの補足 | 当時の全体・関連テスト結果を本文に記載 |
| [#35](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/35) | AI相談のMarkdown・ウィジェットの補足 | 画像 9 / 資料 3。実接続結果は[別記](external-api-results.md#gemini-s-a-02) |
| [#44](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/44) | Composer・npmのセキュリティ更新 | 依存更新・監査の添付、全体・JS回帰。残存告知は[環境構築記録](environment.md#セキュリティ確認) |
| [#45](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/45) | メールアドレス検証のCR/LF拒否 | 本文にアプリ入力対策と全体・関連・実workerの結果。生ログはローカル保管 |
| [#46](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/46) | 管理者模試一覧の問題数表示 | 画像 3 / 資料 1。0・1・6件の前後比較、SQL 8本維持、全体回帰 |

## 採点時に参照する資料

- [新規構築・マイグレーション・初期データ・テスト結果](environment.md)
- [本人によるGoogle Calendar・Gemini・Stripeの実接続結果](external-api-results.md)
- [セキュリティ更新と残存告知](environment.md#セキュリティ確認)

PRテンプレートでは、一覧・詳細・フォームは画像、状態遷移・モーダル・非同期更新は動画またはGIF、バグ修正は前後比較、リファクタリングはテスト結果による代替が案内されています。静止画像を順に並べたGIFは各状態を確認する資料です。連続した画面録画として扱いません。

## 以前の監査で要確認だった9件

業務上の適合と提出資料の充足を区別して評価します。過去の監査で確認した業務仕様・テストの判定は適合です。下表は指摘された証跡・記載の再評価です。

| ID | 判定 | 今回確認した証跡と残る確認事項 |
|---|---|---|
| S-B-02 | 適合 | READMEのPayment未実装という記載を更新し、購入履歴があるパックの削除制約をS-A-03の実装と一致させました。 |
| S-A-01 | 要確認 | [本人確認済みの実接続結果](external-api-results.md#google-calendar-s-a-01)を公開資料に集約しました。OAuthから予定の同期までの操作動画・GIFは未収録です。 |
| S-A-02 | 要確認 | [本人確認済みの実接続結果](external-api-results.md#gemini-s-a-02)に回答・会話保存・利用回数を記載しました。既存のUI画像に加え、操作動画・GIFの要否が残ります。 |
| S-A-03 | 要確認 | [テスト決済・Webhook・台帳の確認結果](external-api-results.md#stripe-s-a-03)を公開資料に集約しました。Checkoutから回数反映までの操作動画・GIFは未収録です。 |
| S-A-05 | 要確認 | 独立デモDBでポップオーバーを開き、全件既読で19件から0件になることを確認しました。[未読時の画面](media/notification-unread.jpg)・[既読後の画面](media/notification-read.jpg)は静止画です。操作動画・GIFの要否が残ります。 |
| B-B-07 | 適合 | 独立デモDBで今回作成した未使用の架空分類を削除し、一覧から消え、成功通知が表示されることを確認しました。[削除操作前](media/category-before.jpg)・[成功通知](media/category-delete-success.jpg)は現行版の静止画です。 |
| B-B-13 | 適合 | 独立環境で招待メールをMailpitに1通配送し、確認用パスワード不一致の拒否と、入力が一致した場合の登録を確認しました。[不一致エラー](media/onboarding-error.jpg)・[登録後のダッシュボード](media/onboarding-success.jpg)は、招待URLを除いた実際のHTTP応答の表示です。 |
| B-A-02 | 要確認 | 独立デモDBで6問中4問正解、合格点60%に対し66.67%で合格を確認しました。[現行の結果画面](media/mock-exam-score.jpg)を追加しました。修正前バージョンの画面は未添付です。 |
| B-A-03 | 要確認 | 独立デモDBで実践タームを保持していた検証用の受講登録を使い、未開始セッションをキャンセルすると基礎タームになることを確認しました。[キャンセル操作前](media/term-before-cancel.jpg)・[操作後](media/term-after-cancel.jpg)は現行版の画面です。修正前バージョンの画面は未添付です。 |

総合評価は適合35件、要確認6件、不適合確定0件です。要確認の6件は証跡形式または比較画像の不足です。各動画・GIFの提出要否は、[リポジトリのPRテンプレート](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/blob/a445746356c351a590f77cb19a6bbdff8f048e51/.github/PULL_REQUEST_TEMPLATE.md)を基準に正式な提出条件と照合してください。

公開資料に含める新規記録はデモ用の架空データと集計値に限定しました。実アカウント、認証コード、決済ID、`.env`、メールの招待URL、DBの生データはローカル保管です。既存PRの閲覧可能性の確認は添付の内容全体の再監査を意味しません。
