# Delegated authority security boundary

`DelegatedAuthority` is a short-lived permission envelope for one named subject,
tenant, optional company, operation and audience. It also records issue and
expiry times. The signed-token round trip preserves the subject, tenant,
company, operation and audience, while the expiry and maximum-lifetime cases
exercise the time boundary
([DelegatedAuthorityTest](../../Connector/Tests/Feature/DelegatedAuthorityTest.php)).
The owning contract is
[`DelegatedAuthority`](../../Connector/Data/DelegatedAuthority.php).

This envelope is not a login session, a broad People credential or proof that
the subject may perform the operation. A valid signature proves only that the
claims were minted together and were not changed in transit. Tampering with a
company claim and presenting a token that this connector did not sign are both
refused
([DelegatedAuthorityTest](../../Connector/Tests/Feature/DelegatedAuthorityTest.php),
[DelegatedDenialParityTest](../../Connector/Tests/Feature/DelegatedDenialParityTest.php)).

## What is enforced here

The remote edge verifies the signature, expected audience and expiry before it
passes the decoded authority to the same `AcceptsDelegatedCommands` port used by
an in-process caller. The shared port then rechecks the current tenant, requested
operation and expiry. Wrong-audience and unsigned-token cases exercise the
remote-only checks; the shared denial dataset proves that accepted, expired,
wrong-tenant and wrong-operation authorities receive the same typed result over
both transports
([DelegatedDenialParityTest](../../Connector/Tests/Feature/DelegatedDenialParityTest.php)).

Expiry is intentionally checked both when a token is verified and when its
authority is spent. A caller therefore cannot verify a token, wait past its
expiry and then use the already-decoded value. The signer also refuses a
lifetime beyond the configured maximum
([DelegatedAuthorityTest](../../Connector/Tests/Feature/DelegatedAuthorityTest.php)).

The HTTP refusal contains a fixed reason code, never exception text containing
the rejected tenant or operation value
([DelegatedDenialParityTest](../../Connector/Tests/Feature/DelegatedDenialParityTest.php)).
The wider diagnostic rule is documented in
[`diagnostic-privacy.md`](../contracts/diagnostic-privacy.md).

## What an exposed token permits

There is no route registered by this repository, and the port currently has no
leave, attendance or payroll behavior behind it. Possessing a token therefore
does not by itself expose a callable endpoint or a business command
([DelegatedAuthorityTest](../../Connector/Tests/Feature/DelegatedAuthorityTest.php)).
An adopter that registers a transport makes a separate deployment and routing
decision.

Once an adopter exposes a command, a stolen token can be presented to its named
audience, in its named tenant, for its exact operation, until expiry. The holder
cannot alter the signed subject, tenant, company, operation, audience or times
without invalidating the signature; the tampering, audience, tenant, operation
and expiry cases prove those individual limits
([DelegatedAuthorityTest](../../Connector/Tests/Feature/DelegatedAuthorityTest.php)).

The current token has no nonce or `jti`, and no consumption store exists. The
same valid token can therefore be presented repeatedly during its lifetime.
Replay, clock-skew and additional cross-path hardening remain tracked in
[#185](https://github.com/BelimbingApp/blb-people-connector/issues/185); no
existing test claims single use. Deployments must not describe this token as
single-use until that contract lands with its tests.

## What the authoritative backend must still decide

The shared port currently rechecks tenant, operation and expiry. It does **not**
yet bind `subject` to the authenticated employee, compare `companyId` with the
target record, or authorize record access; its tests assert only the checks it
actually performs
([DelegatedAuthorityTest](../../Connector/Tests/Feature/DelegatedAuthorityTest.php),
[DelegatedDenialParityTest](../../Connector/Tests/Feature/DelegatedDenialParityTest.php)).
Every concrete business backend must make those subject, company and
record-policy decisions at the authoritative boundary before doing work.

Consequently, “one subject” means that the holder cannot rewrite the signed
subject claim. It does not mean this generic boundary has proved that subject is
the current actor. Likewise, exact operation matching prevents a token minted
for one operation from being changed into another, but the issuer and business
backend must ensure employee delegation is never minted or accepted for leave
approval, payroll administration or another privileged operation. The
wrong-operation test proves exact matching; there is deliberately no leave or
payroll acceptance test
([DelegatedAuthorityTest](../../Connector/Tests/Feature/DelegatedAuthorityTest.php)).

Background sync credentials are a different authority class and must never be
accepted as employee delegation. The architecture requirement, including the
separation from leave approval and payroll and the complete backend recheck, is
owned by People plan 0001's
[security boundary](https://github.com/BelimbingApp/blb-people/blob/main/docs/plans/0001-people-architecture-and-provider-boundaries.md#security-boundary).

## Adopter checklist

- Register a route only after choosing its authentication, middleware, rate
  limiting and exposure policy. No route behavior is covered by the current
  controller tests
  ([DelegatedAuthorityTest](../../Connector/Tests/Feature/DelegatedAuthorityTest.php)).
- Keep the signing secret out of the general portal when separate
  administrative authority is the threat boundary. Missing and shorter-than-32
  byte secrets fail closed
  ([DelegatedAuthorityTest](../../Connector/Tests/Feature/DelegatedAuthorityTest.php)).
- Allowlist mintable and accepted operations; recheck employee/subject binding,
  company, record scope and business authorization at execution. The existing
  denial tests cover tenant, operation and expiry, not those additional checks
  ([DelegatedDenialParityTest](../../Connector/Tests/Feature/DelegatedDenialParityTest.php)).
- Preserve denial parity whenever either transport changes by adding the case to
  the one shared fixture, which runs every listed case through both paths
  ([DelegatedDenialParityTest](../../Connector/Tests/Feature/DelegatedDenialParityTest.php)).
- Until #185 lands, treat a captured token as replayable for its full lifetime
  and keep that lifetime as short as the operation permits. The configured
  maximum is enforced when signing
  ([DelegatedAuthorityTest](../../Connector/Tests/Feature/DelegatedAuthorityTest.php)).
