# Provider egress probe

Remote provider deployments need to prove that the application host can reach
the configured provider origin. Run the read-only probe as a named operator:

```bash
php artisan connector:probe:egress --tenant=7 --as=42
php artisan connector:probe:egress --tenant=7 --as=42 --json
```

For every active connection in that tenant, the command reports DNS, TCP, and
TLS negotiation independently. Connections without a remote endpoint report
all three checks as `not_applicable`. Any red outcome makes the command exit
non-zero, which lets deployment scripts fail closed.

TCP connection and TLS negotiation each have a five-second timeout. The probe
opens no HTTP request and sends no provider credential or payload. Its TLS row
proves that encrypted negotiation is reachable; certificate identity and
authenticated provider health remain the responsibility of
`connector:health:check`.
