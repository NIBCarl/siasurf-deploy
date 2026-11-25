<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\InstructorStatus;
use App\Models\Certificate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminCertificateExpiryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');
        Role::findOrCreate('admin', 'web');

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->assignRole('admin');

        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'status' => InstructorStatus::Active,
            'level' => 1,
            'rate_per_hour' => 600,
        ]);
    }

    private function sisaCertificate(array $attributes = []): Certificate
    {
        return Certificate::factory()->create(array_merge([
            'user_id' => $this->instructor->id,
            'type' => CertificateType::SISA->value,
            'status' => CertificateStatus::Verified->value,
            'verified_at' => now()->subMonths(11),
            'expires_at' => now()->addDays(20),
        ], $attributes));
    }

    #[Test]
    public function admin_can_extend_the_expiry_of_a_verified_certificate(): void
    {
        $certificate = $this->sisaCertificate();
        $newExpiry = now()->addYear()->toDateString();

        $this->actingAs($this->admin)
            ->patch(route('admin.certificates.update', $certificate), [
                'expires_at' => $newExpiry,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $certificate->refresh();

        $this->assertSame($newExpiry, $certificate->expires_at->toDateString());
        $this->assertSame(CertificateStatus::Verified, $certificate->status);
    }

    #[Test]
    public function extending_a_lapsed_certificate_restores_it_to_verified(): void
    {
        $certificate = $this->sisaCertificate([
            'status' => CertificateStatus::Expired->value,
            'expires_at' => now()->subDays(10),
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.certificates.update', $certificate), [
                'expires_at' => now()->addYear()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $certificate->refresh();

        $this->assertSame(CertificateStatus::Verified, $certificate->status);
        $this->assertFalse($certificate->isExpired());
    }

    #[Test]
    public function expiry_must_be_a_future_date(): void
    {
        $certificate = $this->sisaCertificate();

        $this->actingAs($this->admin)
            ->patch(route('admin.certificates.update', $certificate), [
                'expires_at' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors('expires_at');

        $this->assertNotSame(
            now()->subDay()->toDateString(),
            $certificate->fresh()->expires_at->toDateString()
        );
    }

    #[Test]
    public function expiry_is_required(): void
    {
        $certificate = $this->sisaCertificate();

        $this->actingAs($this->admin)
            ->patch(route('admin.certificates.update', $certificate), [])
            ->assertSessionHasErrors('expires_at');
    }

    #[Test]
    public function instructor_cannot_change_their_own_certificate_expiry(): void
    {
        $certificate = $this->sisaCertificate();
        $original = $certificate->expires_at->toDateString();

        $this->actingAs($this->instructor)
            ->patch(route('admin.certificates.update', $certificate), [
                'expires_at' => now()->addYears(5)->toDateString(),
            ])
            ->assertForbidden();

        $this->assertSame($original, $certificate->fresh()->expires_at->toDateString());
    }

    #[Test]
    public function other_instructors_cannot_change_someone_elses_expiry(): void
    {
        $certificate = $this->sisaCertificate();
        $original = $certificate->expires_at->toDateString();

        $other = User::factory()->create(['role' => 'instructor']);
        $other->assignRole('instructor');

        $this->actingAs($other)
            ->patch(route('admin.certificates.update', $certificate), [
                'expires_at' => now()->addYears(5)->toDateString(),
            ])
            ->assertForbidden();

        $this->assertSame($original, $certificate->fresh()->expires_at->toDateString());
    }

    #[Test]
    public function restored_expiry_keeps_the_instructor_visible_in_the_directory(): void
    {
        $certificate = $this->sisaCertificate([
            'status' => CertificateStatus::Expired->value,
            'expires_at' => now()->subDays(10),
        ]);

        $this->assertFalse($this->instructor->fresh()->hasVerifiedCertificate());

        $this->actingAs($this->admin)
            ->patch(route('admin.certificates.update', $certificate), [
                'expires_at' => now()->addYear()->toDateString(),
            ]);

        $this->assertTrue($this->instructor->fresh()->hasVerifiedCertificate());
    }
}
