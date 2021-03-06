<?php

declare(strict_types=1);

namespace Demostf\API\Providers;

use Doctrine\DBAL\Connection;
use RandomLib\Generator;
use SteamId;

class AuthProvider extends BaseProvider {
    /**
     * @var Generator
     */
    private $generator;

    public function __construct(Connection $db, Generator $generator) {
        parent::__construct($db);
        $this->generator = $generator;
    }

    public function generateToken(): string {
        return $this->generator->generateString(32, Generator::CHAR_ALNUM);
    }

    public function setUser(string $token, SteamId $steamid, string $key) {
        apcu_store($token, [
            'name' => $steamid->getNickname(),
            'steamid' => $steamid->getSteamId64(),
            'key' => $key,
        ]);
    }

    public function getUser(string $token) {
        $found = true;
        $result = apcu_fetch($token, $found);

        return $found ? $result : ['name' => null, 'steamid' => null, 'key' => null];
    }

    public function logout($token) {
        apcu_delete($token);
    }
}
