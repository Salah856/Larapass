<?php

namespace Tests;

use Ramsey\Uuid\Uuid;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;
use Tests\Stubs\TestWebAuthnUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Webauthn\TrustPath\EmptyTrustPath;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialDescriptor;

class WebAuthnAuthenticationTest extends TestCase
{
    use RegistersPackage,
        RunsPublishableMigrations;

    /** @var \Tests\Stubs\TestWebAuthnUser */
    protected $user;

    protected function setUp() : void
    {
        $this->afterApplicationCreated(function () {
            $this->loadLaravelMigrations();
            $this->loadMigrationsFrom([
                '--realpath' => true,
                '--path'     => [
                    realpath(__DIR__ . '/../database/migrations'),
                ],
            ]);

            $uuid = Str::uuid();

            $this->user = TestWebAuthnUser::make()->forceFill([
                'name'     => 'john',
                'email'    => 'john.doe@mail.com',
                'password' => '$2y$10$FLIykVJWDsYSVMJyaFZZfe4tF5uBTnGsosJBL.ZfAAHsYgc27FSdi',
            ]);

            $this->user->save();

            DB::table('web_authn_credentials')->insert([
                'id'         => 'test_credential_foo',
                'user_id'               => 1,
                'is_enabled'            => true,
                'type'                  => 'public_key',
                'transports'            => json_encode([]),
                'attestation_type'      => 'none',
                'trust_path'            => json_encode(['type' => EmptyTrustPath::class]),
                'aaguid'                => Str::uuid(),
                'public_key' => 'public_key_foo',
                'counter'               => 0,
                'user_handle'           => $uuid->toString(),
                'created_at'            => now()->toDateTimeString(),
                'updated_at'            => now()->toDateTimeString(),
            ]);

            DB::table('web_authn_credentials')->insert([
                'id'         => 'test_credential_bar',
                'user_id'               => 1,
                'is_enabled'            => true,
                'type'                  => 'public_key',
                'transports'            => json_encode([]),
                'attestation_type'      => 'none',
                'trust_path'            => json_encode(['type' => EmptyTrustPath::class]),
                'aaguid'                => Str::uuid(),
                'public_key' => 'public_key_bar',
                'counter'               => 0,
                'user_handle'           => $uuid->toString(),
                'created_at'            => now()->toDateTimeString(),
                'updated_at'            => now()->toDateTimeString(),
            ]);
        });

        parent::setUp();
    }

    public function test_cycles_entity_when_no_credential_exists()
    {
        $user = TestWebAuthnUser::make()->forceFill([
            'name'     => 'mike',
            'email'    => 'mike.doe@mail.com',
            'password' => '$2y$10$FLIykVJWDsYSVMJyaFZZfe4tF5uBTnGsosJBL.ZfAAHsYgc27FSdi',
        ]);

        $user->save();

        $entity = $user->userEntity();

        $this->assertInstanceOf(PublicKeyCredentialUserEntity::class, $entity);

        $this->assertNotSame($entity->getId(), $user->userEntity()->getId());
    }

    public function test_returns_user_entity_with_handle_used_previously()
    {
        $this->assertSame($this->user->userEntity()->getId(), $this->user->userEntity()->getId());
    }

    public function test_returns_all_credentials_as_excluded()
    {
        $this->assertCount(2, $this->user->attestationExcludedCredentials());

        DB::table('web_authn_credentials')->insert([
            'id'         => 'test_credential_baz',
            'user_id'               => 1,
            'is_enabled'            => false,
            'type'                  => 'public_key',
            'transports'            => json_encode([]),
            'attestation_type'      => 'none',
            'trust_path'            => json_encode(['type' => EmptyTrustPath::class]),
            'aaguid'                => Str::uuid(),
            'public_key' => 'public_key_bar',
            'counter'               => 0,
            'user_handle'           => $this->user->userEntity()->getId(),
            'created_at'            => now()->toDateTimeString(),
            'updated_at'            => now()->toDateTimeString(),
        ]);

        $this->assertCount(3, $this->user->webAuthnCredentials()->get());
        $this->assertCount(2, $this->user->attestationExcludedCredentials());
    }

    public function test_checks_if_credentials_id_exists()
    {
        $this->assertFalse($this->user->hasCredential('doesnt_exists'));
        $this->assertTrue($this->user->hasCredential('test_credential_foo'));
    }

    public function test_adds_a_new_credential()
    {
        Date::setTestNow($now = Date::create(2020, 01, 04, 16, 30));

        $this->user->addCredential(new PublicKeyCredentialSource(
            'test_credential_id',
            'public_key',
            [],
            'none',
            new EmptyTrustPath(),
            $uuid = Uuid::uuid4(),
            $key = 'testKey',
            $handle = Uuid::uuid4(),
            0
        ));

        $this->assertDatabaseHas('web_authn_credentials', [
            'id'         => 'test_credential_id',
            'user_id'               => 1,
            'is_enabled'            => true,
            'type'                  => 'public_key',
            'transports'            => json_encode([]),
            'attestation_type'      => 'none',
            'trust_path'            => json_encode(['type' => EmptyTrustPath::class]),
            'aaguid'                => $uuid,
            'counter'               => 0,
            'user_handle'           => $handle,
            'created_at'            => $now->toDateTimeString(),
            'updated_at'            => $now->toDateTimeString(),
            'public_key' => base64_decode('testKey'),
        ]);
    }

    public function test_enables_and_disables_credentials()
    {
        $this->user->disableCredential('test_credential_foo');
        $this->assertDatabaseHas('web_authn_credentials', [
            'id' => 'test_credential_foo',
            'is_enabled' => false
        ]);

        $this->user->disableCredential(['test_credential_foo', 'test_credential_bar']);
        $this->assertCount(2, DB::table('web_authn_credentials')->where('is_enabled', false)->get());

        $this->user->enableCredential('test_credential_foo');
        $this->assertDatabaseHas('web_authn_credentials', [
            'id' => 'test_credential_foo',
            'is_enabled' => true
        ]);

        $this->user->enableCredential(['test_credential_foo', 'test_credential_bar']);
        $this->assertCount(2, DB::table('web_authn_credentials')->where('is_enabled', true)->get());
    }

    public function test_deletes_credentials()
    {
        $this->user->removeCredential('test_credential_foo');
        $this->assertDatabaseMissing('web_authn_credentials', [
            'id' => 'test_credential_foo'
        ]);

        DB::table('web_authn_credentials')->insert([
            'id'         => 'test_credential_baz',
            'user_id'               => 1,
            'is_enabled'            => false,
            'type'                  => 'public_key',
            'transports'            => json_encode([]),
            'attestation_type'      => 'none',
            'trust_path'            => json_encode(['type' => EmptyTrustPath::class]),
            'aaguid'                => Str::uuid(),
            'public_key' => 'public_key_bar',
            'counter'               => 0,
            'user_handle'           => $this->user->userEntity()->getId(),
            'created_at'            => now()->toDateTimeString(),
            'updated_at'            => now()->toDateTimeString(),
        ]);

        $this->user->removeCredential(['test_credential_bar', 'test_credential_baz']);
        $this->assertDatabaseMissing('web_authn_credentials', [
            'id' => 'test_credential_bar'
        ]);
        $this->assertDatabaseMissing('web_authn_credentials', [
            'id' => 'test_credential_baz'
        ]);
    }

    public function test_deletes_all_credentials()
    {
        $this->user->flushCredentials();

        $this->assertDatabaseCount('web_authn_credentials', 0);
    }

    public function test_deletes_all_credentials_except_one()
    {
        $this->user->flushCredentials('test_credential_foo');

        $this->assertDatabaseCount('web_authn_credentials', 1);
        $this->assertDatabaseHas('web_authn_credentials',[
            'id' => 'test_credential_foo'
        ]);
    }

    public function test_retrieves_all_credentials_as_descriptors_except_disabled()
    {
        $descriptors = $this->user->allCredentialDescriptors();

        $this->assertCount(2, $descriptors);

        foreach ($descriptors as $descriptor) {
            $this->assertInstanceOf(PublicKeyCredentialDescriptor::class, $descriptor);
        }

        $this->user->disableCredential('test_credential_foo');

        $descriptors = $this->user->allCredentialDescriptors();

        $this->assertCount(1, $descriptors);
    }

    public function test_returns_user_from_given_credential_id()
    {
        $user = call_user_func([$this->user, 'getFromCredentialId'], 'test_credential_foo');

        $this->assertTrue($this->user->is($user));

        $this->assertNull(call_user_func([$this->user, 'getFromCredentialId'], 'test_credential_baz'));
    }

    public function test_returns_user_from_given_user_handle()
    {
        $user = call_user_func([$this->user, 'getFromCredentialUserHandle'], $this->user->userHandle());

        $this->assertTrue($this->user->is($user));

        $this->assertNull(call_user_func([$this->user, 'getFromCredentialUserHandle'], 'nope'));
    }
}
