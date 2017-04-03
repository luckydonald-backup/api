<?php namespace Demostf\API\Providers;

use Demostf\API\Demo\Demo;
use Doctrine\DBAL\Connection;

class DemoListProvider extends BaseProvider {
	public function listUploads(string $steamid, int $page, array $where = []) {
		$user = $this->db->user()->where('steamid', $steamid);
		$where['uploader'] = $user->fetch()->id;
		return $this->listDemos($page, $where);
	}

	public function listProfile(int $page, array $where = []) {
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

	public function listDemos(int $page, array $where = []) {
		if (isset($where['players']) and is_array($where['players']) and count($where['players']) > 0) {
			return $this->listProfile($page, $where);
		}

		$offset = ($page - 1) * 50;

		$query = $this->getQueryBuilder();
		$query->select('d.*')
			->from('demos', 'd')
			->leftJoin('d', 'upload_blacklist', 'b', $query->expr()->eq('uploader_id', 'uploader'))
			->where($query->expr()->isNull('b.id'));
		if (isset($where['map'])) {
			$query->where($query->expr()->eq('map', $query->createNamedParameter($where['map'])));
		}
		if (isset($where['playerCount'])) {
			$query->where($query->expr()->in('playerCount', $query->createNamedParameter($where['playerCount'], Connection::PARAM_INT_ARRAY)));
		}
		$query->orderBy('d.id', 'DESC')
			->setMaxResults(50)
			->setFirstResult($offset);

		$demos = $query->execute()->fetchAll();
		return $this->formatList($demos);
	}

	protected function formatList(array $rows) {
		return array_map(function (array $row) {
			return Demo::fromRow($row);
		}, $rows);
	}
}