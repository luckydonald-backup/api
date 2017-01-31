<?php namespace Providers;

use Demo\Header;
use Demo\IDemoStore;
use Demo\StoredDemo;
use Doctrine\DBAL\Connection;

class DemoProvider extends BaseProvider {
	const VERSION = 4;

	/**
	 * @var IDemoStore
	 */
	private $demoStore;

	public function __construct(Connection $connection, IDemoStore $demoStore) {
		parent::__construct($connection);
		$this->demoStore = $demoStore;
	}

	public function get($id) {
		$demo = $this->db->demo()->where('id', $id);

		// sql magic
		$sql = 'WITH demokills AS (SELECT attacker_id, assister_id, victim_id FROM kills WHERE demo_id = ?)
		SELECT players.id, user_id, players.name, team, class, users.steamid, users.avatar,
		(SELECT COUNT(*) FROM demokills WHERE attacker_id=players.user_id) AS kills,
		(SELECT COUNT(*) FROM demokills WHERE assister_id=players.user_id) AS assists,
		(SELECT COUNT(*) FROM demokills WHERE victim_id=players.user_id) AS deaths
		FROM players
		INNER JOIN demos ON demos.id = players.demo_id
		INNER JOIN users ON players.user_id = users.id
		WHERE demo_id = ?
		';

		$uploader = $demo->user()->via('uploader')->fetch();
		$demoData = $demo->fetch();
		$playerQuery = $this->query($sql, [$demoData['id'], $demoData['id']]);
		$players = $playerQuery->fetchAll(\PDO::FETCH_ASSOC);

		$formattedDemo = $this->formatDemo($demoData);
		$formattedDemo['players'] = $players;
		$formattedDemo['uploader'] = [
			'id' => $uploader['id'],
			'steamid' => $uploader['steamid'],
			'name' => $uploader['name']
		];
		return $formattedDemo;
	}

	public function listUploads($steamid, $page, $where = []) {
		$user = $this->db->user()->where('steamid', $steamid);
		$where['uploader'] = $user->fetch()->id;
		return $this->listDemos($page, $where);
	}

	public function listProfile($page, $where = []) {
		$users = $this->db->user()->where('steamid', $where['players']);
		unset($where['players']);
		$userIds = [];
		foreach ($users as $user) {
			$userIds[] = $user['id'];
		}
		$in = implode(', ', array_fill(0, count($userIds), '?'));

		$sql = 'SELECT demos.id FROM demos INNER JOIN players ON players.demo_id = demos.id
		WHERE players.user_id IN (' . $in . ') GROUP BY demos.id HAVING COUNT(user_id)  = ? ORDER BY demos.id DESC LIMIT 50 OFFSET ' . ((int)$page - 1) * 50;

		$params = $userIds;
		$params[] = count($userIds);

		$result = $this->query($sql, $params);
		$demoIds = $result->fetchAll(\PDO::FETCH_COLUMN);

		$demos = $this->db->demo()->where('id', $demoIds)
			->where($where)
			->orderBy('id', 'DESC');
		return $this->formatList($demos);
	}

	public function listDemos($page, $where = []) {
		if (isset($where['players']) and is_array($where['players']) and count($where['players']) > 0) {
			return $this->listProfile($page, $where);
		}

		$offset = ($page - 1) * 50;

		$query = $this->getQueryBuilder();
		$query->select('demos.*')
			->from('demos', 'd')
			->leftJoin('d', 'upload_blacklist', 'b', $query->expr()->eq('uploader_id', 'uploader'))
			->where($query->expr()->isNull('b.id'));
		if (isset($where['map'])) {
			$query->where($query->expr()->eq('map', $query->createNamedParameter($where['map'])));
		}
		if (isset($where['playerCount'])) {
			$query->where($query->expr()->in('playerCount', $query->createNamedParameter($where['playerCount'], Connection::PARAM_INT_ARRAY)));
		}
		$query->orderBy('demos.tf', 'DESC')
			->setMaxResults(50)
			->setFirstResult($offset);

		$demos = $query->execute()->fetchAll();
		return $this->formatList($demos);
	}

	public function listMaps() {
		$sql = 'SELECT DISTINCT(map), COUNT(map) AS count from demos GROUP BY map ORDER BY count DESC';
		$result = $this->query($sql);
		return $result->fetchAll(\PDO::FETCH_COLUMN);
	}

	public function getChat($demoId) {
		$chat = $this->db->chat()->where('demo_id', $demoId);
		$result = [];
		foreach ($chat as $message) {
			$result[] = [
				'message' => $message['text'],
				'user' => $message['from'],
				'time' => $message['time']
			];
		}
		return $result;
	}

	protected function formatList($demos) {
		$result = [];
		foreach ($demos as $demo) {
			$result[] = $this->formatDemo($demo);
		}
		return $result;
	}

	private function formatDemo($demoData) {
		return [
			'id' => $demoData['id'],
			'url' => $demoData['url'],
			'name' => $demoData['name'],
			'server' => $demoData['server'],
			'duration' => $demoData['duration'],
			'nick' => $demoData['nick'],
			'map' => $demoData['map'],
			'time' => strtotime($demoData['created_at']),
			'red' => $demoData['red'],
			'blue' => $demoData['blu'],
			'redScore' => $demoData['scoreRed'],
			'blueScore' => $demoData['scoreBlue'],
			'playerCount' => $demoData['playerCount'],
			'uploader' => $demoData['uploader']
		];
	}

	private function formatTeam($teamInfo) {
		if ($teamInfo === null) {
			return $teamInfo;
		}
		return [
			'id' => $teamInfo['id'],
			'profileId' => $teamInfo['profile_id'],
			'name' => $teamInfo['name'],
			'tag' => $teamInfo['tag'],
			'avatar' => $teamInfo['avatar'],
			'steam' => $teamInfo['steam'],
			'league' => $teamInfo['league'],
			'division' => $teamInfo['division']
		];
	}

	public function getStats() {
		$demoCount = $this->db->demo()->count();
		$playerCount = $this->db->user()->count();

		$sql = 'SELECT count(user_id) FROM players GROUP BY user_id';
		$result = $this->query($sql);

		return [
			'demos' => $demoCount,
			'players' => $playerCount,
			'uploaders' => $result->fetchColumn()
		];
	}

	public function demoIdByHash($hash) {
		$query = $this->getQueryBuilder();
		$query->select('hash')
			->from('demos')
			->where($query->expr()->eq('hash', $query->createNamedParameter($hash)));

		return $query->execute()->fetchColumn();
	}

	public function storeDemo($handle, $name) {
		$this->demoStore->store($handle, $name);
	}

	public function save($name, Header $header, StoredDemo $storedDemo, $red, $blu, $uploaderId, $hash) {
		$query = $this->getQueryBuilder();
		$query->insert('demos')
			->values([
				'name' => $name,
				'url' => $storedDemo->getUrl(),
				'map' => $header->getMap(),
				'red' => $red,
				'blu' => $blu,
				'uploader' => $uploaderId,
				'duration' => floor($header->getDuration()),
				'backend' => $storedDemo->getBackend(),
				'path' => $storedDemo->getPath(),
				'server' => $header->getServer(),
				'nick' => $header->getNick(),
				'hash' => $hash,
				'version' => 0

			]);

		$query->execute();
		return $this->connection->lastInsertId('demos');
	}

//	public function analyse()
}
