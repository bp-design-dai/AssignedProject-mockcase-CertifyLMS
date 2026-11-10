# Certify LMS

マルチ資格対応の資格学習プラットフォームです。受講生は資格ごとの教材で学習し、演習問題・模擬試験で理解度を確かめながら、コーチの面談サポートを受けて資格取得を目指せます。

> プロジェクト構造・ドメインモデル・コードの読み進め方は [ONBOARDING.md](./ONBOARDING.md) を参照してください。

## 主な機能

| ロール | 機能 |
|---|---|
| 受講生（student） | 教材閲覧 / 演習問題・苦手分野ドリル / 模擬試験（分野別ヒートマップ・合格可能性スコア）/ 面談予約 / チャット / 学習時間・進捗・ストリーク管理 / 修了証の受領 |
| コーチ（coach） | 教材・演習問題・模試の管理 / 担当受講生の進捗フォロー / 面談対応・面談メモ / チャット |
| 管理者（admin） | ユーザー招待・管理 / 資格・資格分類マスタ管理 / 資格へのコーチ割当 / 面談回数の付与 / 全体ダッシュボード |

## 動作環境

- Docker Desktop / Docker Compose
- 開発環境は Laravel Sail で構築します（PHP コンテナ・MySQL・Mailpit・phpMyAdmin を起動）

## 環境構築手順

### 1. リポジトリの clone

```bash
git clone <このリポジトリの URL>
cd <リポジトリ名>
```

### 2. 環境変数ファイルの作成

```bash
cp .env.example .env
```

`.env.example` は Sail 向けに設定済みのため、コピーするだけでローカル開発を始められます（外部サービス連携のキーは後述）。

### 3. 依存パッケージのインストール（初回のみ）

`vendor/` がまだ無いため、初回のみ Docker 経由で Composer を実行します。

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs
```

### 4. Sail エイリアスの設定（推奨）

```bash
alias sail='./vendor/bin/sail'
```

以降のコマンドはこのエイリアス前提で記載します（未設定の場合は `./vendor/bin/sail` に読み替えてください）。

### 5. コンテナの起動

```bash
sail up -d
```

### 6. アプリケーションの初期化

```bash
sail artisan key:generate
sail artisan storage:link
sail artisan migrate:fresh --seed
```

`storage:link` は教材画像・プロフィール画像の配信に必要です。`migrate:fresh --seed` でテーブル作成とデモデータ投入が行われます（いつでも再実行してデータを初期状態に戻せます）。

### 7. フロントエンドのビルド

```bash
sail npm install
sail npm run build
```

Blade / CSS / JS を編集しながら開発する場合は、`build` の代わりに `sail npm run dev` を起動したままにしてください（Vite のホットリロードが効きます）。

### 8. 動作確認

http://localhost:8000 にアクセスし、下記の[ログインアカウント](#ログインアカウント)でログインできればセットアップ完了です。

## 開発環境 URL

| 用途 | URL |
|---|---|
| アプリケーション | http://localhost:8000 |
| phpMyAdmin（DB 確認） | http://localhost:8080 |
| Mailpit（メール確認） | http://localhost:8025 |

アプリケーションが送信するメール（招待メールなど）はすべて Mailpit に届きます。実際のメールは送信されません。

## ログインアカウント

`migrate:fresh --seed` 後、以下の固定アカウントが使えます（パスワードはすべて `password`）。

| ロール | メールアドレス | 備考 |
|---|---|---|
| 管理者 | admin@certify-lms.test | 全機能にアクセス可能 |
| コーチ | coach@certify-lms.test | IT 系資格の担当 |
| コーチ | coach2@certify-lms.test | ビジネス系資格の担当 |
| 受講生 | student@certify-lms.test | 受講中の資格・学習履歴・面談などのデモデータ付き |

このほか、ライフサイクル（招待中 / 受講中 / 卒業 / 退会）を網羅したデモユーザーが投入されます。

> 本サービスは**招待制**です。公開の会員登録画面はありません。新規ユーザーを作るには、管理者でログイン → ユーザー管理から招待 → Mailpit で招待メールの URL を開く → オンボーディング登録、という流れになります。

## 質問掲示板（S-B-01）の追加データ

既存環境を更新する場合は、既存データを初期化せず次を実行してください。

```bash
sail artisan migrate
sail artisan db:seed --class=QaBoardSeeder
```

新規環境では通常の Seeder に含まれます。公開資格ごとに未解決・解決済、回答なし・回答ありの質問を用意し、固定受講生を投稿者としています。再実行しても既存の質問・回答を上書きしません。
受講生・担当コーチは `/qa-board`、管理者は `/admin/qa-board` から利用できます。投稿者による質問削除は本人かつ回答0件の場合のみ、管理者専用画面ではモデレーション削除が可能です。通知送信は本機能に含みません。

## 通知基盤（S-B-04）の追加データ

既存環境ではデータを初期化せず、次を実行してください。

```bash
sail artisan migrate
sail artisan db:seed --class=NotificationSeeder
```

固定受講生・コーチに、既存のチャット・質問・面談へ遷移する既読／未読のデモ通知を各25件追加します。再実行しても既存通知・既読状態を上書きせず、Seederからメールは送りません。新規環境では通常のSeederに含まれます。

`/notifications` で本人宛の通知を確認し、行のクリックで既読化と対象画面への移動、全件既読操作ができます。チャットは送信者以外の参加者、Q&A回答は回答者以外の質問投稿者、面談予約・キャンセルは当事者の受講生・担当コーチへ通知します。配信は `InProgress` の受講生・コーチのみで、管理者・`completion_approved` は対象外です。

T-A-05以降は業務トランザクションの確定後に配信ジョブを登録し、workerがアプリ内保存とメール送信を行います。同一イベント・受信者・チャネルの成功状態と既読日時を維持し、失敗分を再試行します。メール失敗時も成功済みのアプリ内通知は残ります。Mailpitで配送を確認できます。設定・起動・復旧は後述の「通知・メールのdatabaseキュー」を参照してください。

通知ベルには未読件数が表示されます。通知ポップオーバー用JSON APIはS-A-05、お知らせ配信はS-B-08、面談リマインダーはS-B-09、配信の非同期化はT-A-05の各節を参照してください。リアルタイムpushは対象外です。

## 通知ポップオーバー（S-A-05）

受講生・コーチのTopBarのベルを開くと、Sanctum SPA Cookie認証で本人宛の通知を取得します。全件／未読の各タブは新しい順に最大10件を表示し、全件既読は本人の未読通知すべてを対象とします。通知クリックは既読処理と遷移先の認可が成功した後に移動します。既存の `/notifications` も利用できます。

`SANCTUM_STATEFUL_DOMAINS` にフロントのホスト名とポートを設定してください。同一オリジンで動作させ、将来フロントを別オリジンに置く場合は `CORS_ALLOWED_ORIGINS` に具体的なオリジンを指定し、Cookieのドメイン・SameSite・HTTPS設定を環境に合わせてください。APIトークンの発行は不要です。APIは `auth:sanctum` と `auth:web` でセッションCookieを確認し、受講生・コーチに制限します。

更新リクエストはCSRFトークンを送信します。419の場合は `/sanctum/csrf-cookie` でCookieを取得し直して1回だけ再試行し、401は再ログインを案内します。通信エラー時は件数を維持して再試行を表示します。新規テーブル・マイグレーション・Seederはありません。

## 管理者お知らせ配信（S-B-08）

既存環境では初期化せず `sail artisan migrate` を実行してください。デモ履歴を追加する場合のみ `sail artisan db:seed --class=AnnouncementSeeder` を実行します。全受講生・資格指定・ユーザー指定の3種類を追加し、通知も保存しますが、Seederからメールは送りません。再実行しても既存の履歴・通知・既読状態は変更しません。

管理者の `/admin/announcements` で履歴閲覧、新規配信ができます。対象は `InProgress` の受講生のみで、資格指定は該当資格の受講登録者、ユーザー指定は選択した1名です。HTTPでは配信を受け付け、T-A-05のworkerが対象者を分割して配信します。編集・取消・再配信・予約送信はありません。受講生は通知一覧から本人宛の通知詳細へ進み、全文を読めます。

受信者単位でアプリ内保存とメール送信が両方成功した場合を成功、例外時を失敗として集計し、失敗しても残りへ継続します。メール失敗時は該当者のDB通知だけをロールバックします。成功が1件以上の場合のみ配信履歴に成功件数・失敗件数を保存し、対象0人・全件失敗では履歴を残しません。SMTPでの受付後に接続が切れた場合など、外部メール配送の最終到達までは保証できません。

二重POSTはフォームの受付キーとDBの一意制約で抑止します。同じ成功済み受付キーの送信は元の結果へ戻し、処理中・失敗済みキーや別内容での再利用は409で拒否します。失敗時の再操作は新しい配信画面から行ってください。内部の `announcement_dispatches` は本文を持たない重複防止用受付記録であり、配信履歴には表示しません。通知基盤（S-B-04）の業務通知の失敗処理は変更していません。新しい環境変数やワーカーは不要です。

## 面談リマインダー（S-B-09）

通知方法別の送信状態テーブルとT-A-05の追加マイグレーションが必要です。既存データは初期化せず、適用先DBを確認して運用手順に従ってください。配信には後述のdatabase workerを使用します。新規Seederは不要です。

```bash
sail artisan notifications:send-meeting-reminders --window=eve
sail artisan notifications:send-meeting-reminders --window=one_hour_before
```

Schedulerはアプリのタイムゾーン（既定Asia/Tokyo）で、前日分を毎日18:00、1時間前分を5分間隔で実行します。前日分は翌日0:00:00〜23:59:59、1時間前分は実行時点の55〜65分後（両端を含む）の予約済み面談が対象です。受講生・担当コーチの両方がInProgressかつ正しいロールである場合のみ双方へ配信し、キャンセル済み・完了済み・削除済みの当事者は除外します。

開発中は必要な間だけ `sail artisan schedule:work` を起動してください。サーバーでは毎分 `php artisan schedule:run` を実行する既存のScheduler運用が必要です。これらは他の既存定期処理も実行するため、検証時に不用意に起動せず、上の個別コマンドで確認できます。

面談・前日/1時間前・受信者・通知方法ごとにsent_atを保存します。DB通知と送信状態は同一Transactionで確定し、メールは送信処理が正常終了した場合のみ記録します。Schedulerは配信受付数・ジョブ投入失敗数を返し、投入失敗がある場合は終了コード1です。実配信の失敗はworkerが他の方法・受信者を妨げず再試行し、上限到達後はfailed_jobsへ記録します。Schedulerの再実行で試行回数をリセットせず、復旧はqueue:retryを使用します。既読日時や送信済み通知は変更せず、workerも面談・当事者の適格性を再確認します。DB一意制約と行ロックで重複起動を抑止しますが、SMTP受付後の通信断など外部配送の最終到達・完全なexactly-once配送までは保証しません。

通知には面談日時と前日/1時間前の区別を表示し、既存の通知一覧・未読ベル・既読処理を使います。通知行とメールの遷移先は当事者の面談一覧です。保存された任意URLを信用せず、既読操作時には対象面談の閲覧認可を再確認します。S-B-04・S-B-08の配信処理と、面談予約・キャンセル・面談残回数は変更していません。

## 面談パックのマスタ管理（S-B-02）

管理者の `/admin/meeting-packs` で一覧・検索・状態絞り込み・作成・編集・削除を行えます。一覧は20件単位で、既存の並び順（`sort_order` 昇順、作成日時降順）を使用します。既存テーブル・Seeder・提供済み画面を使用するため、新しいマイグレーションや環境変数は不要です。

作成時は下書き固定で、編集フォームから状態は変更できません。状態変更は `Draft → Published → Archived → Draft` の3種類のみ許可します。公開中、または購入履歴が1件でもあるパックの物理削除は拒否します。状態違反・削除不可はHTMLでは元の画面と `error` フラッシュ、JSONでは409、他ロールによる操作は403です。更新・状態変更・削除はTransaction内で対象行をロックし、最新状態を再確認します。

入力はパック名100文字、説明2000文字、面談回数1〜100、価格0〜1,000,000円、Price ID 255文字、並び順0〜65535（既存マスタの検証パターン）に制限します。受講生向けの購入画面・Stripe連携・Webhook・面談回数付与は含めません。

購入履歴の `Payment` と `payments` テーブルは後続のS-A-03の所有です。未導入の現在は提供済み画面と同様に履歴なしとして表示し、導入後は `meeting_pack_id` による読み取り専用の関連を使用します。削除ガードは決済状態やModelスコープに関係なく全履歴の存在を確認します。後続の購入処理では同じパック行のロックと外部キーによる参照整合性を維持してください。購入履歴ありの削除拒否は、testing DBに限って用意する最小テーブルで検証し、アプリに決済テーブルは先行追加していません。

## プランのマスタ管理（S-B-03）

管理者の `/admin/plans` で一覧・プラン名検索・状態絞り込み・作成・編集・削除を行えます。一覧は既存マスタと同じ20件単位で、`sort_order` 昇順・作成日時降順です。契約中受講者数と詳細の受講者一覧は、現在の `users.plan_id` が一致し、`UserStatus::InProgress` のユーザーのみを対象にします。割当・延長履歴の件数や期限だけで集計せず、削除済みユーザーは既存Modelのスコープで除外します。

作成は下書き固定、編集は基本情報のみです。状態変更は提供済み画面の `Draft → Published → Archived → Draft` の順序に限ります。削除は下書きかつユーザー・契約履歴の参照がない場合のみで、招待中・卒業・退会・削除済みユーザーや過去の割当履歴も保護します。状態違反・削除不可はHTMLで元画面と `error` フラッシュ、JSONで409、管理者以外は403です。更新・状態変更・削除はTransaction内で行ロックして最新状態を確認します。

入力は提供フォームに従い、プラン名100文字・説明2000文字・受講期間1〜3650日・初期付与面談回数0〜1000回・並び順0〜65535です。マスタの編集・アーカイブで既存受講者の期限・面談回数・割当履歴は変更しません。招待・プラン延長時の公開中プラン選択は既存処理を使用します。価格・決済機能は追加していません。既存テーブル・初期データ・画面を利用するため、新しいマイグレーション・環境変数・セットアップは不要です。

## 個人学習目標（S-B-05）

既存環境では初期化せず `sail artisan migrate` を実行してください。デモ目標が必要な場合のみ `sail artisan db:seed --class=EnrollmentGoalSeeder` を実行します。各受講登録に期日あり・期日なし・達成済みの目標を追加し、再実行でも既存の目標・達成状態を上書きしません。新規環境では通常のSeederに含まれます。

受講登録詳細で目標を追加・閲覧でき、編集ページと達成／未達成の切替・物理削除を提供します。操作はInProgressの受講生本人のみ、担当資格の有効なコーチと管理者は閲覧専用です。既存の受講登録詳細・修了証の閲覧認可は変更せず、受講停止状態の本人には目標データ・操作を提供しません。目標は受講登録のSoftDelete時にも削除し、物理削除時は外部キーで連動します。復元・状態遷移履歴・達成通知はありません。

タイトルは必須100文字以内、詳細は任意1000文字以内、期日は任意でアプリのタイムゾーン（Asia/Tokyo）の今日以降です。過去日の新規指定・更新は拒否します。既に保存した期日が経過しても目標は残り、達成切替はできます。一覧は未達成の期日昇順→未達成の期日なし→達成日時（achieved_at）降順、同条件は作成日時・ID降順です。受講登録詳細と既存ダッシュボードは同じModelスコープを使用し、受講一覧には目標件数を表示します。マスタ・コーチメモ・通知・リマインダーは追加しません。新しい環境変数やビルドは不要です。

## 設定・プロフィール（S-B-06）

ログイン後の `/settings/profile` で本人の氏名（必須50文字以内）・自己紹介（任意1000文字以内）・アイコン画像を管理します。コーチのみ任意の固定面談URL（既存のURL検証、500文字以内）を編集できます。受講生・管理者による面談URLの送信は拒否し、メール・ロール・ステータス・他ユーザーの指定は更新対象にしません。全ロールと修了済み受講生が利用でき、受講中限定Middlewareは適用しません。

画像はPNG/JPEG/WebP、2MB以内です。既存のpublic diskと `storage:link` を使用し、`avatars/{本人ID}/{ULID}.{検出した拡張子}` に保存します。画像をリサイズ・圧縮せず、差替え・削除時はDB更新の確定後に本機能で保存した本人の旧画像のみ削除します。共用画像・外部URL・他人のファイルは削除しません。新規環境のUserSeederではコーチ1名に既存ロゴのサンプル画像を設定し、画像あり／なしを確認できます。既存環境でUserSeederを再実行する必要はありません。

パスワードタブでは既存Fortify Actionを再利用し、現在のパスワード検証と新パスワードの8文字以上・確認一致を必須にします。エラーは同じタブに戻し、パスワード入力値はセッションへ再表示用保存しません。プロフィール・画像・パスワード更新の成功は既存のsuccessフラッシュで表示します。Google連携、面談可能時間枠、通知設定、メール変更、2FA等は本機能に追加していません。新しいDB定義・環境変数・フロントビルドは不要です。

## 受講生メモ（S-B-07）

既存環境ではDBを初期化せず `sail artisan migrate` を実行してください。デモメモが必要な場合だけ `sail artisan db:seed --class=EnrollmentNoteSeeder` を実行します。通常の新規環境ではDatabaseSeederに含まれます。複数の受講登録に現在の担当コーチ・管理者のメモを追加し、既存の受講登録・担当割当・面談メモは変更しません。同じ受講登録と作成者にメモが既にあれば、再実行で本文を上書きしません。TOEICは既存の2コーチ共通担当で、他コーチのメモ閲覧と操作の出し分けを確認できます。

受講登録詳細の提供済みコーチメモ欄から本文（必須・2000文字以内）を追加し、作成日時降順で閲覧します。担当資格はB-B-15と同じ有効な割当（本人ID・unassigned_at IS NULL）で判定します。担当コーチは他の担当コーチ・管理者のメモも閲覧できますが、編集・削除は自分のメモだけです。管理者は任意の受講登録に追加し、全メモを編集・削除できます。受講生は本人の受講登録でもメモ欄を表示せず、直接URL・POST/PATCH/DELETEも拒否します。作成者と対象受講登録はサーバーで固定し、本文以外の送信項目では変更しません。

登録・更新・削除はTransaction内で親と対象行をロックし、最新の担当関係・作成者を再確認します。メモ削除は物理削除で復元・削除履歴はありません。親受講登録のSoftDelete時はメモを取得とURL解決から除外し、親を物理削除する場合は外部キーで連動削除します。面談単位のMeetingMemoとは別テーブルで、受講生向けのメモ公開はありません。検索・添付・個別非公開・コメント・通知・自動移管は追加していません。新しい環境変数やフロントビルドは不要です。

## テスト

```bash
sail artisan test                  # 全テスト実行
sail artisan test --filter=Xxx    # クラス名・メソッド名で絞り込み
```

## コード整形

Laravel Pint を使用しています。コミット前に実行してください。

```bash
sail bin pint --dirty    # 変更ファイルのみ整形
sail bin pint --test     # 整形漏れの確認（CI 相当のチェック）
```

## 使用技術

- PHP 8.5 / Laravel 10
- MySQL 8.4
- Laravel Fortify（認証）/ Laravel Sanctum（API 認証）
- Blade + Tailwind CSS + Vite（JavaScript は素の JS、フレームワーク不使用）
- PHPUnit / Laravel Pint
- league/commonmark（教材本文の Markdown レンダリング）
- Pusher（チャットのリアルタイム配信）
- Docker（Laravel Sail）

## 環境変数

`.env.example` をコピーするだけで、すべての機能がローカルで動作します（メールは Mailpit に配信されます）。

- `PUSHER_*` — チャットのリアルタイム配信に使用します。有効にする場合は Pusher のキーを取得して設定し、`BROADCAST_DRIVER=pusher` に変更してください。未設定（既定の `BROADCAST_DRIVER=log`）でもメッセージの送受信自体は動作し、相手画面へのリアルタイム反映のみ行われません

新しい環境変数やセットアップ手順を追加した場合は、`.env.example` と本 README に追記し、チームの誰でも環境を再現できる状態を保ってください。


## Google Calendar連携（S-A-01）

コーチ本人が「設定 → 面談設定」からGoogleのプライマリカレンダーを任意連携できます。
受講生・管理者による連携、カレンダー選択、Google Meet自動生成、過去予約の遡及同期、双方向同期は対象外です。

### 初回セットアップ（Googleの本人操作）

1. 検証用GoogleアカウントとGoogle Cloudプロジェクトを用意し、**Google Calendar API**を有効化します。
2. Google Auth Platform（OAuth同意画面）のアプリ名・サポートメール等を設定し、Audienceを組織要件に合わせます。ExternalのTestingで検証する場合は、連携する検証用Googleアカウントをテストユーザーへ追加します。
3. Data Accessに以下の2スコープを追加します。FreeBusyは予定の詳細を取得せず、終日・繰り返し・「予定なし」の扱いをGoogle側のFreeBusy判定に委ねます。
   - `https://www.googleapis.com/auth/calendar.freebusy`
   - `https://www.googleapis.com/auth/calendar.events.owned`
4. OAuthクライアントを「ウェブアプリケーション」として作成します。承認済みリダイレクトURIはローカル検証なら **`http://localhost:8000/settings/google-calendar/callback`** です。本番ではHTTPSの実際のURLを登録し、下記設定と完全一致させます。
5. Client ID・Client Secretは本人が手元の`.env`へ入力します。チャット・PR・スクリーンショット・ログへ転記しないでください。

```dotenv
GOOGLE_CALENDAR_CLIENT_ID=
GOOGLE_CALENDAR_CLIENT_SECRET=
GOOGLE_CALENDAR_REDIRECT_URI=http://localhost:8000/settings/google-calendar/callback
```

既存環境には追加マイグレーションだけを適用します（`migrate:fresh`や既存データ削除は不要）。設定キャッシュを使用している環境では設定変更後に再生成してください。

```bash
sail artisan migrate
sail npm run build
# 設定キャッシュを運用している環境のみ
sail artisan config:cache
```

未設定・未連携の場合、従来の面談機能はGoogle通信なしで利用できます。未設定の連携開始は設定確認メッセージを表示します。
Googleへのログイン・本人認証・同意・秘密情報の入力は利用者本人が実施します。実API検証は既存の私用予定を持たない検証アカウントで行ってください。
GoogleのTesting公開状態やOAuthポリシーによるトークン有効期間・本番審査の要否は運用開始前に確認してください。

### 処理・失敗時の動作

- OAuthはコーチのログインセッションに紐付く10分・1回限りのstateとPKCEで検証し、戻り先は面談設定へ固定します。Googleから取得したaccess/refresh tokenと有効期限を、本人に一意な`google_calendar_credentials`へ保存します。
- 期限前30秒から自動更新します。保存期限より早くAPIが401を返した場合も、1回だけ更新して再試行します。恒久的な認証失敗は再連携が必要な状態にし、同じ連携世代の認証エラー通知は1件だけ保存します。通信障害は再連携必須にせず、次の操作で再度更新を試みます。
- FreeBusyで空き枠を絞り、予約のコーチ行→受講生行ロック取得後にも再確認します。Google側が埋まっていれば409で別時刻を案内します。通信失敗はLMS判定で継続し、受講生に注意を表示します。呼出しは接続2秒・全体5秒に制限します。
- 面談と回数消費・返却は従来のTransactionで確定し、その`afterCommit`イベントから同期します。Googleへの作成・削除は元のLMSのTransactionが確定してから行います。
- 連携世代IDと決定的なGoogle予定IDを予約Transaction中に保存します。同期は別Transactionでコーチ行→面談行をロックして作成・削除・解除との競合を直列化します。Google操作自体はDBロールバックできませんが、元の予約/キャンセルを戻さず、保存済みIDで予定を特定できます。応答消失時の再発火でも同じIDを使い、既存の同一LMS予定なら重複作成しません。
- 件名は`Certify LMS面談｜受講生名`、60分の開始/終了日時、予約時の固定面談URLを登録します。attendeesとconferenceDataは指定せず、`sendUpdates=none`のため受講生のGoogle招待メールやMeet URL生成は行いません。
- Googleの登録・削除失敗はコーチ本人のアプリ内通知と秘密情報を含めないログに記録します。失敗通知は連携世代・操作・面談の組で重複防止します。LMSでキャンセルが完了したことと、Google予定が残った可能性を区別して案内します。
- 解除は本人のローカルトークンを削除し、既存Google予定とLMS履歴を残します。解除後のキャンセルもGoogle予定を削除しません。再連携は新しい世代となり、古い世代の面談を別アカウントで操作しません。Googleアカウント側のアプリ許可も取り消す場合は本人がGoogleの接続管理から実施します。
- 自動の高度な再送キューはありません。通知で失敗を確認した場合はコーチがGoogleカレンダーとLMSを照合してください。Googleで予定を手動変更してもLMSへ逆同期はしません。

### 保存・本番運用上の推奨

本チケットではトークンの暗号化保存追加は対象外です。ModelのJSONにはaccess/refresh tokenを含めず、Googleのレスポンス本文・私用予定の詳細・認証コード・トークンをログや受講生画面に出しません。
**本番運用ではDB内トークンの暗号化・暗号鍵管理、DB/バックアップへの最小権限、HTTPS、OAuthスコープとGoogle側の公開審査、トークン失効時の運用を整備してください。** 暗号化対応は別途行います。

### モック検証

`GoogleCalendarGateway`を差し替えて外部通信なしで検証できます。SDK境界のテストはGuzzleのMockHandlerを使用し、実Googleへ接続しません。
`GoogleCalendarCredentialFactory`と`GoogleCalendarDemoSeeder`は分離した`testing` DB専用の合成データです。Seederは連携済み/未連携コーチを新規作成し、local/production DBでは実行を拒否します。既存デモコーチにダミートークンを付けません。
実APIへの連携・終日/繰り返し/予定なし・作成/削除の最終疎通は、本人がOAuth設定を用意した後に検証アカウントで実施します。

公式資料: [OAuth Web Server](https://developers.google.com/identity/protocols/oauth2/web-server)、[FreeBusy](https://developers.google.com/workspace/calendar/api/v3/reference/freebusy/query)、[予定作成](https://developers.google.com/workspace/calendar/api/v3/reference/events/insert)、[予定削除](https://developers.google.com/workspace/calendar/api/v3/reference/events/delete)。


## Gemini AI相談（S-A-02）

受講中（`UserStatus::InProgress`）の受講生本人だけが、右下のウィジェットと`/ai-chat`で相談できます。コーチ・管理者・招待中・卒業・退会は利用できず、会話の閲覧・送信・タイトル変更・削除も所有者に限ります。会話は自動削除しません。

```dotenv
AI_CHAT_ENABLED=false
AI_CHAT_DAILY_LIMIT=30
GEMINI_API_KEY=
GEMINI_MODEL=gemini-3.5-flash-lite
GEMINI_TIMEOUT=30
# AI_CHAT_SYSTEM_PROMPT="任意の学習支援指示"
```

新規の検証環境で追加マイグレーションを適用し、アセットをビルドしてください。既存データの初期化は不要です。デモ履歴が必要な場合だけ`AiChatSeeder`を実行します。通常の新規DB用`DatabaseSeeder`にも含まれ、教材付き・全般・応答失敗の会話を用意します。SeederからGeminiへ通信せず、再実行で既存の会話・メッセージは上書きしません。

```bash
sail artisan migrate
sail artisan db:seed --class=AiChatSeeder
sail npm run build
```

実データ用DBへ適用しない検証では、`APP_ENV=testing`・`DB_DATABASE=testing`を明示した独立テストDBを使用してください。未適用の実データ用DBでは`AI_CHAT_ENABLED=false`を維持します。OFF時はUIを隠し、AI用テーブルをルートモデルバインディングで参照する前に画面・APIを404にします。ONでもAPIキー未設定なら、利用できない旨を表示し、APIは503で拒否します。設定キャッシュの運用時は変更後に専用環境のキャッシュを再生成してください。

APIキーは本人がGoogle AI Studioで検証用プロジェクトのキーを作成し、対象環境の`.env`へ直接入力します。Googleログイン・利用規約への同意・課金設定の操作は本人が行います。値をチャット・Git・PR・スクリーンショットへ貼らず、フロント用の`VITE_`変数にも入れないでください。`GEMINI_MODEL`はそのアカウントで利用可能なモデルIDを指定します。提供済みUIの旧モデル名を固定表示せず、設定例はGoogleの後継推奨に合わせています。実接続前に利用可能モデル・キーの権限・料金とクォータを本人が確認してください。

送信は`generateContent`への同期REST呼出しで、APIキーは`x-goog-api-key`ヘッダにだけ渡します。接続タイムアウト5秒、応答タイムアウトは既定30秒です。自動再送はせず、応答失敗・空応答・遮断をエラーとして保存します。リクエスト本文・APIキー・プロバイダーのエラー本文をログへ出しません。

利用回数は学習者の送信を受理した時点で、日本時間の日付ごとの`ai_chat_daily_usages`に保存します。既定は1人30回、JST翌日0時から別日分を使います。ユーザー行→会話行のロック順序で上限確認と質問保存を確定してから、DBロックを解放して外部通信します。AI失敗もカウントし、ユーザーによる再送は新しい質問として保存・加算します。タイトル生成の補助呼出しは学習者の送信回数に加算しません（更新対象の送信では回答とタイトルで最大2回のGemini呼出しがあります）。認可・入力・上限・同一会話の応答待ちで拒否した操作はAI送信を受理しません。回答待ちの競合は409、日次上限は429です。

質問と応答待ちメッセージはAPI通信前に保存されるため、失敗しても質問が残ります。失敗した質問には「同じ質問を再送」を表示します。中断された古い応答待ちは次の送信時にエラーへ変更し、既に使った回数は返却しません。削除後の外部応答で会話を復活させることはありません。

タイトルは最初のAI回答成功時に生成し、その後は前回のタイトル生成から質問が5件増えた成功応答で更新します。失敗した質問も質問数に含め、タイトル生成だけ失敗した場合は回答を維持して次の成功時に再試行します。50文字以内に制限し、手動編集で自動更新をOFFにします。タイトル編集ダイアログのチェックでONに戻せます。

現在のSection、資格画面の資格、それ以外では本人の最後の学習セッションの資格の順でコンテキストを選びます。既定資格を「最後に学習した資格」として代用しません。取得できなければ資格情報を付けず、直接紐付ける教材はSectionだけです。同じ本人・Sectionは会話を再利用し、DB一意制約とユーザー行ロックで同時作成もまとめます。教材本文の送信時にも、公開状態・本人の有効な受講登録・既存のSection閲覧Gateを確認します。過去の履歴は表示し、Geminiに送る履歴は現在の質問を含め直近10往復・最大20メッセージ（先頭の孤立したAI回答は除外）です。失敗した応答は送らず、連続する受講生の質問は同じuserターンのpartsへまとめてGeminiの交互ロール形式を維持します。

ウィジェットの開閉・会話は受講生ID別の`sessionStorage`で保持します。教材から一般画面へ移動しても保持し、明示的に別Section・別資格を開いた場合はその文脈へ切り替えます。フル画面で再開した会話も次の一般画面へ引き継ぎます。

モデル名・トークン数・応答時間はメッセージとタイトルの内部メタデータだけに保存します。Blade・動的メッセージ・ウィジェットに表示せず、公開JSONも許可項目だけを返します。通常の送信時刻・「AI相談」の固定ラベル・再送用エラー表示は維持しています。

外部通信なしの専用テストとJavaScript状態テスト:

```bash
APP_ENV=testing DB_DATABASE=testing sail artisan test tests/Feature/Http/AiChat tests/Unit/Integrations/GeminiGatewayTest.php
sail exec laravel.test node --test tests/Js/ai-chat-state.test.mjs
```

並行テストはMySQLの独立プロセス・独立接続を使い、`testing` DB以外ではマイグレーションを拒否します。`SA02_CONCURRENCY_REPORT`で接続ID・ロック待機・結果のJSONLを保存できます。実接続では、安全な独立Web・DBで本人がキーを入力した後、ウィジェットとフル画面の送信・履歴・再送・タイトル・上限案内を確認してください。実APIの利用可否・速度・課金はモックテストの成功だけでは確認できません。

公式資料: [generateContent](https://aistudio.google.com/api-reference/generate-content)、[APIキー](https://aistudio.google.com/docs/api-key)、[モデルの廃止予定](https://aistudio.google.com/docs/deprecations)。

資格紹介ページでは公開されている資格名を相談の文脈として保持します。未登録でも既存の資格紹介ページを閲覧できますが、教材本文は本人の有効な受講登録と既存Gateで許可されたSectionだけを付与します。公開停止後は資格情報も新たなプロンプトに付与しません。

AI相談の画面回帰テストは `sail npm run test:ai-chat` で実行します。既存の状態保持テストに加え、テスト専用のjsdomで実際のフォーム・メッセージDOMと本番のJSを動かし、タイトルの同期・未変更保存・ON/OFF・空状態の解除を確認します。`npm ci`では開発依存もインストールしてください（検証したSail内Nodeは24.14.1）。タイトル未変更時はtitleを再送せず、自動更新設定だけを送ります。入力中のタイトルや明示的なスイッチ操作は、AI回答が到着しても上書きしません。

AI回答の日本語括弧に隣接する太字は、通常のCommonMark判定を優先した限定的なトークナイザー補助で表示します。コード・エスケープ・リンク先を区別し、変換後のDOMPurifyを維持します。ウィジェットの幅制御は専用CSSに限定し、通常文は折り返し、コードと幅広い表はそれぞれの内側でスクロールします。`npm run test:ai-chat`にはMarkdown・保存履歴・新規回答・再送・幅制約のDOM回帰テストも含まれます。

### 追加面談購入（S-A-03、Stripeテストモード）

学習中の受講生は `/meeting-quota/checkout` から公開中かつ50円以上の面談パックを購入できます。購入開始時に購入者とパックを行ロック下で再確認し、名称・円建て金額・面談回数を `payments` に保存します。Checkout Sessionは保存値から動的に作成します。パックの `stripe_price_id` やブラウザから渡された価格は使用しません。カード入力はStripe側だけで行います。

`POST /webhooks/stripe` は生のリクエスト本文と署名をStripe SDKで検証し、Checkout Sessionの現在状態を照会します。`complete` / `paid` で、Session ID・購入ID・金額・通貨が一致した場合だけ既存台帳に `purchased` を追記します。受講状態の変更は確定済み購入の付与を妨げません。処理済みイベント、購入の付与済み時刻、台帳の購入ID一意制約を同一トランザクションで確定します。Webhookが非2xxで失敗した場合はStripe再送の対象です。

完了画面は購入者本人の保存状態を表示するだけです。反映待ちなら「決済の反映を確認中」と表示します。中断・カード拒否後にCheckoutを再試行できる間は保留のままで、Stripe側の失効や非同期決済失敗は付与せず記録します。返金は管理者がStripeダッシュボードで手動対応します。LMSの自動減算や返金UIはありません。既存の管理者付与は正の補填に限られます。

**独立環境での接続手順**

1. 作業元と別のチェックアウト・Composeプロジェクト・DBボリュームを使います。例: `CertifyLMS-sa03-live`、`certify-lms-sa03-live`、`certify_lms_sa03_live`。Webは `http://localhost:8013` を使用し、8000/8011/8012や既存DBを流用しません。独立側の `.env.example` から `.env` を作り、`APP_URL` とWebポート、DB名、セッションCookieを分けます。
2. 接続先が独立DBであることを確認してから、その環境にだけ `php artisan migrate` と必要なデモデータ用 `php artisan db:seed`、`npm ci`、`npm run build` を実行します。`PaymentSeeder` はローカル/テスト用の決済状態別サンプルを生成し、実際のStripe通信は行いません。決済キー未設定でも従来の台帳履歴は利用でき、購入開始は503になります。
3. 本人が[StripeサンドボックスのAPIキー](https://docs.stripe.com/keys)でテスト用Secret Keyを確認し、独立環境の `STRIPE_SECRET_KEY` に入力します。実装は `sk_test_` のキーだけを許可し、ライブモードの通知も拒否します。
4. 本人が[Stripe CLI](https://docs.stripe.com/stripe-cli/use-cli)で同じサンドボックスにログインし、次のコマンドでWebhookを転送します。CLIが表示する署名シークレットを独立環境の `STRIPE_WEBHOOK_SECRET` に直接入力します。公開URLを使う場合は、テストモードのWorkbenchでそのHTTPSエンドポイントを登録し、そこで発行された別の署名シークレットを使用してください。

   ```bash
   stripe listen --events checkout.session.completed,checkout.session.async_payment_succeeded,checkout.session.async_payment_failed,checkout.session.expired --forward-to http://localhost:8013/webhooks/stripe
   ```

5. 独立環境で `php artisan config:clear` を実行し、学習中の受講生でログインします。購入パックを選び、Stripeのテスト表示を確認してから[公式のテストカード](https://docs.stripe.com/testing)で決済します。支払確定のWebhookが2xxとなり、完了画面・ダッシュボード・予約画面に購入回数が反映されることを確認します。中断や未払いでは残数が増えず、同じイベントの再送や完了画面の更新でも付与は一度だけです。

キーと署名シークレットはサーバー側の独立 `.env` のみに設定し、`VITE_` 変数、Git、PR、ログ、画面キャプチャには含めません。既存DBへのマイグレーションや決済用テーブルを含む環境の初期化は行いません。

自動テストは外部通信をモックします。Sailの隔離 `testing` DBで `APP_ENV=testing DB_DATABASE=testing sail artisan test tests/Feature/Stripe tests/Unit/Integrations/StripeApiCheckoutGatewayTest.php` を実行します。署名の検証には実SDKを使い、並行Webhookは別々のMySQL接続が同じ行ロックを待つことを確認します。

公式仕様: [Checkoutの付与処理](https://docs.stripe.com/checkout/fulfillment?ui=full-page)、[Webhook署名](https://docs.stripe.com/webhooks/signature)。

### 修了証PDF（S-A-04）

修了証受領時に既存テンプレートをmPDFでA4横の日本語PDFへ変換し、非公開ディスクの `storage/app/private/certificates` に保存します。取得先は認証・認可付きの `/certificates/{certificate}/download` です。過去の発行記録にPDF実体がない場合は404になります。新規に投入するデモ修了証にはPDF実体が付きます。

SailではPlaywright依存に含まれるIPAゴシック（`/usr/share/fonts/opentype/ipafont-gothic/ipag.ttf`）を埋め込みます。別環境では日本語TrueTypeフォントを用意し、必要に応じて `CERTIFICATE_PDF_FONT_PATH` にその環境内の絶対パスを指定してください。PHP実行ユーザーには非公開保存領域と `storage/app/private/mpdf-certificates` への書き込み権限が必要です。

## 通知・メールのdatabaseキュー（T-A-05）

新しい環境では `.env` の `QUEUE_CONNECTION=database` を設定し、対象DBを確認してからマイグレーションを適用してください。既存環境で `sync` が明示されている場合は自動的には切り替わりません。`jobs` と配信状態管理の追加マイグレーションが必要です。実データ用DBへの適用は別途運用作業とし、開発検証は独立DBで行ってください。

チャット・Q&A返信・面談予約/キャンセル、前日/1時間前リマインダー、管理者お知らせ、招待メール、パスワード再設定メールを `notifications` キューで処理します。既存のコーチ担当変更リスナーなどは `default` キューを利用するため、両方を起動します。配信内容・対象・チャネル、Schedulerの時刻は維持します。

```bash
php artisan queue:work database --queue=notifications,default --sleep=1 --tries=4 --timeout=60
```

DB接続は `after_commit=true`、ジョブもcommit後に投入します。rollbackされた業務処理の通知は送信しません。workerが停止している間はジョブがDBに残り、配信されません。workerはWebとは別プロセスで常駐監視してください。設定・コード更新後は次を実行し、監視プロセスがworkerを再起動する構成にします。`queue:restart` にはworkerとコマンドで共通の永続キャッシュが必要です。Redisを追加せず、同じ保存領域の `CACHE_DRIVER=file` などを使用してください（`array` はプロセス間で共有されません）。

```bash
php artisan queue:restart
php artisan queue:failed
php artisan queue:retry <失敗ジョブのUUID>
# 原因解消と対象確認後、全失敗ジョブを再投入する場合
php artisan queue:retry all
```

停止は実行中ジョブの完了を待つ `SIGTERM` を使用します。新規配信ジョブは最大4回実行（初回+3回）、待機は60/300/900秒です。timeout=60秒よりdatabase接続のretry_after=120秒を長くし、処理中ジョブの早過ぎる再取得を避けます。上限到達後は標準の `failed_jobs` に記録し、原因解消後に `queue:retry` で復旧します。`queue:retry` は実行回数をリセットします。`sync` は開発・テスト互換用で、非同期配信やworkerの再試行を提供しません。

管理者お知らせのHTTP応答は「配信受付」です。workerが100人ずつ対象者を読み取り、受信者ごとのジョブに分割します。`announcement_dispatches` に処理状況、`announcement_recipients` に各受信者の結果を保存します。全対象が完了して1件以上成功した場合だけ従来の配信履歴へ実績を記録します。0件・全件失敗では履歴を作りません。成功とは既存仕様どおり当該受信者のアプリ内通知とメール双方の成功です。失敗を再投入して成功した場合も同じ履歴の件数を再集計し、二重計上しません。一覧の「最近の配信受付」は画面更新で確認します。

通常通知はイベント・受信者・チャネルの一意な配信状態と固定通知ID、リマインダーは既存の面談・受信者・window・チャネル一意制約を使用します。DB通知の既読状態を保持し、成功チャネルは再送しません。リマインダーはworkerでも面談取消、当事者と受講状態を再確認します。Schedulerを繰り返しても実行上限をリセットしません。失敗分は `queue:retry` で再投入してください。

メールサーバーが受理した直後、送信状態のDB確定前にworkerが強制終了した場合など、SMTPとDBをまたぐ完全な一度限りの送信は保証できません。DB通知・配信件数の重複は一意制約と行ロックで防止しますが、この境界でのメール重複は既知の制約です。招待・再設定メールもSMTPの同じ制約を受けます。キューペイロード、`failed_jobs`、メール配送エラーには宛先や招待/再設定情報が含まれ得るため、DB・運用ログへのアクセスを制限し、公開証跡へ転記しないでください。

検証では実データと分離したDBとMailpitを使用します。HTTP直後のjobs登録・メール未配信、worker実行後のDB通知・メール・配信履歴、障害時の60/300/900秒とfailed_jobs、原因解消後の `queue:retry` を確認します。DBキューを使う独立検証以外で実メールや既存検証データを変更しないでください。
