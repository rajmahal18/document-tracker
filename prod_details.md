# Production Details

Purpose: quick operational reference for the live `document-tracker` deployment so future maintenance work does not need to rediscover the production setup.

Do not store secrets in this file. Keep credentials, tokens, and private keys out of the repository.

## Server

- App path: `/var/www/html/document-tracker`
- Backup script path: `/home/ubuntu/backup_document_tracker.sh`
- Backup directory: `/home/ubuntu/backups`
- Backup log: `/home/ubuntu/backups/backup.log`
- Reminder log: `/var/www/html/document-tracker/storage/logs/due-reminders.log`
- Current server timezone: `UTC`
- App/reminder timezone logic: `Asia/Manila`

## Database Auth

- Manual check on prod showed MySQL client access resolves to `doc_tracker_user@localhost`
- Current backup script intentionally relies on the server's existing MySQL client auth setup
- Do not assume explicit `mysqldump -u ... -p ...` flags are needed on prod unless the server auth model changes

## Database Migrations

- Document listing performance indexes: run `db/migrations/20260825_document_listing_performance_indexes.sql` during the next deployment.
- DTS long remarks: run `db/migrations/20260825_routes_remarks_text.sql` if it has not been applied yet.
- After index migrations on prod, run `ANALYZE TABLE routes, document_events, documents, document_branches;` so MariaDB refreshes optimizer statistics.

## Reminder Automation

### Main reminder scripts

- Manual slot runner: [scripts/send_due_today_reminders.php](/abs/path/c:/xampp/htdocs/document-tracker/scripts/send_due_today_reminders.php)
- Cron-safe window runner: [scripts/send_due_today_reminders_window.php](/abs/path/c:/xampp/htdocs/document-tracker/scripts/send_due_today_reminders_window.php)
- Shared reminder logic: [core/document_deadline_reminders.php](/abs/path/c:/xampp/htdocs/document-tracker/core/document_deadline_reminders.php)

### Current production cron

```cron
*/30 * * * * cd /var/www/html/document-tracker && APP_ENV=production php scripts/send_due_today_reminders_window.php >> /var/www/html/document-tracker/storage/logs/due-reminders.log 2>&1
```

### Reminder behavior

- Cron checks every 30 minutes
- Script only sends during Manila reminder windows
- Morning window: `8:00 AM` to before `1:00 PM` Manila
- Afternoon window: `1:00 PM` to before `6:00 PM` Manila
- Outside those windows, the wrapper exits cleanly with a skipped result
- Rerun protection uses `email_reminder_log`
- Same user/document/date/slot combination is blocked from duplicate sends

### Important prod notes

- `APP_ENV=production` must be present for cron/manual CLI runs
- `APP_URL_ORIGIN` must be set correctly in prod config so email links are absolute
- CLI base-path handling was adjusted so reminder emails no longer build `/scripts/public/...` links

## Backup Automation

### Current production cron

```cron
0 2 * * 1-5 /home/ubuntu/backup_document_tracker.sh >> /home/ubuntu/backups/backup.log 2>&1
```

### Backup behavior

- Runs at `2:00 AM` server time
- Runs on weekdays only: Monday to Friday
- Keeps only the last `3` backups per type
- Produces:
  - `document_tracker_YYYY-MM-DD.sql.gz`
  - `document_tracker_storage_YYYY-MM-DD.tar.gz`
  - `document_tracker_app_YYYY-MM-DD.tar.gz`
- App archive excludes `storage/` to avoid duplicating the heaviest payload
- Script uses temp files and only moves them into final names after successful creation
- Script rejects empty output files

### Retention strategy

- Retention is count-based, not age-based
- Keeps last `3` SQL dumps
- Keeps last `3` storage archives
- Keeps last `3` app archives
- This was chosen because the server storage budget is tight and older age-based retention was not predictable enough

## Known Production Assumptions

- The server can safely keep only a small number of large backups
- `storage/` is the heaviest backup component
- Daily full app backups must not include `storage/` because that duplicates the same large data already covered by the dedicated storage archive
- Weekend backup skipping is intentional

## Useful Commands

### Check crons

```bash
crontab -l
```

### Run reminder job manually

```bash
cd /var/www/html/document-tracker
APP_ENV=production php scripts/send_due_today_reminders_window.php --dry-run=1
```

### Run slot-specific reminder manually

```bash
cd /var/www/html/document-tracker
APP_ENV=production php scripts/send_due_today_reminders.php --slot=morning --dry-run=1
```

### Run backup manually

```bash
/home/ubuntu/backup_document_tracker.sh
```

### Check backup outputs

```bash
ls -lh /home/ubuntu/backups
tail -n 50 /home/ubuntu/backups/backup.log
```

### Check reminder outputs

```bash
tail -n 50 /var/www/html/document-tracker/storage/logs/due-reminders.log
```

### Check server timezone

```bash
date
timedatectl
```

## When Updating This File

Update this file when any of these change:

- production cron entries
- backup retention policy
- backup file structure
- production paths
- timezone assumptions
- reminder send windows
- app base-path or URL-origin behavior relevant to CLI jobs

Keep this file operational and concise. Avoid turning it into a changelog.
