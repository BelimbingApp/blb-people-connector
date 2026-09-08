# Identity audit trail

An authorized operator can read one external identity's lifecycle without
exporting a bulk dataset or mutating connector state:

```bash
php artisan connector:identity:audit-trail AUDIT-EMP-1 --tenant=7 --as=42
php artisan connector:identity:audit-trail AUDIT-EMP-1 --tenant=7 --as=42 --json
```

The ordered output joins immutable mapping/merge snapshots with attributed
operator audits for replacements, subject-history import/export, and replay.
Historical rows with no stored operator are labelled `system`; the reader is
never presented as the actor who created them. An identity outside the current
tenant is reported as not found.
