<?php

namespace DTApi\Helpers;

class TeHelperTest extends TestCase
{
    public function test_less_than_or_equal_to_90_hours()
    {
        $due = Carbon::parse('2023-07-01 00:00:00');
        $created_at = $due->addHours(90);

        $response = TeHelper::willExpireAt($due->format('Y-m-d H:i:s'), $created_at->format('Y-m-d H:i:s'));

        $this->assertTrue($response == $due->format('Y-m-d H:i:s'));
    }

    public function test_greater_than_90_hours()
    {
        $due = Carbon::parse('2023-07-01 00:00:00');
        $created_at = $due->addHours(100);

        $response = TeHelper::willExpireAt($due->format('Y-m-d H:i:s'), $created_at->format('Y-m-d H:i:s'));

        $this->assertTrue($response == $due->subHours(48)->format('Y-m-d H:i:s'));
    }
}