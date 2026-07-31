<?php

namespace App\Enums;

enum ServicePostBehavior: string
{
    case ConfirmationOnly = 'confirmation_only';
    case HealthForm = 'health_form';
    case ScreeningForm = 'screening_form';
    case DonationForm = 'donation_form';
    case CustomForm = 'custom_form';

    public function label(): string
    {
        return match ($this) {
            self::ConfirmationOnly => 'Konfirmasi selesai',
            self::HealthForm => 'Konfirmasi layanan kesehatan',
            self::ScreeningForm => 'Form screening',
            self::DonationForm => 'Proses donor',
            self::CustomForm => 'Form catatan',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ConfirmationOnly => 'Petugas cukup memanggil lalu menandai pelayanan selesai.',
            self::HealthForm => 'Petugas mengonfirmasi layanan kesehatan telah diberikan.',
            self::ScreeningForm => 'Petugas menentukan hasil screening peserta.',
            self::DonationForm => 'Petugas memulai dan menyelesaikan proses donor.',
            self::CustomForm => 'Petugas menyimpan catatan pelayanan sebelum selesai.',
        };
    }

    public function defaultQueuePrefix(): ?string
    {
        return match ($this) {
            self::ScreeningForm => 'K',
            self::DonationForm => 'D',
            self::HealthForm => 'H',
            self::ConfirmationOnly,
            self::CustomForm => null,
        };
    }

    public function isOperationalService(): bool
    {
        return in_array($this, [
            self::ScreeningForm,
            self::DonationForm,
            self::HealthForm,
        ], true);
    }
}
