<?php

namespace DTApi\Repository;

// TODO cover all branches
class UserRepositoryTest extends TestCase
{
    /** @var UserRepository $userRepository */
    protected $userRepository;

    public function setUp(UserRepository $userRepository) {
        $this->userRepository = $userRepository;
    }

    public function test_create()
    {
        $this->userRepository->createOrUpdate(null, [
            'name' => 'Test User',
            'role' => 'admin',
            'company_id' => 0,
            'department_id' => 0,
            'email' => 'test@gmail.com',
            'phone' => '123456789'
        ]);
    }

    public function test_update()
    {
        $this->userRepository->createOrUpdate(1, [
            'name' => 'Test User',
            'role' => 'admin',
            'company_id' => 0,
            'department_id' => 0,
            'email' => 'test@gmail.com',
            'phone' => '123456789'
        ]);
    }
}