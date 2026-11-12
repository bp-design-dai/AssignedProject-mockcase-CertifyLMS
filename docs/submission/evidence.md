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

## 監査の要確認9件の最終照合

2026-11-12（JST）に[課題ガイドの概要シートC29](https://docs.google.com/spreadsheets/d/10MwISCEyE_5jc6ao2x5z4_vfzMmOTcymoyXH2VK5ssM/edit?gid=101#gid=101&range=C29)と[PRテンプレート](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/blob/a445746356c351a590f77cb19a6bbdff8f048e51/.github/PULL_REQUEST_TEMPLATE.md)を照合しました。動的機能は動画・GIF、バグ修正・改修は修正前後の比較を添付する指定です。前後比較の形式は画像に限定されていません。

| ID | 判定 | 証跡と確認手順 |
|---|---|---|
| S-B-02 | 適合 | READMEのPayment未実装という記載を更新し、購入履歴があるパックの削除制約をS-A-03の実装と一致させました。 |
| S-A-01 | 適合 | コーチの面談設定でモックOAuth連携後、受講生が既存予定の19時枠を除いた20時枠を予約し、取消します。モックの予定は1件から0件となり、既存予定は残りました。[操作GIF](media/s-a-01-operation.gif)・[本人による実接続確認](external-api-results.md#google-calendar-s-a-01) |
| S-A-02 | 適合 | AI相談の新規会話から質問を送信し、モック回答と自動タイトルを確認します。再読み込み後とウィジェットでも同じ会話を表示しました。[操作GIF](media/s-a-02-operation.gif)・[本人による実接続確認](external-api-results.md#gemini-s-a-02) |
| S-A-03 | 適合 | 残面談3回から3,000円の1回パックを選び、モックCheckoutから署名付きの合成イベントを決済処理に渡します。完了画面で反映済み、ダッシュボードで残面談4回を確認しました。[操作GIF](media/s-a-03-operation.gif)・[本人による実接続確認](external-api-results.md#stripe-s-a-03) |
| S-A-05 | 適合 | 受講生の通知ベルで通知一覧を取得し、未読タブに切り替えます。全件既読でバッジと未読タブが2件から0件になりました。[操作GIF](media/s-a-05-operation.gif)・[既存のPC・モバイル画面とテスト](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/38) |
| B-B-07 | 適合 | 独立デモDBで未使用の架空分類を削除し、一覧から消え、成功通知が表示されました。[削除操作前](media/category-before.jpg)・[成功通知](media/category-delete-success.jpg) |
| B-B-13 | 適合 | Mailpitに招待メールを1通配送し、確認用パスワード不一致の拒否と正常登録を確認しました。[不一致エラー](media/onboarding-error.jpg)・[登録後のダッシュボード](media/onboarding-success.jpg) |
| B-A-02 | 適合 | 合格基準60%・5問中4問の同じ条件で、修正前0.80・不合格から修正後80.00%・合格に変わったことを確認しました。[PR #29の修正前後の採点比較CSVとHTTP検証](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/29)・[現行版の結果画面（6問中4問）](media/mock-exam-score.jpg) |
| B-A-03 | 適合 | 受験中などの有効セッションがなく、キャンセル済みのみのケースと未開始・キャンセル済みの混在ケースで、修正前は実践ターム、修正後は基礎タームです。修正後のDB値と2画面の表示を確認しました。[PR #30の修正前後比較CSVとHTTP検証](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/30)・[現行版の取消操作前](media/term-before-cancel.jpg)・[操作後](media/term-after-cancel.jpg) |

4本の操作GIFは、独立検証環境で実際に操作した順に画面を採取して連結したものです。Google・Gemini・Stripeはモックを使用しています。外部サービスでの確認範囲は実接続記録を参照してください。B-A-02とB-A-03の現行版スクリーンショットは操作確認の補足で、修正前後の比較には既存PRのテスト記録を使用しています。

公開されている課題ガイドの証跡指定に基づき、今回の6件に追加の不足はありません。個別の点数配分や、GIFに連続した録画を求める追加条件は確認できていません。[提出方法（概要シートC33）](https://docs.google.com/spreadsheets/d/10MwISCEyE_5jc6ao2x5z4_vfzMmOTcymoyXH2VK5ssM/edit?gid=101#gid=101&range=C33)

公開資料に含める新規記録はデモ用の架空データと集計値に限定しました。実アカウント、認証コード、決済ID、`.env`、メールの招待URL、DBの生データはローカル保管です。既存PRの閲覧可能性の確認は添付の内容全体の再監査を意味しません。
