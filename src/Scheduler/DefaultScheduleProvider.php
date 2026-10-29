<?php

namespace App\Scheduler;

use App\Entity\ListingSource;
use App\Message\FetchAllListingsMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('default')]
class DefaultScheduleProvider implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
            RecurringMessage::every('1 day', new FetchAllListingsMessage(ListingSource::WILLHABEN)),
            RecurringMessage::every('1 day', new FetchAllListingsMessage(ListingSource::IMMOBILIENSCOUT24)),
        );
    }
}