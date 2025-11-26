<?php

namespace App\Scheduler;

use App\Message\SyncOrdersMessage;
use App\Message\SyncStocksMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('prestaboost')]
final class PrestaBoostScheduleProvider implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            // Sync orders every 5 minutes (high frequency for real-time dashboard)
            ->add(
                RecurringMessage::every(
                    '5 minutes',
                    new SyncOrdersMessage(days: 1) // Only last 24h for performance
                )
            )
            // Sync stocks every 30 minutes (less frequent, stocks change less)
            ->add(
                RecurringMessage::every(
                    '30 minutes',
                    new SyncStocksMessage()
                )
            )
            // Optional: Full sync once per day at 3 AM
            ->add(
                RecurringMessage::cron(
                    '0 3 * * *', // Daily at 3 AM
                    new SyncOrdersMessage(days: 30) // Full 30 days sync
                )
            );
    }
}
