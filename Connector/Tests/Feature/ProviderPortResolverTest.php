<?php

use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ReadableProviderPort;
use App\Domains\PeopleConnector\Connector\Contracts\WritableProviderPort;
use App\Domains\PeopleConnector\Connector\Data\CapabilityChannel;
use App\Domains\PeopleConnector\Connector\Data\CapabilityDeclaration;
use App\Domains\PeopleConnector\Connector\Data\CapabilitySet;
use App\Domains\PeopleConnector\Connector\Data\ProviderDescriptor;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderCompatibilityException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderValidationException;
use App\Domains\PeopleConnector\Connector\Exceptions\UnsupportedProviderOperation;
use App\Domains\PeopleConnector\Connector\Services\ProviderPortResolver;

interface TestEmployeeReader extends ReadableProviderPort {}

interface TestEmployeeWriter extends WritableProviderPort {}

function providerDescriptor(): ProviderDescriptor
{
    return new ProviderDescriptor('test.provider', 'Test Provider', '1.0.0', '1.0.0');
}

test('unsupported writes fail before the adapter is asked to resolve a port', function (): void {
    $provider = Mockery::mock(ProviderAdapter::class);
    $provider->shouldReceive('capabilities')->once()->andReturn(new CapabilitySet([]));
    $provider->shouldReceive('descriptor')->once()->andReturn(providerDescriptor());
    $provider->shouldNotReceive('resolvePort');

    expect(fn () => (new ProviderPortResolver)->write(
        $provider,
        PeopleCapability::EmployeeDirectory,
        TestEmployeeWriter::class,
    ))->toThrow(UnsupportedProviderOperation::class, 'does not support write access');
});

test('a declared port that cannot be resolved is a compatibility failure', function (): void {
    $provider = Mockery::mock(ProviderAdapter::class);
    $provider->shouldReceive('capabilities')->once()->andReturn(new CapabilitySet([
        new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, [
            new CapabilityChannel(CapabilityDelivery::Synchronous, TestEmployeeWriter::class),
        ]),
    ]));
    $provider->shouldReceive('descriptor')->once()->andReturn(providerDescriptor());
    $provider->shouldReceive('resolvePort')->once()->with(TestEmployeeWriter::class)->andReturnNull();

    expect(fn () => (new ProviderPortResolver)->write(
        $provider,
        PeopleCapability::EmployeeDirectory,
        TestEmployeeWriter::class,
    ))->toThrow(ProviderCompatibilityException::class, 'declares');
});

test('declared readable and writable ports resolve with their exact type', function (): void {
    $reader = new class implements TestEmployeeReader {};
    $writer = new class implements TestEmployeeWriter {};
    $provider = Mockery::mock(ProviderAdapter::class);
    $provider->shouldReceive('capabilities')->twice()->andReturn(new CapabilitySet([
        new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, [
            new CapabilityChannel(CapabilityDelivery::Synchronous, TestEmployeeReader::class),
            new CapabilityChannel(CapabilityDelivery::Synchronous, TestEmployeeWriter::class),
        ]),
    ]));
    $provider->shouldReceive('descriptor')->twice()->andReturn(providerDescriptor());
    $provider->shouldReceive('resolvePort')->once()->with(TestEmployeeReader::class)->andReturn($reader);
    $provider->shouldReceive('resolvePort')->once()->with(TestEmployeeWriter::class)->andReturn($writer);

    $resolver = new ProviderPortResolver;

    expect($resolver->read($provider, PeopleCapability::EmployeeDirectory, TestEmployeeReader::class))->toBe($reader)
        ->and($resolver->write($provider, PeopleCapability::EmployeeDirectory, TestEmployeeWriter::class))->toBe($writer);
});

test('provider validation failures retain provider operation and structured context', function (): void {
    $exception = new ProviderValidationException(
        providerId: 'test.provider',
        operation: 'employee.update',
        message: 'Employee number is required.',
        context: ['field' => 'employee_number', 'code' => 'required'],
    );

    expect($exception->providerId)->toBe('test.provider')
        ->and($exception->operation)->toBe('employee.update')
        ->and($exception->context)->toBe(['field' => 'employee_number', 'code' => 'required']);
});
