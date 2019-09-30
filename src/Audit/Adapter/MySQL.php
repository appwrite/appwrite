<?php

namespace Audit\Adapter;

use Audit\Adapter;
use PDO;

class MySQL extends Adapter
{
    /**
     * @var PDO
     */
    protected $pdo;

    /**
     * @param PDO $pdo
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Log.
     *
     * Add specific event log
     *
     * @param int    $userId
     * @param int    $userType
     * @param string $event
     * @param string $resource
     * @param string $userAgent
     * @param string $ip
     * @param string $location
     * @param array  $data
     *
     * @return bool
     *
     * @throws \Exception
     */
    public function log($userId, $userType, $event, $resource, $userAgent, $ip, $location, $data)
    {
        $st = $this->getPDO()->prepare('INSERT INTO `'.$this->getNamespace().'.audit.audit`
            SET userId = :userId, userType = :userType, event= :event, resource= :resource, userAgent = :userAgent, ip = :ip, location = :location, time = "'.date('Y-m-d H:i:s').'", data = :data
		');

        $data = mb_strcut(json_encode($data), 0, 64000, 'UTF-8'); // Limit data to MySQL 64kb limit

        $st->bindValue(':userId', $userId, PDO::PARAM_STR);
        $st->bindValue(':userType', $userType, PDO::PARAM_INT);
        $st->bindValue(':event', $event, PDO::PARAM_STR);
        $st->bindValue(':resource', $resource, PDO::PARAM_STR);
        $st->bindValue(':userAgent', $userAgent, PDO::PARAM_STR);
        $st->bindValue(':ip', $ip, PDO::PARAM_STR);
        $st->bindValue(':location', $location, PDO::PARAM_STR);
        $st->bindValue(':data', $data, PDO::PARAM_STR);

        $st->execute();

        return ('00000' == $st->errorCode()) ? true : false;
    }

    public function getLogsByUser($userId, $userType)
    {
        $st = $this->getPDO()->prepare('SELECT *
        FROM `'.$this->getNamespace().'.audit.audit`
            WHERE userId = :userId
                AND userType = :userType
            ORDER BY `time` DESC LIMIT 10
        ');

        $st->bindValue(':userId', $userId, PDO::PARAM_STR);
        $st->bindValue(':userType', $userType, PDO::PARAM_INT);

        $st->execute();

        return $st->fetchAll();
    }

    public function getLogsByUserAndActions($userId, $userType, array $actions)
    {
        $query = [];

        foreach ($actions as $k => $id) {
            $query[] = ':action_'.$k;
        }

        $query = implode(',', $query);

        $st = $this->getPDO()->prepare('SELECT *
        FROM `'.$this->getNamespace().'.audit.audit`
            WHERE `event` IN ('.$query.')
                AND userId = :userId
                AND userType = :userType
            ORDER BY `time` DESC LIMIT 10
        ');

        $st->bindValue(':userId', $userId, PDO::PARAM_STR);
        $st->bindValue(':userType', $userType, PDO::PARAM_INT);

        foreach ($actions as $k => $id) {
            $st->bindValue(':action_'.$k, $id);
        }

        $st->execute();

        return $st->fetchAll();
    }

    /**
     * @return PDO
     */
    protected function getPDO()
    {
        return $this->pdo;
    }
}
