<?php

namespace Database\Seeders;

use App\Enums\DonorNumberMode;
use App\Enums\EventStatus;
use App\Enums\ParticipantGender;
use App\Enums\RegistrationNumberFormat;
use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use App\Models\Event;
use App\Models\Participant;
use App\Models\ServicePost;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DefaultActiveEventSeeder extends Seeder
{
    public const EVENT_CODE = 'DONOR-DEMO';

    public const EVENT_NAME = 'Donor Darah — Event Demo';

    public function run(): void
    {
        $this->call(AdminSeeder::class);

        DB::transaction(function (): void {
            $administrator = User::query()
                ->where('email', AdminSeeder::EMAIL)
                ->firstOrFail();

            $event = Event::query()->where('code', self::EVENT_CODE)->first();

            if (! $event instanceof Event) {
                $event = new Event([
                    'code' => self::EVENT_CODE,
                    'name' => self::EVENT_NAME,
                    'description' => 'Event demo aktif untuk pengujian alur registrasi dan operasional donor darah.',
                    'location' => 'Lokasi Demo',
                    'starts_at' => now(),
                    'status' => EventStatus::Draft,
                ]);
                $event->created_by = $administrator->id;
                $event->save();
            }

            $this->configureEvent($event);
            $this->ensureServicePosts($event);
            $this->ensureActiveWhenAvailable($event);
            $this->seedDemoParticipants();
        });
    }

    private function configureEvent(Event $event): void
    {
        $event->settings()->updateOrCreate([], [
            'registration_number_format' => RegistrationNumberFormat::GenderPrefix->value,
            'registration_queue_prefix' => 'R',
            'registration_male_prefix' => 'L',
            'registration_female_prefix' => 'P',
            'registration_queue_digits' => 3,
            'donor_number_mode' => DonorNumberMode::GenderSeparated->value,
            'donor_queue_prefix' => 'D',
            'donor_queue_digits' => 3,
            'donation_capacity_male' => 4,
            'donation_capacity_female' => 4,
            'donation_capacity' => 4,
            'general_queue_digits' => 3,
            'male_donor_queue_prefix' => 'L',
            'female_donor_queue_prefix' => 'P',
        ]);

        foreach (ParticipantGender::cases() as $gender) {
            $event->donationCapacityLanes()->firstOrCreate([
                'gender' => $gender->value,
            ]);
        }
    }

    private function ensureServicePosts(Event $event): void
    {
        $this->ensureServicePost(
            event: $event,
            code: 'pos-kesehatan',
            name: 'Pos Cek Kesehatan',
            description: 'Pos awal untuk seluruh peserta Event Demo.',
            type: ServicePostType::Health,
            behavior: ServicePostBehavior::HealthForm,
            queuePrefix: 'H',
        );
        $this->ensureServicePost(
            event: $event,
            code: 'pos-donor',
            name: 'Pos Donor',
            description: 'Pos proses donor untuk peserta Event Demo.',
            type: ServicePostType::Donation,
            behavior: ServicePostBehavior::DonationForm,
            queuePrefix: 'D',
        );
    }

    private function ensureServicePost(
        Event $event,
        string $code,
        string $name,
        string $description,
        ServicePostType $type,
        ServicePostBehavior $behavior,
        string $queuePrefix,
    ): void {
        $servicePost = ServicePost::query()->firstOrNew([
            'event_id' => $event->id,
            'code' => $code,
        ]);

        $servicePost->forceFill([
            'name' => $name,
            'description' => $description,
            'type' => $type,
            'behavior' => $behavior,
            'queue_prefix' => $queuePrefix,
            'queue_number_digits' => 3,
            'is_active' => true,
        ]);

        if (! $servicePost->exists) {
            $servicePost->sequence = (int) $event->servicePosts()->max('sequence') + 1;
        }

        $servicePost->save();
    }

    private function ensureActiveWhenAvailable(Event $event): void
    {
        if ($event->status === EventStatus::Active && $event->active_marker === 'active') {
            return;
        }

        if ($event->status !== EventStatus::Draft
            || Event::query()->active()->whereKeyNot($event->id)->exists()) {
            return;
        }

        $event->forceFill([
            'status' => EventStatus::Active,
            'active_marker' => 'active',
        ])->save();
    }

    private function seedDemoParticipants(): void
    {
        foreach ([
            ['gender' => ParticipantGender::Male, 'label' => 'Peserta Laki'],
            ['gender' => ParticipantGender::Female, 'label' => 'Peserta Perempuan'],
        ] as $definition) {
            /** @var ParticipantGender $gender */
            $gender = $definition['gender'];
            $label = $definition['label'];

            for ($number = 1; $number <= 5; $number++) {
                $suffix = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
                $identifier = $gender === ParticipantGender::Male ? $number : $number + 5;

                Participant::query()->firstOrCreate([
                    'nik' => '99000000000000'.str_pad((string) $identifier, 2, '0', STR_PAD_LEFT),
                ], [
                    'name' => "{$label} {$suffix}",
                    'phone' => '0810000000'.str_pad((string) $identifier, 2, '0', STR_PAD_LEFT),
                    'gender' => $gender->value,
                ]);
            }
        }
    }
}
