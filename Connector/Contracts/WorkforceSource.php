<?php

namespace App\Domains\PeopleConnector\Connector\Contracts;

/** Convenience composition for adapters that support the complete workforce lifecycle. */
interface WorkforceSource extends BootstrapsWorkforce, ReadsWorkforceChanges, ReconcilesWorkforce {}
