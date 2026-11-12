# 環境構築と再現性の確認

対象コードは `a445746356c351a590f77cb19a6bbdff8f048e51`（PR #46のマージ後）です。WindowsのDocker Desktop上で、READMEに記載したコマンドを検証しました。

## 新規構築の結果

2026-11-12（JST）に独立環境で確認しました。GitHubからクローンしたコミットと、構築に使用した同じコミットの展開内容を照合しました。Docker Engine 29.7.2、Compose 5.5.0を使用しました。Composerの初回取得でタイムアウトが発生したため、キャッシュの書き込み先と並列数を指定して同じロックファイルから再実行しました。PHP依存130件のバージョン・ソース参照が `composer.lock` と一致しました。アプリイメージの `--no-cache` ビルドと、PHP 8.5.12での `composer check-platform-reqs` が成功しました。

| 確認項目 | 結果 |
|---|---|
| Docker Compose | アプリ、MySQL 8.4、Mailpit、phpMyAdminを独立プロジェクトで起動。Webログイン画面はHTTP 200。 |
| APP_KEY・ストレージ | 新規 `.env` にキーを生成し、`storage:link` を実行。IPAゴシックのフォント実体を確認。 |
| マイグレーション・Seeder | 新規 `certify_lms_submission` に51件を適用。未適用0件。デモデータ投入成功。 |
| フロントエンド | Node.js 24.22.2とnpm 12.2.2で `npm ci --include=dev`、Viteビルド成功。インストールされた175件のバージョンが `package-lock.json` と一致。JS・DOMテスト68件成功、失敗0件。 |
| 主要画面 | 3ロールでログインとダッシュボードを確認。管理者の模試一覧・面談パック・お知らせ、コーチの受講登録・面談・空き枠、受講生の受講登録・通知・面談パック購入画面を確認。 |
| 外部APIキーなし | アプリの基本画面は表示可能。AI相談の既定の無効状態はHTTP 404。外部APIの成功・エラー時の挙動はモックと既存の[実接続記録](external-api-results.md)で確認。 |
| キュー・メール | database workerがデモ招待ジョブを処理し、Mailpitで1通受信。失敗キューは0件。`queue:restart` でworkerの終了を確認し、再起動に成功。再試行・並行配信は[PR #39](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/39)の検証記録を参照。 |
| Scheduler | `schedule:list` で7処理と次回実行時刻を確認。実行時刻に `schedule:run` を呼び出し、1時間前の面談リマインダーコマンドが完了しました。 |
| Pint | 1,146ファイルを検査して成功。 |
| PHP全体テスト | 2,313成功・0失敗・10,418 assertions。 |
| 外部APIモック `external-api` | 251成功・0失敗・1,467 assertions。実APIキーなしで実行。 |

証跡に使ったDBとメールは合成データです。既存メイン環境の `.env`・Composeのハッシュ、コンテナID、DBボリューム名・作成時刻が作業前の記録と一致しました。既存DBは停止状態のまま検証したため、作業後の行チェックサムは再取得していません。

### 既存環境と同居させる場合の分離

新規クローンの `.env` でプロジェクト名、DB名、ホスト側ポートを分けます。今回使用したプロジェクトは `certify-lms-submission-check` です。Webは8022、MySQLの公開ポートは3322、Mailpit UIは8042、Viteは5192、phpMyAdminは8092です。MailpitのSMTPはCompose内部の1025を使用しました。WebのアプリDBは `certify_lms_submission`、PHPUnit用DBは同プロジェクトのMySQL内の `testing` を使用しました。

独立側のCompose overrideで、アプリのイメージを `certify-lms-submission/app:review` にし、ホスト公開ポートを `127.0.0.1` にバインドしました。既存環境と同居させる場合は、新規クローンの `compose.override.yaml` に次の設定を置きます。`!override` は公開ポート設定を置き換えます。

```yaml
services:
  laravel.test:
    image: certify-lms-submission/app:review
    ports: !override
      - "127.0.0.1:8022:80"
      - "127.0.0.1:5192:5192"
  mysql:
    ports: !override
      - "127.0.0.1:3322:3306"
  mailpit:
    ports: !override
      - "127.0.0.1:8042:8025"
  phpmyadmin:
    ports: !override
      - "127.0.0.1:8092:80"
```

## 既存メイン環境の調査

### Composer依存の差分

| パッケージ | 既存`vendor` | `composer.lock` |
|---|---|---|
| `guzzlehttp/guzzle` | `7.10.0` | `7.16.1` |
| `guzzlehttp/promises` | `2.3.0` | `2.6.0` |
| `guzzlehttp/psr7` | `2.9.0` | `2.13.2` |
| `league/commonmark` | `2.8.2` | `2.10.7` |
| `league/flysystem` | `3.33.0` | `3.36.0` |
| `paragonie/sodium_compat` | `v2.5.0` | `v2.5.2` |
| `symfony/http-foundation` | `v6.4.35` | `v6.4.50` |
| `symfony/http-kernel` | `v6.4.39` | `v6.4.50` |
| `symfony/mailer` | `v6.4.34` | `v6.4.49` |
| `symfony/mime` | `v6.4.37` | `v6.4.49` |
| `symfony/polyfill-intl-idn` | `v1.37.0` | `v1.43.0` |
| `symfony/routing` | `v6.4.37` | `v6.4.50` |
| `symfony/yaml` | `v7.4.11` | `v7.4.23` |

メインコンテナはリポジトリをバインドマウントします。`vendor/` はGit管理外で、`git pull` やコンテナの再起動では再インストールされません。12件の導入済みバージョンはPR #44前のロックと一致し、`symfony/yaml` はそれより古い版でした。既存の依存フォルダを使い続けた状態です。

採点者は新規クローンで `composer install` と `npm ci` を実行し、PHP 8.5のアプリコンテナで `composer check-platform-reqs` を確認してください。既存環境も同期する場合は、対象クローン・依存バージョン・停止中のworkerを確認したうえで、アプリコンテナ内の `composer install --prefer-dist --no-interaction` を計画します。PHPとnpmのロックファイルを更新する必要はありません。今回のメイン環境での操作はバージョン照合までです。

### メインDBの未適用マイグレーション

| ファイル | 未適用の影響 |
|---|---|
| `2026_11_02_000001_create_google_calendar_credentials_table.php` | Google OAuthの接続情報を保存できません。 |
| `2026_11_02_000002_add_google_calendar_sync_to_meetings.php` | 面談のGoogle予定ID・連携世代・同期状態を保持できません。 |
| `2026_11_04_000001_create_ai_chat_tables.php` | Gemini相談の会話、メッセージ、日次利用枠、デモ履歴を保存できません。 |
| `2026_11_05_000001_create_payments_and_stripe_webhook_events.php` | Checkoutの購入状態、Webhookの処理履歴、面談回数付与の一意性に必要な構造がありません。 |
| `2026_11_10_000001_add_notification_queue_delivery_tracking.php` | `jobs` と配信状態管理の構造がなく、databaseキューと受信者別配信結果を使えません。 |

過去の機能検証では専用DBへ適用しており、既存DBの更新は見送っていました。メイン環境でこれらの機能を使用する段階では、接続先とバックアップ、既存データへの影響、キュー設定とworkerの停止・再起動を確認してから `docker compose exec -T laravel.test php artisan migrate` を計画します。今回の採点用新規構築にはメインDBへの適用は不要です。

## セキュリティ確認

2026-11-12（JST）にロック済み依存を再監査しました。`composer audit --locked` は `laravel/framework` の5件（4種類）、`npm audit --omit=dev` は0件、開発依存を含む `npm audit` は12件（中5・高7）でした。以前の監査記録にある22件とは監査時点が異なります。今回の監査では依存を更新していません。

PR #44でロック済みの依存を更新し、PR #45でメール入力時のCR/LF拒否を追加しました。入力経路の補完対策はLaravel本体を更新するものではありません。次の告知は個別に評価済みです。正式提出時に監査の件数や影響範囲が変わっていないか確認してください。

- [GHSA-f9x3-7vq9-cph6](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6)。該当する組み込みTokenGuardを現在の認証経路では使っていません。
- [GHSA-jh5r-qr3c-85q8](https://github.com/laravel/framework/security/advisories/GHSA-jh5r-qr3c-85q8)。対象の例外ページ描画実装はLaravel 10にありません。公開運用では `APP_DEBUG=false` とします。
- [GHSA-crmm-hgp2-wgrp](https://github.com/laravel/framework/security/advisories/GHSA-crmm-hgp2-wgrp)。対象のローカルファイル一時URLは利用していません。修了証は認可付きのダウンロードです。
- [GHSA-5vg9-5847-vvmq](https://github.com/laravel/framework/security/advisories/GHSA-5vg9-5847-vvmq)。PR #45でアプリのメール検証経路に防御を追加しました。Symfony Mimeの送信境界でも拒否されます。監査では同一告知が複数ソースから報告されています。

運用上の制約として、Google連携トークンの暗号化保管は別途設計が必要です。SMTP受理とDBへの成功記録の間に強制終了した場合は再送が起こり得ます。プロセス強制終了時のPDF孤立ファイル、キャッシュ全面障害の復旧直後に最長TTLまで旧値が残る可能性も記録しています。

[依存更新と監査の証跡（PR #44）](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/44)・[アプリのメール検証対策（PR #45）](https://github.com/bp-design-dai/AssignedProject-mockcase-CertifyLMS/pull/45)
