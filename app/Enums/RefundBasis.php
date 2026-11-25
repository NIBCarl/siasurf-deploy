<?php

namespace App\Enums;

enum RefundBasis: string
{
    case LessonCountdownFull = 'lesson_countdown_7_plus_days';
    case LessonCountdownHalf = 'lesson_countdown_3_to_6_days';
    case LessonCountdownNoRefund = 'lesson_countdown_under_3_days';
    case InstructorCancelled = 'instructor_cancelled';
    case WeatherSafety = 'weather_safety';
    case InstructorNoShow = 'instructor_no_show';
    case InstructorSuspended = 'instructor_suspended';
    case InstructorRescheduleDeclined = 'instructor_reschedule_declined';
    case StudentNoShow = 'student_no_show';
    case CashUncollected = 'cash_uncollected';
    case PendingUnpaid = 'pending_unpaid';
    case AdminOverride = 'admin_override';

    public function label(): string
    {
        return match ($this) {
            self::LessonCountdownFull => '7 or more days before the lesson (100%)',
            self::LessonCountdownHalf => '3 to 6 days before the lesson (50%)',
            self::LessonCountdownNoRefund => 'Under 3 days before the lesson (no refund)',
            self::InstructorCancelled => 'Cancelled by instructor (100% lesson price)',
            self::WeatherSafety => 'Cancelled due to unsafe weather or sea conditions',
            self::InstructorNoShow => 'Instructor did not arrive (100% lesson price)',
            self::InstructorSuspended => 'Instructor account suspended (100% lesson price)',
            self::InstructorRescheduleDeclined => 'Instructor rescheduled – student declined / cancelled',
            self::StudentNoShow => 'Student no-show (no refund)',
            self::CashUncollected => 'Cash booking cancelled (no amount collected)',
            self::PendingUnpaid => 'Unpaid request withdrawn (no charge)',
            self::AdminOverride => 'SISA Administrator refund decision',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::LessonCountdownFull => '100% of the lesson price is refunded when cancelled 7 or more days before the lesson, however far in advance it was booked (7% service fee retained).',
            self::LessonCountdownHalf => '50% of the lesson price is refunded when cancelled 3 to 6 days before the lesson; 50% goes to the instructor and the 7% service fee is retained.',
            self::LessonCountdownNoRefund => 'No refund applies less than 3 days before the lesson; the lesson price goes to the instructor and the 7% service fee is retained.',
            self::InstructorCancelled,
            self::WeatherSafety,
            self::InstructorNoShow,
            self::InstructorSuspended => '100% of the lesson price is refunded for instructor-side or safety cancellations (7% service fee retained).',
            self::InstructorRescheduleDeclined => 'Because your instructor moved the lesson date, you receive 100% of the lesson price back (7% service fee retained).',
            self::StudentNoShow => 'No refund is issued for a missed session without prior cancellation.',
            self::CashUncollected => 'No cash was collected, so no refund or service fee applies.',
            self::PendingUnpaid => 'No payment was completed, so withdrawing releases your slot with no charge.',
            self::AdminOverride => 'Refund issued by SISA Administration.',
        };
    }
}
