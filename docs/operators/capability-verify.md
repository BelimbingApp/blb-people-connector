# Recording capability evidence

Record deployment evidence for one provider capability into the capability
register, the file `connector:health:check` compares adapter declarations
against:

```bash
php artisan connector:capability:verify hr2000.sbg employee_directory \
    --evidence=https://tracker.example/HR2000-EMP-7 --tenant=7 --as=42
```

The command appends one entry
(`{capability, evidence, verified_at, verified_by}`) under the provider in
[docs/providers/capability-register.json](../providers/capability-register.json)
(or the file `PEOPLE_CONNECTOR_CAPABILITY_REGISTER` points at), and writes
one operator audit row (**Provider capability evidence recorded**) naming the
operator, the provider's connection and the evidence reference. Entries are
appended, never rewritten: a capability already verified is reported and
nothing is written; removing evidence is a deliberate pull-request edit.

It refuses, exits non-zero and writes nothing when the capability name is
not a `PeopleCapability` value (the register loader would refuse the file
otherwise), when no evidence is given, when the provider has no connection
in the operator's tenant, or when the operator is outside the tenant or
lacks `people-connector.connection.manage`.

Recording evidence for a capability the adapter does not declare yet is
allowed (the prose register argues vendor evidence ahead of adapter support)
and warned about: the health check lists it as withdrawn until the adapter
declares it.
