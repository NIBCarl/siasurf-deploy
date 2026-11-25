<?php

namespace App\Notifications;

use App\Models\BookingChangeRequest;
use App\Notifications\Concerns\ResolvesNotificationUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A cancellation or reschedule request was approved, rejected or withdrawn
 * (FR 1.9.4). Previously broadcast-only (BookingChangeResolved).
 */
class BookingChangeResolvedNotification extends Notification implements ShouldQueue
{
    use Queueable, ResolvesNotificationUrl;

    public function __construct(protected BookingChangeRequest $changeRequest)
    {
        $this->changeRequest = $changeRequest->loadMissing(['booking.student', 'booking.instructor']);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->changeRequest->booking;
        $isStudent = $booking && (int) $notifiable->id === (int) $booking->student_id;

        if ($isStudent && $this->changeRequest->isReschedule() && $this->changeRequest->status === \App\Enums\ChangeRequestStatus::Rejected) {
            $summary = $this->changeRequest->reviewed_by !== null
                ? "The reschedule request for booking #{$this->changeRequest->booking_id} was not approved. Your lesson keeps its original schedule."
                : "Your instructor withdrew the reschedule request for booking #{$this->changeRequest->booking_id}. Your lesson keeps its original schedule.";

            return (new MailMessage)
                ->subject('Reschedule Request Update')
                ->greeting('Hello '.$notifiable->name.',')
                ->line($summary)
                ->line('- Original schedule: '.$this->changeRequest->describeOriginalSlot())
                ->action('View Booking', $this->bookingUrl($notifiable, $booking?->id) ?? $this->dashboardUrl($notifiable));
        }

        return (new MailMessage)
            ->subject('Booking '.$this->changeRequest->type->label().' Update')
            ->greeting('Hello '.$notifiable->name.',')
            ->line("Your {$this->changeRequest->type->label()} request for booking #{$this->changeRequest->booking_id} was {$this->changeRequest->status->label()}.")
            ->line('- Original: '.$this->changeRequest->describeOriginalSlot())
            ->line('- Requested: '.$this->changeRequest->describeNewSlot())
            ->when($this->changeRequest->admin_note, fn (MailMessage $mail) => $mail->line('- SISA Note: '.$this->changeRequest->admin_note))
            ->action('View Booking', $this->bookingUrl($notifiable, $booking?->id) ?? $this->dashboardUrl($notifiable));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $booking = $this->changeRequest->booking;
        $isStudent = $booking && (int) $notifiable->id === (int) $booking->student_id;
        $message = ($isStudent && $this->changeRequest->isReschedule() && $this->changeRequest->status === \App\Enums\ChangeRequestStatus::Rejected)
            ? ($this->changeRequest->reviewed_by !== null
                ? "The reschedule request for booking #{$this->changeRequest->booking_id} was not approved. Your lesson keeps its original schedule."
                : "Your instructor withdrew the reschedule request for booking #{$this->changeRequest->booking_id}. Your lesson keeps its original schedule.")
            : "Your {$this->changeRequest->type->label()} request for booking #{$this->changeRequest->booking_id} was {$this->changeRequest->status->label()}.";

        return [
            'type' => 'booking_change_resolved',
            'change_request_id' => $this->changeRequest->id,
            'booking_id' => $this->changeRequest->booking_id,
            'status' => $this->changeRequest->status->value,
            'message' => $message,
            'url' => $this->bookingUrl($notifiable, $this->changeRequest->booking_id)
                ?? $this->dashboardUrl($notifiable),
        ];
    }
}
